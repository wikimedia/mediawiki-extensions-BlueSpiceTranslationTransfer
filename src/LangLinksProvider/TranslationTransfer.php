<?php

namespace BlueSpice\TranslationTransfer\LangLinksProvider;

use BlueSpice\Discovery\ILangLinksProvider;
use BlueSpice\TranslationTransfer\Util\ContentTransfer\TargetRecognizer;
use BlueSpice\TranslationTransfer\Util\TranslationsDao;
use ContentTransfer\TargetManager;
use MediaWiki\Config\ConfigFactory;
use MediaWiki\Languages\LanguageNameUtils;
use MediaWiki\Title\Title;
use Wikimedia\Rdbms\ILoadBalancer;

class TranslationTransfer implements ILangLinksProvider {

	/**
	 * @var LanguageNameUtils
	 */
	private $languageNameUtils;

	/**
	 * @var ConfigFactory
	 */
	private $configFactory;

	/**
	 * @var TargetRecognizer
	 */
	private $targetRecognizer;

	/**
	 * @var TargetManager
	 */
	private $targetManager;

	/**
	 * @var ILoadBalancer
	 */
	private $lb;

	/**
	 * @var TranslationsDao
	 */
	private $translationsDao;

	/**
	 * Language to ContentTransfer target map.
	 * Key is language code, value is target key.
	 *
	 * @var array
	 */
	private $langTransferTargetMap;

	/**
	 * @param LanguageNameUtils $languageNameUtils
	 * @param TargetRecognizer $targetRecognizer
	 * @param TargetManager $targetManager
	 * @param ILoadBalancer $lb
	 */
	public function __construct(
		LanguageNameUtils $languageNameUtils, TargetRecognizer $targetRecognizer,
		TargetManager $targetManager, ILoadBalancer $lb
	) {
		$this->languageNameUtils = $languageNameUtils;
		$this->targetRecognizer = $targetRecognizer;
		$this->targetManager = $targetManager;
		$this->lb = $lb;
		$this->translationsDao = new TranslationsDao( $this->lb );

		$this->langTransferTargetMap = $this->targetRecognizer->getLangToTargetKeyMap();
	}

	/**
	 * @inheritDoc
	 */
	public function getLangLinks( array $wikitextLangLinks, Title $title ): array {
		$currentLang = $this->targetRecognizer->recognizeCurrentTarget()['lang'];
		if ( !$currentLang ) {
			// Current wiki instance is not a part of the translations workflow
			return [];
		}

		$currentPrefixedTitleDBKey = $title->getPrefixedDBKey();

		$langVariants = $this->getTitleLanguageVariants( $currentPrefixedTitleDBKey, $currentLang );
		if ( empty( $langVariants ) ) {
			// Current page is not a part of the translations workflow, it has no language variants
			return [];
		}

		$links = [];
		foreach ( $langVariants as $langCode => $titleText ) {
			if ( $langCode === $currentLang ) {
				// Don't need to output link to the current language
				continue;
			}
			if ( !$this->targetRecognizer->isTarget( $langCode ) ) {
				// Not configured as a target
				continue;
			}

			$langName = $this->languageNameUtils->getLanguageName( $langCode );

			$links[] = [
				'href' => $this->targetRecognizer->composeTargetTitleLink( $langCode, $titleText ),
				'text' => $langName,
				'title' => $titleText . ' – ' . $langName,
				'class' => 'interlanguage-link interwiki-' . $langCode,
				'link-class' => 'interlanguage-link-target',
				'lang' => $langCode,
				'hreflang' => $langCode
			];
		}

		return $links;
	}

	/**
	 * Gets all language variants of specified title (except drafts).
	 *
	 * @param string $prefixedTitle
	 * @param string $lang
	 * @return array Key is language code, value is title of corresponding language variant
	 */
	private function getTitleLanguageVariants( string $prefixedTitle, string $lang ): array {
		if ( $this->translationsDao->isSource( $lang, $prefixedTitle ) ) {
			$sourcePrefixedDbKey = $prefixedTitle;
			$sourceLang = $lang;
		} elseif ( $this->translationsDao->isTarget( $lang, $prefixedTitle ) ) {
			$source = $this->translationsDao->getSourceFromTarget( $prefixedTitle, $lang );

			$sourcePrefixedDbKey = $source['key'];
			$sourceLang = strtolower( $source['lang'] );
		} else {
			return [];
		}

		$langVariants = [ $sourceLang => $sourcePrefixedDbKey ];

		$sourceTranslations = $this->translationsDao->getSourceTranslations( $sourcePrefixedDbKey, $sourceLang );
		foreach ( $sourceTranslations as $targetLang => $translation ) {
			if (
				!isset( $langVariants[$targetLang] )
				// We do not need draft titles like "Draft:..." in language switch
				&& !$this->isDraftTitle( $targetLang, $translation['target_prefixed_key'] )
			) {
				$langVariants[$targetLang] = $translation['target_prefixed_key'];
			}
		}

		return $langVariants;
	}

	/**
	 * Checks if specified title is draft one.
	 *
	 * @param string $langCode
	 * @param string $prefixedTitleKey
	 * @return bool <tt>true</tt> if specified title is draft one, <tt>false</tt> otherwise
	 */
	private function isDraftTitle( string $langCode, string $prefixedTitleKey ): bool {
		$draftNsText = $this->targetRecognizer->getDraftNamespace( $langCode );
		if ( $draftNsText === null ) {
			return false;
		}

		// Use "mb_strpos" here because draft namespace text could contain not only UTF-8 symbols
		if ( mb_strpos( $prefixedTitleKey, $draftNsText . ':' ) === 0 ) {
			return true;
		}

		return false;
	}

}
