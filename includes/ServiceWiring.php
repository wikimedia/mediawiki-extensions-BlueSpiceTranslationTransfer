<?php

use BlueSpice\TranslationTransfer\DeepL;
use BlueSpice\TranslationTransfer\Dictionary\TitleDictionary;
use BlueSpice\TranslationTransfer\Logger\TranslationsSpecialLogLogger;
use BlueSpice\TranslationTransfer\TranslationWikitextConverter;
use BlueSpice\TranslationTransfer\Translator;
use BlueSpice\TranslationTransfer\Util\ContentTransfer\TargetRecognizer;
use BlueSpice\TranslationTransfer\Util\TranslationPusher;
use BlueSpice\TranslationTransfer\Util\TranslationsDao;
use BlueSpice\TranslationTransfer\Util\TranslationUpdater;
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

		$translator = new Translator( $deepL, $wfConverter, $wikiPageFactory, $translationsDao, $titleDictionary );
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
