<?php

namespace BlueSpice\TranslationTransfer\Pipeline;

use MediaWiki\Languages\LanguageFactory;
use MediaWiki\Title\TitleFactory;
use MWStake\MediaWiki\Component\DeepLTranslator\DeepLTranslator;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Post-processing step that translates magic words and image attributes to English.
 *
 * Magic words are always translated to English because English magic word names are
 * understood by all MediaWiki installations (they're registered as aliases). This ensures
 * the translated wikitext works correctly on any target wiki regardless of its content language.
 *
 * Processing order:
 * 1. {{#contentTranslate...}} — removed entirely (always, regardless of config)
 * 2. {{DISPLAYTITLE:value}} — value translated via DeepL (always, regardless of config)
 * 3. Double underscores (__TOC__, __NOTOC__, etc.) — replaced with English equivalents (gated)
 * 4. Variable magic words ({{PAGENAME}}, {{FULLPAGENAME}}, etc.) — replaced with English,
 *    skipping actual templates (checked via TitleFactory::exists()) (gated)
 * 5. Image attributes (thumb, center, right, etc.) in [[File:...]] links — replaced with English (gated)
 *
 * Steps 3–5 are gated by the `translateMagicWords` key in DeeplTranslateConversionConfig.
 *
 * @see WikitextTranslator Where this is called as step 6 (after TemplateTranslator)
 * @see TranslationWikitextConverter::translateMagicWords() Legacy implementation this is ported from
 */
class MagicWordTranslator implements LoggerAwareInterface {

	/** @var LanguageFactory */
	private $languageFactory;

	/** @var TitleFactory */
	private $titleFactory;

	/** @var DeepLTranslator */
	private $deepL;

	/** @var \MagicWordFactory */
	private $magicWordFactory;

	/** @var bool */
	private $enabled;

	/** @var LoggerInterface */
	private $logger;

	/** @var string */
	private $sourceLang;

	/**
	 * @param LanguageFactory $languageFactory
	 * @param TitleFactory $titleFactory
	 * @param DeepLTranslator $deepL
	 * @param \MagicWordFactory $magicWordFactory
	 * @param bool $enabled Whether magic word translation is enabled (translateMagicWords config)
	 */
	public function __construct(
		LanguageFactory $languageFactory,
		TitleFactory $titleFactory,
		DeepLTranslator $deepL,
		\MagicWordFactory $magicWordFactory,
		bool $enabled
	) {
		$this->languageFactory = $languageFactory;
		$this->titleFactory = $titleFactory;
		$this->deepL = $deepL;
		$this->magicWordFactory = $magicWordFactory;
		$this->enabled = $enabled;
		$this->logger = new NullLogger();
		$this->sourceLang = '';
	}

	/**
	 * @param LoggerInterface $logger
	 */
	public function setLogger( LoggerInterface $logger ): void {
		$this->logger = $logger;
	}

	/**
	 * Translate magic words in assembled wikitext.
	 *
	 * Always removes {{#contentTranslate...}} and always translates {{DISPLAYTITLE:...}}.
	 * Other magic word translations (double underscores, variable magic words, image attributes)
	 * are gated by the `enabled` flag.
	 *
	 * @param string $wikitext Assembled wikitext
	 * @param string $sourceLang Source language code
	 * @param string $targetLang Target language code
	 * @return string Wikitext with translated magic words
	 */
	public function translateMagicWords(
		string $wikitext, string $sourceLang, string $targetLang
	): string {
		$this->sourceLang = $sourceLang;

		// Always remove {{#contentTranslate...}} regardless of config
		$wikitext = $this->removeContentTranslate( $wikitext );

		// Always translate {{DISPLAYTITLE:value}} regardless of config
		$wikitext = $this->translateDisplayTitle( $wikitext, $targetLang );

		if ( !$this->enabled ) {
			return $wikitext;
		}

		$englishLang = $this->languageFactory->getLanguage( 'en' );
		$enMagic = $englishLang->getMagicWords();

		// 1. Double underscores (__TOC__, __NOTOC__, etc.)
		$wikitext = $this->translateDoubleUnderscores( $wikitext, $enMagic );

		// 2. Variable magic words inside {{...}} ({{PAGENAME}}, etc.)
		$wikitext = $this->translateVariableMagicWords( $wikitext, $enMagic );

		// 3. Image attributes in [[File:...]] links
		$wikitext = $this->translateImgAttributes( $wikitext, $enMagic );

		return $wikitext;
	}

	/**
	 * Remove {{#contentTranslate...}} parser function calls.
	 *
	 * @param string $wikitext
	 * @return string
	 */
	private function removeContentTranslate( string $wikitext ): string {
		return preg_replace( '/\{\{#contentTranslate.*?\}\}/s', '', $wikitext );
	}

	/**
	 * Translate double-underscore behavior switches to English.
	 *
	 * Examples: __TOC__ → __TOC__ (already English), __INHALTSVERZEICHNIS__ → __TOC__
	 *
	 * @param string $wikitext
	 * @param array $enMagic English magic word definitions
	 * @return string
	 */
	private function translateDoubleUnderscores( string $wikitext, array $enMagic ): string {
		$doubleUnderscores = $this->magicWordFactory->getDoubleUnderscoreArray();

		foreach ( $doubleUnderscores->getNames() as $id ) {
			$wikitext = $this->tryReplaceMagic( $id, $enMagic, $wikitext );
		}

		return $wikitext;
	}

	/**
	 * Translate variable magic words inside {{...}} to English.
	 *
	 * Finds all {{...}} patterns, identifies which are magic words (not templates),
	 * and replaces them with their English equivalents. Skips:
	 * - Actual templates (pages that exist in Template: namespace)
	 * - {{int:...}} messages (handled separately to preserve message keys)
	 *
	 * @param string $wikitext
	 * @param array $enMagic English magic word definitions
	 * @return string
	 */
	private function translateVariableMagicWords( string $wikitext, array $enMagic ): string {
		$vars = $this->magicWordFactory->getVariableIDs();

		$matches = [];
		preg_match_all( '#\{\{(.*?)\}\}#s', $wikitext, $matches );

		if ( empty( $matches[0] ) ) {
			return $wikitext;
		}

		// Step 1: Process {{int:...}} separately to protect message keys
		$intIndexes = $this->processIntMagicWords( $wikitext, $matches, $enMagic );

		// Step 2: Identify actual templates to skip
		$templateIndexes = $this->identifyTemplates( $matches );

		// Step 3: Translate variable magic words
		foreach ( $matches[0] as $index => $match ) {
			if ( in_array( $index, $templateIndexes, true ) ) {
				continue;
			}
			if ( in_array( $index, $intIndexes, true ) ) {
				continue;
			}

			$newMatch = $match;
			foreach ( $vars as $varId ) {
				$newMatch = $this->tryReplaceMagic( $varId, $enMagic, $newMatch );
			}

			if ( $match !== $newMatch ) {
				$wikitext = str_replace( $match, $newMatch, $wikitext );
			}
		}

		// Step 4: Also try all English magic word keys (catches DISPLAYTITLE etc.)
		foreach ( $matches[0] as $index => $match ) {
			if ( in_array( $index, $templateIndexes, true ) ) {
				continue;
			}
			if ( in_array( $index, $intIndexes, true ) ) {
				continue;
			}

			$newMatch = $match;
			foreach ( array_keys( $enMagic ) as $mwId ) {
				$newMatch = $this->tryReplaceMagic( $mwId, $enMagic, $newMatch );
			}

			if ( $match !== $newMatch ) {
				$wikitext = str_replace( $match, $newMatch, $wikitext );
			}
		}

		return $wikitext;
	}

	/**
	 * Process {{int:...}} magic words: translate the magic word name to English
	 * but preserve the message key.
	 *
	 * @param string &$wikitext
	 * @param array $matches Regex matches from preg_match_all
	 * @param array $enMagic English magic word definitions
	 * @return int[] Indices of {{int:...}} matches (to skip in later processing)
	 */
	private function processIntMagicWords(
		string &$wikitext, array $matches, array $enMagic
	): array {
		$intIndexes = [];
		$mwId = 'int';

		$mw = $this->magicWordFactory->get( $mwId );

		foreach ( $matches[0] as $index => $match ) {
			if ( !$mw->match( $match ) ) {
				continue;
			}

			$newMatch = $this->tryReplaceMagic( $mwId, $enMagic, $match );

			if ( $newMatch !== $match ) {
				$wikitext = str_replace( $match, $newMatch, $wikitext );
			}

			$intIndexes[] = $index;
		}

		return $intIndexes;
	}

	/**
	 * Identify {{...}} patterns that are actual templates (not magic words).
	 *
	 * Checks if the text before the first | is a valid Template: page that exists
	 * on the wiki. These are skipped during magic word translation to avoid
	 * accidentally replacing parts of template names.
	 *
	 * @param array $matches Regex matches from preg_match_all
	 * @return int[] Indices of template matches
	 */
	private function identifyTemplates( array $matches ): array {
		$templateIndexes = [];

		foreach ( $matches[1] as $index => $content ) {
			$templateName = $content;

			// Strip parameters
			$pipePos = strpos( $templateName, '|' );
			if ( $pipePos !== false ) {
				$templateName = substr( $templateName, 0, $pipePos );
			}

			// Skip parser functions ({{#if:...}}, {{#switch:...}}, etc.)
			if ( strpos( trim( $templateName ), '#' ) === 0 ) {
				$templateIndexes[] = $index;
				continue;
			}

			$title = $this->titleFactory->newFromText( $templateName, NS_TEMPLATE );
			if ( $title && $title->exists() ) {
				$templateIndexes[] = $index;
			}
		}

		return $templateIndexes;
	}

	/**
	 * Translate image attributes in [[File:...]] links to English.
	 *
	 * Image attributes like "miniatur" (German for "thumb"), "zentriert" (German for "center"),
	 * etc. are replaced with their English equivalents. Only processes [[File:...]] links
	 * that contain a | separator. The file name itself is never modified.
	 *
	 * @param string $wikitext
	 * @param array $enMagic English magic word definitions
	 * @return string
	 */
	private function translateImgAttributes( string $wikitext, array $enMagic ): string {
		$matches = [];
		preg_match_all( '/\[\[.*?\]\]/s', $wikitext, $matches );

		foreach ( $matches[0] as $link ) {
			if ( strpos( $link, '|' ) === false ) {
				continue;
			}

			$bits = explode( '|', $link );
			$target = array_shift( $bits );

			if ( empty( $bits ) ) {
				continue;
			}

			// Remove closing ]] from last element for processing
			$lastIdx = count( $bits ) - 1;
			$bits[$lastIdx] = preg_replace( '/\]\]$/', '', $bits[$lastIdx] );

			$title = $this->titleFactory->newFromText(
				preg_replace( '/^\[\[/', '', $target )
			);
			if ( !$title || $title->getNamespace() !== NS_FILE ) {
				continue;
			}

			$changed = false;
			foreach ( $bits as &$option ) {
				$originalOption = $option;
				foreach ( array_keys( $enMagic ) as $mwId ) {
					if ( strpos( $mwId, 'img_' ) !== 0 ) {
						continue;
					}
					$option = $this->tryReplaceMagic( $mwId, $enMagic, $option );
				}
				if ( $option !== $originalOption ) {
					$changed = true;
				}
			}
			unset( $option );

			if ( $changed ) {
				$newLink = $target . '|' . implode( '|', $bits ) . ']]';
				$wikitext = str_replace( $link, $newLink, $wikitext );
			}
		}

		return $wikitext;
	}

	/**
	 * Translate the value inside {{DISPLAYTITLE:...}} via DeepL.
	 *
	 * Uses MagicWordFactory to match DISPLAYTITLE in any language alias
	 * (e.g., SEITENTITEL in German), so this works regardless of whether
	 * translateVariableMagicWords() has already normalized the magic word name.
	 * The output always uses the English form {{DISPLAYTITLE:translated_value}}.
	 *
	 * @param string $wikitext
	 * @param string $targetLang
	 * @return string
	 */
	private function translateDisplayTitle( string $wikitext, string $targetLang ): string {
		// Build regex matching any alias of the 'displaytitle' magic word
		$mw = $this->magicWordFactory->get( 'displaytitle' );
		$synonyms = $mw->getSynonyms();
		$synonymPattern = implode( '|', array_map( 'preg_quote', $synonyms ) );

		$matches = [];
		if ( !preg_match( '#\{\{(' . $synonymPattern . '):(.*?)\}\}#si', $wikitext, $matches ) ) {
			return $wikitext;
		}

		$originalValue = $matches[2];
		if ( trim( $originalValue ) === '' ) {
			return $wikitext;
		}

		$status = $this->deepL->translateText( $originalValue, $this->sourceLang, $targetLang );
		if ( !$status->isOK() ) {
			$this->logger->warning(
				'MagicWordTranslator: DeepL translation failed for DISPLAYTITLE value',
				[ 'value' => $originalValue ]
			);
			return $wikitext;
		}

		$translated = $status->getValue();

		return str_replace(
			$matches[0],
			"{{DISPLAYTITLE:{$translated}}}",
			$wikitext
		);
	}

	/**
	 * Try to replace a magic word in text with its English equivalent.
	 *
	 * Uses MagicWordFactory to match the current (possibly non-English) magic word,
	 * then replaces it with the canonical English form from the English language's
	 * magic word definitions.
	 *
	 * @param string $id Magic word ID (e.g., 'toc', 'pagename', 'img_thumbnail')
	 * @param array $enMagic English magic word definitions from Language::getMagicWords()
	 * @param string $text Text to search and replace in
	 * @return string Modified text (or original if no match)
	 */
	private function tryReplaceMagic( string $id, array $enMagic, string $text ): string {
		$mw = $this->magicWordFactory->get( $id );

		if ( !$mw->match( $text ) ) {
			return $text;
		}

		if ( !isset( $enMagic[$id] ) ) {
			return $text;
		}

		$regex = $mw->getRegex();
		$matches = [];
		if ( !preg_match_all( $regex, $text, $matches ) || empty( $matches[0] ) ) {
			return $text;
		}

		$toReplace = $matches[0][0];
		// English magic word definition: [0] = case-sensitive flag, [1] = canonical name
		$replacement = $enMagic[$id][1];

		if ( $toReplace === $replacement ) {
			return $text;
		}

		return preg_replace( '/' . preg_quote( $toReplace, '/' ) . '/', $replacement, $text );
	}
}
