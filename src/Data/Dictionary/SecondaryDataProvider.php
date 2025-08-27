<?php

namespace BlueSpice\TranslationTransfer\Data\Dictionary;

use BlueSpice\TranslationTransfer\Util\ContentTransfer\TargetRecognizer;
use Exception;
use MediaWiki\Config\ConfigFactory;
use MediaWiki\Config\HashConfig;
use MediaWiki\Language\Language;
use MediaWiki\Languages\LanguageFactory;
use MediaWiki\Title\TitleFactory;
use MWStake\MediaWiki\Component\DataStore\ISecondaryDataProvider;

class SecondaryDataProvider implements ISecondaryDataProvider {

	/**
	 * @var TitleFactory
	 */
	private $titleFactory;

	/**
	 * @var HashConfig
	 */
	private $conversionConfig;

	/**
	 * @var array
	 */
	private $langToWikiMap;

	/**
	 * @var Language
	 */
	private $contentLanguage;

	/**
	 * @var Language
	 */
	private $selectedLanguage;

	/**
	 * @var TargetRecognizer
	 */
	private $targetRecognizer;

	/**
	 * @param string $selectedLanguage
	 * @param LanguageFactory $languageFactory
	 * @param Language $contentLanguage
	 * @param ConfigFactory $configFactory
	 * @param TitleFactory $titleFactory
	 * @param TargetRecognizer $targetRecognizer
	 * @throws Exception
	 */
	public function __construct(
		string $selectedLanguage,
		LanguageFactory $languageFactory,
		Language $contentLanguage,
		ConfigFactory $configFactory,
		TitleFactory $titleFactory,
		TargetRecognizer $targetRecognizer
	) {
		$this->contentLanguage = $contentLanguage;
		$this->selectedLanguage = $languageFactory->getLanguage( $selectedLanguage );

		$config = $configFactory->makeConfig( 'bsg' );

		$this->conversionConfig = new HashConfig( $config->get( 'DeeplTranslateConversionConfig' ) );

		$this->targetRecognizer = $targetRecognizer;
		$this->titleFactory = $titleFactory;
	}

	/**
	 * @inheritDoc
	 */
	public function extend( $dataSets ) {
		// We need to take into account "conversion config" - $bsgDeeplTranslateConversionConfig
		// when we will append prefix text to title
		$map = $this->conversionConfig->get( 'namespaceMap' );

		foreach ( $dataSets as $dataSet ) {
			$contentLangCode = $this->contentLanguage->getCode();
			$selectedLangCode = $this->selectedLanguage->getCode();

			$sourceTitleText = $dataSet->get( DictionaryEntryRecord::SOURCE_PREFIXED_TEXT );
			$translationTitleText = $dataSet->get( DictionaryEntryRecord::TRANSLATION_PREFIXED_TEXT );

			$nsId = $dataSet->get( DictionaryEntryRecord::NS_ID );

			$sourceTitle = $this->titleFactory->newFromText( $sourceTitleText, $nsId );
			$translationTitle = $this->titleFactory->newFromText( $translationTitleText, $nsId );

			$sourcePrefixedDbKey = $sourceTitle->getPrefixedDBkey();
			$translationPrefixedDbKey = $translationTitle->getPrefixedDBkey();

			if ( $nsId != 0 ) {
				// If not main namespace, prepend NS text to both source and translation
				$nsSourceText = $map[$nsId][$contentLangCode] ?? $this->contentLanguage->getNsText( $nsId );
				$nsTargetText = $map[$nsId][$selectedLangCode] ?? $this->selectedLanguage->getNsText( $nsId );

				$dataSet->set(
					DictionaryEntryRecord::SOURCE_PREFIXED_TEXT,
					$nsSourceText . ':' . $sourceTitleText
				);
				$dataSet->set(
					DictionaryEntryRecord::TRANSLATION_PREFIXED_TEXT,
					$nsTargetText . ':' . $translationTitleText
				);

				$translationDbKey = $translationTitle->getDBkey();

				// Also namespace in the link should also be translated to target language
				$translationPrefixedDbKey = $nsTargetText . ':' . $translationDbKey;

				// Prefixed source can be got in regular way with Title object (it is done above),
				// because content language will be used for namespace text
			}

			$dataSet->set(
				DictionaryEntryRecord::SOURCE_PAGE_LINK,
				$this->targetRecognizer->composeTargetTitleLink( $contentLangCode, $sourcePrefixedDbKey )
			);
			$dataSet->set(
				DictionaryEntryRecord::TRANSLATION_PAGE_LINK,
				$this->targetRecognizer->composeTargetTitleLink( $selectedLangCode, $translationPrefixedDbKey )
			);

			// For further API calls, we need prefixed DB keys there
			$dataSet->set( DictionaryEntryRecord::SOURCE_PREFIXED_DB_KEY, $sourcePrefixedDbKey );
			$dataSet->set( DictionaryEntryRecord::TRANSLATION_PREFIXED_DB_KEY, $translationPrefixedDbKey );

			// We need translation prefixed DB key for composing link to the "What links here" special page
			$affectedPagesLink = $this->targetRecognizer->composeTargetTitleLink(
				$selectedLangCode, 'Special:WhatLinksHere/' . $translationPrefixedDbKey
			);

			$dataSet->set(
				DictionaryEntryRecord::TRANSLATION_AFFECTED_PAGES_LINK,
				$affectedPagesLink
			);
		}

		return $dataSets;
	}
}
