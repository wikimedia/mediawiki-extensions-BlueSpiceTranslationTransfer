<?php

namespace BlueSpice\TranslationTransfer\Pipeline;

use BlueSpice\TranslationTransfer\Util\GlossaryDao;
use MediaWiki\Config\Config;
use MediaWiki\Http\HttpRequestFactory;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Top-level orchestrator for the placeholder-based translation pipeline.
 *
 * Replaces the old EscapeWikitext + ignore_tags approach entirely.
 * DeepL only ever receives small inline-HTML fragments — no structural wikitext.
 *
 * Pipeline steps (executed in order):
 * 1. Segment — WikitextSegmenter splits wikitext into skeleton + segments
 * 2. Translate — SegmentTranslator sends segments to DeepL (with HTML tag_handling)
 * 3. Assemble — SkeletonAssembler replaces PUA markers with translated text
 * 4. Link translation — LinkTranslator translates [[...]] links (categories, titles, NS)
 * 5. Template translation — TemplateTranslator translates registered template args
 * 6. Magic word translation — MagicWordTranslator translates __TOC__, image attrs, DISPLAYTITLE, etc.
 *
 * Steps 4–6 are optional post-processing (only run when configured and services provided).
 * They operate on the fully-assembled wikitext via regex, not on the structured segment data.
 *
 * @see WikitextSegmenter Step 1
 * @see SegmentTranslator Step 2
 * @see SkeletonAssembler Step 3
 * @see LinkTranslator Step 4 (optional)
 * @see TemplateTranslator Step 5 (optional)
 * @see MagicWordTranslator Step 6 (optional)
 */
class WikitextTranslator implements LoggerAwareInterface {

	/** @var WikitextSegmenter */
	private $segmenter;

	/** @var SegmentTranslator */
	private $translator;

	/** @var SkeletonAssembler */
	private $assembler;

	/** @var GlossaryDao */
	private $glossaryDao;

	/** @var LinkTranslator|null */
	private $linkTranslator;

	/** @var TemplateTranslator|null */
	private $templateTranslator;

	/** @var MagicWordTranslator|null */
	private $magicWordTranslator;

	/** @var LoggerInterface */
	private $logger;

	/**
	 * @param Config $config
	 * @param HttpRequestFactory $requestFactory
	 * @param GlossaryDao $glossaryDao
	 * @param LinkTranslator|null $linkTranslator
	 * @param TemplateTranslator|null $templateTranslator
	 * @param MagicWordTranslator|null $magicWordTranslator
	 * @param string[] $fileNamespacePrefixes File namespace prefixes for InlineConverter
	 */
	public function __construct(
		Config $config,
		HttpRequestFactory $requestFactory,
		GlossaryDao $glossaryDao,
		?LinkTranslator $linkTranslator = null,
		?TemplateTranslator $templateTranslator = null,
		?MagicWordTranslator $magicWordTranslator = null,
		array $fileNamespacePrefixes = [ 'File', 'Image' ]
	) {
		$this->segmenter = new WikitextSegmenter();
		$this->assembler = new SkeletonAssembler();

		$inlineConverter = new InlineConverter( $fileNamespacePrefixes );
		$this->translator = new SegmentTranslator(
			$config, $requestFactory, $inlineConverter
		);

		$this->linkTranslator = $linkTranslator;
		$this->templateTranslator = $templateTranslator;
		$this->magicWordTranslator = $magicWordTranslator;
		$this->glossaryDao = $glossaryDao;
		$this->logger = new NullLogger();
	}

	/**
	 * @param LoggerInterface $logger
	 */
	public function setLogger( LoggerInterface $logger ): void {
		$this->logger = $logger;
		$this->segmenter->setLogger( $logger );
		$this->translator->setLogger( $logger );
		$this->assembler->setLogger( $logger );
		if ( $this->linkTranslator !== null ) {
			$this->linkTranslator->setLogger( $logger );
		}
		if ( $this->templateTranslator !== null ) {
			$this->templateTranslator->setLogger( $logger );
		}
		if ( $this->magicWordTranslator !== null ) {
			$this->magicWordTranslator->setLogger( $logger );
		}
	}

	/**
	 * Translate full wikitext from source language to target language.
	 *
	 * @param string $wikitext
	 * @param string $sourceLang
	 * @param string $targetLang
	 * @return string Translated wikitext
	 */
	public function translate( string $wikitext, string $sourceLang, string $targetLang ): string {
		if ( trim( $wikitext ) === '' ) {
			return $wikitext;
		}

		$pipelineStart = microtime( true );

		// Set glossary if available
		$glossaryId = $this->glossaryDao->getGlossaryId( strtolower( $targetLang ) );
		$this->translator->setGlossaryId( $glossaryId );

		$this->logger->debug( 'WikitextTranslator: starting translation {src} → {tgt}', [
			'src' => $sourceLang,
			'tgt' => $targetLang,
		] );

		// 1. Segment
		$segmentStart = microtime( true );
		[ $skeleton, $segments ] = $this->segmenter->segment( $wikitext );
		$segmentTime = microtime( true ) - $segmentStart;

		$this->logger->debug( 'WikitextTranslator: segmentation done in {time}s, {count} segments', [
			'time' => round( $segmentTime, 4 ),
			'count' => count( $segments ),
		] );

		if ( empty( $segments ) ) {
			$this->logger->debug( 'WikitextTranslator: no translatable segments found' );
			return $wikitext;
		}

		// 2. Translate segments
		$translateStart = microtime( true );
		$this->translator->translateBatch( $segments, $sourceLang, $targetLang );
		$translateTime = microtime( true ) - $translateStart;

		$this->logger->debug( 'WikitextTranslator: DeepL translation done in {time}s', [
			'time' => round( $translateTime, 4 ),
		] );

		// 3. Assemble
		$assembleStart = microtime( true );
		$result = $this->assembler->assemble( $skeleton, $segments );
		$assembleTime = microtime( true ) - $assembleStart;

		// 4. Post-assembly: translate internal links (categories, titles, namespaces, galleries)
		if ( $this->linkTranslator !== null ) {
			$result = $this->linkTranslator->translateLinks( $result, $sourceLang, $targetLang );
		}

		// 5. Post-assembly: translate registered template arguments
		if ( $this->templateTranslator !== null ) {
			$result = $this->templateTranslator->translateTemplates( $result, $sourceLang, $targetLang );
		}

		// 6. Post-assembly: translate magic words, image attributes, DISPLAYTITLE
		if ( $this->magicWordTranslator !== null ) {
			$result = $this->magicWordTranslator->translateMagicWords( $result, $sourceLang, $targetLang );
		}

		$totalTime = microtime( true ) - $pipelineStart;
		$this->logger->debug( 'WikitextTranslator: assembly done in {assembleTime}s, total pipeline: {totalTime}s', [
			'assembleTime' => round( $assembleTime, 4 ),
			'totalTime' => round( $totalTime, 4 ),
		] );

		return $result;
	}

	/**
	 * @return WikitextSegmenter
	 */
	public function getSegmenter(): WikitextSegmenter {
		return $this->segmenter;
	}

	/**
	 * @return SegmentTranslator
	 */
	public function getSegmentTranslator(): SegmentTranslator {
		return $this->translator;
	}

	/**
	 * @return SkeletonAssembler
	 */
	public function getAssembler(): SkeletonAssembler {
		return $this->assembler;
	}
}
