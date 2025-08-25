<?php

namespace BlueSpice\TranslationTransfer\Hook\SpecialPage_initList;

use MediaWiki\Config\Config;
use MediaWiki\Config\ConfigFactory;
use MediaWiki\SpecialPage\Hook\SpecialPage_initListHook;

/**
 * Hides "Special:TranslationDictionary" and "Special:TranslationGlossary" pages for instances which language is not leading one.
 *
 * If no leading language is configured - pages are not hidden.
 */
class HideDictionaryAndGlossary implements SpecialPage_initListHook {

	/**
	 * @var Config
	 */
	private $bsgConfig;

	/**
	 * @var Config
	 */
	private $mainConfig;

	/**
	 * @param ConfigFactory $configFactory
	 * @param Config $mainConfig
	 */
	public function __construct( ConfigFactory $configFactory, Config $mainConfig ) {
		$this->bsgConfig = $configFactory->makeConfig( 'bsg' );
		$this->mainConfig = $mainConfig;
	}

	/**
	 * @inheritDoc
	 */
	public function onSpecialPage_initList( &$list ) {
		if ( $this->shouldHide() ) {
			unset( $list['TranslationDictionary'] );
			unset( $list['TranslationGlossary'] );
		}

		return true;
	}

	/**
	 * Hide pages if:
	 * * TranslateTransfer configuration of translation targets is missing.
	 * * Leading language is configured, and current language is not leading one.
	 *
	 * @return bool
	 */
	private function shouldHide(): bool {
		// If there are no translation targets configured - hide special pages
		$langTargetDataMap = $this->bsgConfig->get( 'TranslateTransferTargets' );
		if ( empty( $langTargetDataMap ) ) {
			return true;
		}

		// Also hide them for root wiki instance, as soon as it never takes place in
		// translations workflow.
		if ( defined( 'FARMER_IS_ROOT_WIKI_CALL' ) && FARMER_IS_ROOT_WIKI_CALL ) {
			return true;
		}

		// Leading lang is already stored normalized
		$leadingLang = $this->bsgConfig->get( 'TranslateTransferLeadingLanguage' );

		if ( $leadingLang ) {
			$instanceLang = $this->mainConfig->get( 'LanguageCode' );
			$instanceLangNormalized = explode( '-', $instanceLang )[0];

			if ( $leadingLang === $instanceLangNormalized ) {
				return false;
			} else {
				return true;
			}
		} else {
			// If leading language is not configured - do not hide special pages
			return false;
		}
	}
}
