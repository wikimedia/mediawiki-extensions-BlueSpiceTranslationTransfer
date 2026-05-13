<?php

namespace BlueSpice\TranslationTransfer\Pipeline;

use Exception;
use MediaWiki\Config\Config;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Json\FormatJson;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Translates segments via DeepL with tag_handling=html.
 *
 * For each segment:
 * 1. Converts wikitext to HTML via InlineConverter (outbound)
 * 2. Stashes the converter's linkMap and opaqueMap (needed for step 4)
 * 3. Sends HTML to DeepL in batches (up to 50 texts / ~100KB per request)
 * 4. Converts translated HTML back to wikitext via InlineConverter (inbound)
 *
 * Batching strategy:
 * - Maximum 50 segments per HTTP request (DeepL API limit)
 * - Maximum ~100KB total payload per request (prevents timeouts)
 * - On failure: falls back to source text for all segments in the failed batch
 *
 * The DeepL request body is JSON-encoded (not form-encoded) because MediaWiki's
 * http_build_query() produces "text[0]=...&text[1]=..." which DeepL doesn't understand.
 * DeepL expects either repeated "text=..." fields or a JSON body with "text": [...].
 */
class SegmentTranslator implements LoggerAwareInterface {

	private const MAX_BATCH_SIZE = 50;
	/** Maximum batch size in bytes (~100KB) */
	private const MAX_BATCH_BYTES = 100000;

	/** @var Config */
	private $config;

	/** @var HttpRequestFactory */
	private $requestFactory;

	/** @var InlineConverter */
	private $inlineConverter;

	/** @var LoggerInterface */
	private $logger;

	/** @var string|null */
	private $glossaryId;

	/**
	 * @param Config $config
	 * @param HttpRequestFactory $requestFactory
	 * @param InlineConverter $inlineConverter
	 */
	public function __construct(
		Config $config,
		HttpRequestFactory $requestFactory,
		InlineConverter $inlineConverter
	) {
		$this->config = $config;
		$this->requestFactory = $requestFactory;
		$this->inlineConverter = $inlineConverter;
		$this->logger = new NullLogger();
		$this->glossaryId = null;
	}

	/**
	 * @param LoggerInterface $logger
	 */
	public function setLogger( LoggerInterface $logger ): void {
		$this->logger = $logger;
	}

	/**
	 * @param string|null $glossaryId
	 */
	public function setGlossaryId( ?string $glossaryId ): void {
		$this->glossaryId = $glossaryId;
	}

	/**
	 * Translate a batch of segments via DeepL.
	 *
	 * @param Segment[] $segments
	 * @param string $sourceLang
	 * @param string $targetLang
	 */
	public function translateBatch( array $segments, string $sourceLang, string $targetLang ): void {
		// Filter to only translatable segments
		$translatable = [];
		foreach ( $segments as $segment ) {
			if ( !$segment->isTranslatable() ) {
				$segment->setTranslatedText( $segment->getSourceText() );
				continue;
			}
			$translatable[] = $segment;
		}

		if ( empty( $translatable ) ) {
			return;
		}

		// Convert each segment's wikitext to HTML and stash converter state
		$htmlTexts = [];

		foreach ( $translatable as $segment ) {
			$this->inlineConverter->reset();
			$html = $this->inlineConverter->wikitextToHtml( $segment->getSourceText() );

			// Skip segments that became empty after opaque extraction
			if ( trim( strip_tags( $html ) ) === '' ) {
				$segment->setTranslatedText( $segment->getSourceText() );
				continue;
			}

			$htmlTexts[] = [
				'segment' => $segment,
				'html' => $html,
				'linkMap' => $this->inlineConverter->getLinkMap(),
				'opaqueMap' => $this->inlineConverter->getOpaqueMap(),
			];
		}

		if ( empty( $htmlTexts ) ) {
			return;
		}

		// Split into batches
		$batches = $this->buildBatches( $htmlTexts );

		foreach ( $batches as $batch ) {
			$this->executeBatch( $batch, $sourceLang, $targetLang );
		}
	}

	/**
	 * Split prepared segments into batches respecting size limits.
	 *
	 * @param array $htmlTexts
	 * @return array[]
	 */
	private function buildBatches( array $htmlTexts ): array {
		$batches = [];
		$currentBatch = [];
		$currentSize = 0;

		foreach ( $htmlTexts as $item ) {
			$itemSize = strlen( $item['html'] );

			if (
				count( $currentBatch ) >= self::MAX_BATCH_SIZE ||
				( $currentSize + $itemSize > self::MAX_BATCH_BYTES && !empty( $currentBatch ) )
			) {
				$batches[] = $currentBatch;
				$currentBatch = [];
				$currentSize = 0;
			}

			$currentBatch[] = $item;
			$currentSize += $itemSize;
		}

		if ( !empty( $currentBatch ) ) {
			$batches[] = $currentBatch;
		}

		return $batches;
	}

	/**
	 * Execute a single DeepL batch request.
	 *
	 * @param array $batch
	 * @param string $sourceLang
	 * @param string $targetLang
	 */
	private function executeBatch( array $batch, string $sourceLang, string $targetLang ): void {
		$texts = array_map( static function ( $item ) {
			return $item['html'];
		}, $batch );

		$this->logger->debug( 'SegmentTranslator: sending batch of {count} segments to DeepL', [
			'count' => count( $batch )
		] );

		$batchStart = microtime( true );

		try {
			$translations = $this->callDeepL( $texts, $sourceLang, $targetLang );
		} catch ( Exception $e ) {
			$this->logger->error( 'DeepL batch request failed: {error}', [
				'error' => $e->getMessage(),
				'batch_size' => count( $batch )
			] );

			// Fall back to source text for all segments in this batch
			foreach ( $batch as $item ) {
				$item['segment']->setTranslatedText( $item['segment']->getSourceText() );
			}
			return;
		}

		$batchTime = microtime( true ) - $batchStart;
		$this->logger->debug( 'SegmentTranslator: DeepL responded in {time}s for {count} segments', [
			'time' => round( $batchTime, 4 ),
			'count' => count( $batch ),
		] );

		// Map translations back to segments
		foreach ( $batch as $index => $item ) {
			if ( !isset( $translations[$index] ) ) {
				$this->logger->error( 'Missing translation for segment {id}', [
					'id' => $item['segment']->getId()
				] );
				$item['segment']->setTranslatedText( $item['segment']->getSourceText() );
				continue;
			}

			$translatedHtml = $translations[$index];

			// Convert HTML back to wikitext using the stashed converter state
			$linkMap = $item['linkMap'];
			$this->inlineConverter->reset();
			$this->restoreConverterState( $linkMap, $item['opaqueMap'] );

			$translatedWikitext = $this->inlineConverter->htmlToWikitext( $translatedHtml );

			$item['segment']->setTranslatedText( $translatedWikitext );
		}
	}

	/**
	 * Call DeepL translate API with multiple texts.
	 *
	 * @param string[] $texts
	 * @param string $sourceLang
	 * @param string $targetLang
	 * @return string[] Translated texts
	 * @throws Exception
	 */
	private function callDeepL( array $texts, string $sourceLang, string $targetLang ): array {
		$url = rtrim( $this->config->get( 'DeeplTranslateServiceUrl' ), '/' ) . '/translate';

		$postData = [
			'source_lang' => strtoupper( $sourceLang ),
			'target_lang' => strtoupper( $targetLang ),
			'tag_handling' => 'html',
			'text' => $texts,
		];

		if ( $this->glossaryId !== null ) {
			$postData['glossary_id'] = $this->glossaryId;
		}

		// DeepL expects either JSON body or form-encoded with repeated "text=" keys.
		// MediaWiki's HTTP client serializes array postData via http_build_query(),
		// which produces "text[0]=...&text[1]=..." — not understood by DeepL.
		// We must JSON-encode the body explicitly.
		$options = [
			'method' => 'POST',
			'timeout' => 120,
			'postData' => FormatJson::encode( $postData ),
			'sslVerifyHost' => 0,
			'sslVerifyCert' => false,
			'followRedirects' => true,
		];

		$req = $this->requestFactory->create( $url, $options );
		$req->setHeader( 'Content-Type', 'application/json' );
		$req->setHeader( 'Authorization', 'DeepL-Auth-Key ' . $this->config->get( 'DeeplTranslateServiceAuth' ) );

		$status = $req->execute();
		if ( !$status->isOK() ) {
			throw new Exception(
				'DeepL API error: HTTP ' . $req->getStatus() . ' — ' . $req->getContent()
			);
		}

		$response = FormatJson::decode( $req->getContent(), true );
		if ( !isset( $response['translations'] ) || !is_array( $response['translations'] ) ) {
			throw new Exception( 'Invalid DeepL response format' );
		}

		return array_map( static function ( $t ) {
			return $t['text'] ?? '';
		}, $response['translations'] );
	}

	/**
	 * Restore the InlineConverter's internal maps for the inbound conversion.
	 *
	 * @param array $linkMap
	 * @param array $opaqueMap
	 */
	private function restoreConverterState( array $linkMap, array $opaqueMap ): void {
		// We need to inject maps into the converter via a dedicated method
		$this->inlineConverter->restoreMaps( $linkMap, $opaqueMap );
	}
}
