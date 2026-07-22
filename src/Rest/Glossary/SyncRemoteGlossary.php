<?php

namespace BlueSpice\TranslationTransfer\Rest\Glossary;

use BlueSpice\TranslationTransfer\Util\GlossaryDao;
use Exception;
use GlobalVarConfig;
use MediaWiki\Config\Config;
use MediaWiki\Config\ConfigFactory;
use MediaWiki\Config\MultiConfig;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Json\FormatJson;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Message\Message;
use MediaWiki\Rest\Handler;
use Psr\Log\LoggerInterface;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\Rdbms\ILoadBalancer;

/**
 * Syncs local glossary entries with a DeepL v3 multilingual glossary.
 *
 * Algorithm:
 * * Get glossary entries for the target language from DB.
 * * If a glossary already exists on DeepL, replace its dictionary for
 *   the target language via PUT /v3/glossaries/{id}/dictionaries.
 * * If the glossary was deleted on DeepL (404), log a warning,
 *   clear the local ID and create a new glossary.
 * * If no glossary exists yet, create one via POST /v3/glossaries.
 */
class SyncRemoteGlossary extends Handler {

	/**
	 * @var GlossaryDao
	 */
	private $glossaryDao;

	/**
	 * @var Config
	 */
	private $config;

	/**
	 * @var HttpRequestFactory
	 */
	private $requestFactory;

	/**
	 * @var LoggerInterface
	 */
	private $logger;

	/**
	 * @param ILoadBalancer $lb
	 * @param ConfigFactory $configFactory
	 * @param HttpRequestFactory $requestFactory
	 */
	public function __construct(
		ILoadBalancer $lb,
		ConfigFactory $configFactory,
		HttpRequestFactory $requestFactory
	) {
		$this->glossaryDao = new GlossaryDao( $lb );
		$this->config = new MultiConfig( [
			new GlobalVarConfig( 'mwsg' ),
			$configFactory->makeConfig( 'bsg' )
		] );

		$this->requestFactory = $requestFactory;
		$this->logger = LoggerFactory::getInstance( 'BlueSpiceTranslationTransfer' );
	}

	/**
	 * @inheritDoc
	 */
	public function execute() {
		$targetLang = $this->getValidatedParams()['targetLang'];

		try {
			$this->syncGlossary( $targetLang );
		} catch ( Exception $e ) {
			$errorUserText = Message::newFromKey( 'bs-translation-transfer-glossary-sync-error' )->text();

			return $this->getResponseFactory()->createJson( [
				'success' => false,
				'error' => $errorUserText,
				'error_info' => $e->getMessage()
			] );
		}

		return $this->getResponseFactory()->createJson( [
			'success' => true
		] );
	}

	/**
	 * @param string $targetLang
	 * @return void
	 *
	 * @throws Exception
	 */
	private function syncGlossary( string $targetLang ): void {
		$entries = $this->glossaryDao->getGlossaryEntries( $targetLang );
		if ( empty( $entries ) ) {
			// No entries — nothing to sync.
			// DeepL would reject an empty dictionary anyway.
			return;
		}

		$glossaryId = $this->glossaryDao->getGlossaryId();

		if ( $glossaryId !== null ) {
			$success = $this->putDictionary( $glossaryId, $targetLang, $entries );
			if ( $success ) {
				return;
			}
			// PUT failed with 404 — glossary was deleted on DeepL side.
			// Clear local ID and create a new glossary below.
			$this->glossaryDao->clearGlossaryId();
		}

		// No glossary yet (or it was just cleared after 404) — create one
		$this->createGlossary( $targetLang, $entries );
	}

	/**
	 * Replace/create a dictionary within an existing v3 multilingual glossary.
	 *
	 * Uses MultiHttpClient because MW's MWHttpRequest only sends body for POST requests.
	 *
	 * @param string $glossaryId
	 * @param string $targetLang
	 * @param array $entries source => translation
	 * @return bool true if successful, false if glossary not found (404)
	 *
	 * @throws Exception on non-404 errors
	 */
	private function putDictionary( string $glossaryId, string $targetLang, array $entries ): bool {
		$sourceLang = $this->extractSourceLanguage();
		$entriesString = $this->buildCsvEntries( $entries );

		$jsonBody = FormatJson::encode( [
			'source_lang' => $sourceLang,
			'target_lang' => $targetLang,
			'entries' => $entriesString,
			'entries_format' => 'csv'
		] );

		$url = $this->makeGlossaryUrl() . '/' . $glossaryId . '/dictionaries';

		$multiClient = $this->requestFactory->createMultiClient( $this->makeOptions() );

		$req = [
			'method' => 'PUT',
			'url' => $url,
			'body' => $jsonBody,
			'headers' => [
				'Content-Type' => 'application/json',
				'Authorization' => 'DeepL-Auth-Key ' . $this->config->get( 'DeeplTranslateServiceAuth' )
			]
		];

		[ $rcode, $rdesc, $rhdrs, $rbody, $rerr ] = $multiClient->run( $req );

		if ( $rcode === 404 ) {
			$this->logger->warning(
				'DeepL glossary {glossaryId} not found on remote (404). '
				. 'It may have been deleted externally. Will create a new glossary.',
				[ 'glossaryId' => $glossaryId ]
			);
			return false;
		}

		if ( $rcode < 200 || $rcode >= 300 ) {
			throw new Exception(
				'Failed to update DeepL glossary dictionary. Response from DeepL: ' . $rbody
			);
		}

		return true;
	}

	/**
	 * Create a new v3 multilingual glossary with a single dictionary.
	 *
	 * @param string $targetLang
	 * @param array $entries source => translation
	 * @return void
	 *
	 * @throws Exception
	 */
	private function createGlossary( string $targetLang, array $entries ): void {
		$sourceLang = $this->extractSourceLanguage();
		$entriesString = $this->buildCsvEntries( $entries );

		$jsonBody = FormatJson::encode( [
			'name' => "BlueSpice Glossary",
			'dictionaries' => [
				[
					'source_lang' => $sourceLang,
					'target_lang' => $targetLang,
					'entries' => $entriesString,
					'entries_format' => 'csv'
				]
			]
		] );

		$url = $this->makeGlossaryUrl();

		$data = array_merge(
			$this->makeOptions(),
			[
				'method' => 'post',
				'postData' => $jsonBody
			]
		);

		$req = $this->requestFactory->create( $url, $data );
		$req->setHeader( 'Content-Type', 'application/json' );
		$this->setAuthHeader( $req );

		$status = $req->execute();
		if ( !$status->isOK() ) {
			$response = $req->getContent();
			throw new Exception( 'Failed to create DeepL glossary. Response from DeepL: ' . $response );
		}

		$responseRaw = $req->getContent();
		if ( $responseRaw ) {
			$response = FormatJson::decode( $responseRaw, true );

			if ( $response && isset( $response['glossary_id'] ) ) {
				$this->glossaryDao->persistGlossaryId( $response['glossary_id'] );
			}
		}
	}

	/**
	 * Build CSV-formatted glossary entries string.
	 *
	 * @param array $entries source => translation
	 * @return string
	 */
	private function buildCsvEntries( array $entries ): string {
		$lines = [];
		foreach ( $entries as $source => $translation ) {
			// Properly escape any double quotes inside of values, if there are any,
			// and wrap values themselves into double quotes.
			$source = '"' . str_replace( '"', '""', $source ) . '"';
			$translation = '"' . str_replace( '"', '""', $translation ) . '"';

			$lines[] = "$source,$translation";
		}
		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * @param \MWHttpRequest $req
	 * @return void
	 */
	private function setAuthHeader( $req ): void {
		$req->setHeader(
			'Authorization',
			'DeepL-Auth-Key ' . $this->config->get( 'DeeplTranslateServiceAuth' )
		);
	}

	/**
	 * @inheritDoc
	 */
	public function getParamSettings() {
		return [
			'targetLang' => [
				static::PARAM_SOURCE => 'path',
				ParamValidator::PARAM_REQUIRED => true,
				ParamValidator::PARAM_TYPE => 'string'
			]
		];
	}

	/**
	 * @return array
	 */
	private function makeOptions() {
		return [
			'timeout' => 120,
			'sslVerifyHost' => 0,
			'followRedirects' => true,
			'sslVerifyCert' => false,
		];
	}

	/**
	 * Build the v3 glossaries base URL.
	 *
	 * @return string e.g. "https://api-free.deepl.com/v3/glossaries"
	 */
	private function makeGlossaryUrl(): string {
		$url = $this->config->get( 'DeeplTranslateServiceUrl' );
		$url = rtrim( $url, '/' );
		// B/C: strip trailing version path if present (e.g. /v2)
		$url = preg_replace( '#/v\d+$#', '', $url );

		return $url . '/v3/glossaries';
	}

	/**
	 * @return string
	 */
	private function extractSourceLanguage(): string {
		return explode( '-', $this->config->get( 'LanguageCode' ) )[0];
	}
}
