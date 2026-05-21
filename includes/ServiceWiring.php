<?php

use BlueSpice\TranslationTransfer\DeepL;
use BlueSpice\TranslationTransfer\Dictionary\TitleDictionary;
use BlueSpice\TranslationTransfer\Logger\TranslationsSpecialLogLogger;
use BlueSpice\TranslationTransfer\Pipeline\LinkTranslator;
use BlueSpice\TranslationTransfer\Pipeline\MagicWordTranslator;
use BlueSpice\TranslationTransfer\Pipeline\TemplateTranslator;
use BlueSpice\TranslationTransfer\Pipeline\WikitextTranslator;
use BlueSpice\TranslationTransfer\TranslationWikitextConverter;
use BlueSpice\TranslationTransfer\Translator;
use BlueSpice\TranslationTransfer\Util\ContentTransfer\TargetRecognizer;
use BlueSpice\TranslationTransfer\Util\GlossaryDao;
use BlueSpice\TranslationTransfer\Util\TranslationPusher;
use BlueSpice\TranslationTransfer\Util\TranslationsDao;
use BlueSpice\TranslationTransfer\Util\TranslationUpdater;
use MediaWiki\Config\HashConfig;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;

return [
	'DeepL' => static function ( MediaWikiServices $services ) {
		return new DeepL(
			$services->getConfigFactory()->makeConfig( 'bsg' ), $services->getHttpRequestFactory()
		);
	},
	'TranslationTransferTargetRecognizer' => static function ( MediaWikiServices $services ) {
		$configFactory = $services->getConfigFactory();
		$mainConfig = $services->getMainConfig();
		$targetManager = $services->getService( 'ContentTransferTargetManager' );

		return new TargetRecognizer( $configFactory, $mainConfig, $targetManager );
	},
	'TranslationsTransferSpecialLogLogger' => static function ( MediaWikiServices $services ) {
		return new TranslationsSpecialLogLogger();
	},
	'TranslationsTransferWikitextTranslator' => static function ( MediaWikiServices $services ) {
		$config = $services->getConfigFactory()->makeConfig( 'bsg' );
		$glossaryDao = new GlossaryDao( $services->getDBLoadBalancer() );
		$logger = LoggerFactory::getInstance( 'BlueSpiceTranslationTransfer' );

		$conversionConfig = new HashConfig( $config->get( 'DeeplTranslateConversionConfig' ) );
		$deepL = $services->getService( 'DeepL' );
		$titleDictionary = TitleDictionary::factory();

		$linkTranslator = new LinkTranslator(
			$conversionConfig,
			$deepL,
			$titleDictionary,
			$services->getTitleFactory(),
			$services->getLanguageFactory()
		);
		$linkTranslator->setLogger( $logger );

		$templateRegistry = $config->get( 'TranslateTransferTemplateArgs' );
		$templateTranslator = null;
		if ( !empty( $templateRegistry ) ) {
			$templateTranslator = new TemplateTranslator(
				$templateRegistry,
				$deepL,
				$titleDictionary
			);
			$templateTranslator->setLogger( $logger );
		}

		$translateMagicWords = $conversionConfig->has( 'translateMagicWords' )
			? $conversionConfig->get( 'translateMagicWords' )
			: true;
		$magicWordTranslator = new MagicWordTranslator(
			$services->getLanguageFactory(),
			$services->getTitleFactory(),
			$deepL,
			$services->getMagicWordFactory(),
			$translateMagicWords
		);
		$magicWordTranslator->setLogger( $logger );

		// Gather all file namespace prefixes (canonical + localized aliases)
		$namespaceInfo = $services->getNamespaceInfo();
		$contentLang = $services->getContentLanguage();
		$fileNamespacePrefixes = [ $namespaceInfo->getCanonicalName( NS_FILE ) ];
		foreach ( $contentLang->getNamespaceAliases() as $alias => $nsId ) {
			if ( $nsId === NS_FILE ) {
				$fileNamespacePrefixes[] = $alias;
			}
		}

		$localizedFileName = $contentLang->getNsText( NS_FILE );
		if ( $localizedFileName && !in_array( $localizedFileName, $fileNamespacePrefixes ) ) {
			$fileNamespacePrefixes[] = $localizedFileName;
		}

		// Always include "Image" as legacy alias
		if ( !in_array( 'Image', $fileNamespacePrefixes ) ) {
			$fileNamespacePrefixes[] = 'Image';
		}

		$wikitextTranslator = new WikitextTranslator(
			$config,
			$services->getHttpRequestFactory(),
			$glossaryDao,
			$linkTranslator,
			$templateTranslator,
			$magicWordTranslator,
			$fileNamespacePrefixes
		);
		$wikitextTranslator->setLogger( $logger );

		return $wikitextTranslator;
	},
	'TranslationsTransferTranslator' => static function ( MediaWikiServices $services ) {
		$deepL = $services->getService( 'DeepL' );
		$wfConverter = TranslationWikitextConverter::factory();
		$wfConverter->setLogger(
			LoggerFactory::getInstance( 'BlueSpiceTranslationTransfer' )
		);
		$wikiPageFactory = $services->getWikiPageFactory();

		$translationsDao = new TranslationsDao(
			$services->getDBLoadBalancer()
		);

		$titleDictionary = TitleDictionary::factory();

		// Use new pipeline when $bsgTranslateTransferUsePipeline is true
		$wikitextTranslator = null;
		$bsgConfig = $services->getConfigFactory()->makeConfig( 'bsg' );
		if ( $bsgConfig->get( 'TranslateTransferUsePipeline' ) ) {
			$wikitextTranslator = $services->getService( 'TranslationsTransferWikitextTranslator' );
		}

		$translator = new Translator(
			$deepL, $wfConverter, $wikiPageFactory, $translationsDao, $titleDictionary,
			$wikitextTranslator
		);
		$translator->setLogger(
			LoggerFactory::getInstance( 'BlueSpiceTranslationTransfer' )
		);

		return $translator;
	},
	'TranslationTransferTranslationPusher' => static function ( MediaWikiServices $services ) {
		$translationPusher = new TranslationPusher(
			$services->getConfigFactory()->makeConfig( 'bsg' ),
			$services->getService( 'TranslationTransferTargetRecognizer' ),
			$services->getHookContainer(),
			$services->getRevisionStore(),
			new TranslationsDao( $services->getDBLoadBalancer() ),
			$services->getService( 'ContentTransferAuthenticatedRequestHandlerFactory' ),
			$services->getService( 'ContentTransferPageContentProviderFactory' ),
			$services->getDBLoadBalancer(),
			$services->getTitleFactory(),
			$services->getWikiPageFactory(),
			TranslationWikitextConverter::factory(),
			$services->getService( 'TranslationsTransferTranslator' )
		);
		$translationPusher->setLogger(
			LoggerFactory::getInstance( 'BlueSpiceTranslationTransfer' )
		);

		return $translationPusher;
	},
	'TranslationTransferTranslationUpdater' => static function ( MediaWikiServices $services ) {
		$translationUpdater = new TranslationUpdater(
			$services->getService( 'DeepL' ),
			new TranslationsDao( $services->getDBLoadBalancer() ),
			$services->getService( 'ContentTransferTargetManager' ),
			$services->getService( 'TranslationTransferTargetRecognizer' ),
			$services->getTitleFactory(),
			$services->getService( 'ContentTransferAuthenticatedRequestHandlerFactory' ),
			TitleDictionary::factory(),
			$services->getJobQueueGroup()
		);
		$translationUpdater->setLogger(
			LoggerFactory::getInstance( 'BlueSpiceTranslationTransfer' )
		);

		return $translationUpdater;
	}
];
