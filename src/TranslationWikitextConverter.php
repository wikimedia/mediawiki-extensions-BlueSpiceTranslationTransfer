<?php

namespace BlueSpice\TranslationTransfer;

use BlueSpice\TranslationTransfer\Dictionary\TitleDictionary;
use Exception;
use MediaWiki\Config\ConfigException;
use MediaWiki\Config\HashConfig;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class TranslationWikitextConverter implements LoggerAwareInterface {
	/** @var HashConfig */
	private $config;

	/** @var DeepL */
	private $deepL;

	/** @var IDictionary */
	private $titleDictionary;

	/**
	 * @var LoggerInterface
	 */
	private $logger;

	public static function factory() {
		$services = MediaWikiServices::getInstance();
		$config = $services->getConfigFactory()->makeConfig( 'bsg' );

		return new static(
			new HashConfig(
				$config->get( 'DeeplTranslateConversionConfig' )
			),
			$services->getService( 'DeepL' ),
			TitleDictionary::factory()
		);
	}

	/**
	 * @param HashConfig $config
	 * @param DeepL $deepL
	 * @param IDictionary $titleDictionary
	 */
	public function __construct(
		HashConfig $config, DeepL $deepL,
		IDictionary $titleDictionary
	) {
		$this->config = $config;
		$this->deepL = $deepL;
		$this->titleDictionary = $titleDictionary;

		$this->logger = new NullLogger();
	}

	/**
	 * @param LoggerInterface $logger
	 */
	public function setLogger( LoggerInterface $logger ): void {
		$this->logger = $logger;
	}

	/**
	 * @param string $wikitext Text to process
	 * @param string $lang Language code
	 * @return string
	 * @throws ConfigException
	 */
	public function preTranslationProcessing( $wikitext, $lang ) {
		$this->logger->debug( "Pre-translation processing started. Target lang - '$lang'" );

		$this->translateCategories( $wikitext, $lang );

		// That's important to translate titles before namespaces
		// Otherwise namespaces will already be translated to target language and may be not recognized
		if ( $this->config->get( 'translatePageTitle' ) ) {
			$this->translateTitlesInLinks( $wikitext, $lang );
		}

		if ( $this->config->get( 'translateNamespaces' ) ) {
			$this->translateNamespacesInLinks( $wikitext, $lang );
		}

		$this->escapeTemplateParameters( $wikitext );
		if ( $this->config->get( 'translateMagicWords' ) ) {
			$this->translateMagicWords( $wikitext );
			$this->translateImgAttributes( $wikitext );
		}
		$this->removeContentTranslation( $wikitext );

		// We should also translate "display title" if it is specified in the page content
		$this->translateDisplayTitle( $wikitext, $lang );

		$this->escapeWikitext( $wikitext );

		return $wikitext;
	}

	/**
	 * @param array $translateData
	 * @return string
	 * @throws ConfigException
	 */
	public function postTranslationProcessing( $translateData ) {
		$this->logger->debug( "Post-translation processing started." );

		$title = $translateData['title'];
		$wikitext = $translateData['wikitext'];
		if (
			!$this->config->get( 'translatePageTitle' ) &&
			$this->config->get( 'addDisplayTitleToContent' )
		) {
			// Check if page already holds "display title"

			// At the step when this method is called - "display title" magic word should already be translated to EN
			// And escaped after that (so DeepL should not touch it)
			// So we just need to look for "{{DISPLAYTITLE:}}
			$matches = [];
			preg_match( '#{{DISPLAYTITLE:(.*?)}}#', $wikitext, $matches );

			// Append new "display title" only if page does not contain one yet
			// Otherwise existing one will anyway be translated as part of pre-processing, so nothing to do
			if ( empty( $matches ) ) {
				// ERM 22078 - Use only the last subpage for displaytitle
				$title = rtrim( $title, '/' );
				$titleBits = explode( '/', $title );
				$title = array_pop( $titleBits );
				$wikitext = "{{DISPLAYTITLE:$title}}\n\n" . $wikitext;
			}
		}

		$this->stripTranslationIgnoreTags( $wikitext );

		return $wikitext;
	}

	/**
	 * @param string &$wikitext
	 * @return void
	 */
	public function stripTranslationIgnoreTags( string &$wikitext ): void {
		$this->removeTag( 'deepl:ignore', $wikitext );
		$this->removeTag( 'translation:ignore', $wikitext );
	}

	/**
	 * @param Title $original
	 * @param string $translatedText Proposed by DeepL translation of page title. Text without NS
	 * @param string $lang Language code
	 * @param bool $addToDictionary When <tt>true</tt> and dictionary does not have translation for that title yet -
	 *        - add proposed by DeepL translation to the dictionary instantly. If <tt>false</tt> and/or
	 *        dictionary has translation for that title - dictionary won't be changed. By default, equals <tt>true</tt>
	 *        for backward compatibility.
	 * @return array Array with such structure:
	 * 		[
	 * 			<translatedPrefixedText>,
	 * 			<dictionaryUsed> (bool)
	 * 		]
	 * @throws ConfigException
	 * @throws Exception If there is a conflict: current page currently does not have any translation,
	 * 		so the new entry should be created in the dictionary.
	 * 		But meanwhile such translation already exists for other page.
	 */
	public function getTitleText( Title $original, $translatedText, $lang, bool $addToDictionary = true ) {
		$dictionaryUsed = false;

		$this->logger->debug(
			"Translating title text to '$lang'. Original - '{$original->getPrefixedText()}'. " .
			"Proposed by DeepL translation (without NS) - '$translatedText'" );

		if ( $this->config->get( 'translatePageTitle' ) ) {
			$this->logger->debug( 'TranslationWikitextConverter: start translating title text...' );

			$cachedTranslation = $this->titleDictionary->get( $original->getPrefixedText(), $lang );
			if ( $cachedTranslation === null ) {
				if ( $addToDictionary ) {
					$this->logger->debug( "Such translation was not found in the dictionary. Updating dictionary..." );

					$this->titleDictionary->insert( $original->getPrefixedText(), $lang, $translatedText );
				} else {
					$this->logger->debug( "Such translation was not found in the dictionary. Using proposed by DeepL..." );
				}
			} else {
				$this->logger->debug( "Translation found in the dictionary - '$cachedTranslation'" );

				$translatedText = $cachedTranslation;

				$dictionaryUsed = true;
			}

			if (
				!$this->config->get( 'translateNamespaces' ) ||
				$original->getNamespace() === NS_MAIN
			) {
				if ( $original->getNamespace() === NS_MAIN ) {
					return [ $translatedText, $dictionaryUsed ];
				} else {
					return [ $original->getNsText() . ':' . $translatedText, $dictionaryUsed ];
				}
			}

			$translatedPrefixedText = $this->getNsTextInternal( $original->getNamespace(), $lang ) . ':' . $translatedText;

			$this->logger->debug( "Translated with NS to: '$translatedPrefixedText'" );

			return [ $translatedPrefixedText, $dictionaryUsed ];
		}

		if (
			!$this->config->get( 'translateNamespaces' ) ||
			$original->getNamespace() === NS_MAIN
		) {
			return [ $original->getFullText(), $dictionaryUsed ];
		}

		$title = $original->getText();
		if ( $original->getNamespace() === NS_CATEGORY ) {
			$title = $this->getCategoryTranslation( $original, $lang );
		}
		return [ $this->getNsTextInternal( $original->getNamespace(), $lang ) . ':' . $title, $dictionaryUsed ];
	}

	/**
	 * @param int $nsId
	 * @param string $lang
	 * @return string
	 * @throws ConfigException
	 * @throws Exception
	 */
	public function getNsText( int $nsId, string $lang ): string {
		if (
			!$this->config->get( 'translateNamespaces' ) ||
			$nsId === NS_MAIN
		) {
			if ( $nsId === NS_MAIN ) {
				return '';
			} else {
				$dummyTitle = Title::makeTitle( $nsId, 'Dummy' );

				return $dummyTitle->getNsText();
			}
		}

		return $this->getNsTextInternal( $nsId, $lang );
	}

	/**
	 * @param string $tag
	 * @param string &$text
	 */
	private function removeTag( $tag, &$text ) {
		$text = str_replace( "<$tag>", '', $text );
		$text = str_replace( "</$tag>", '', $text );
	}

	/**
	 * @param string &$wikitext
	 * @param string $lang
	 */
	private function translateTitlesInLinks( &$wikitext, $lang ) {
		$matches = [];
		preg_match_all( '/\[\[(.*?)\]\]/s', $wikitext, $matches );
		foreach ( $matches[1] as $index => $match ) {
			if ( strpos( $match, "\n" ) ) {
				$match = str_replace( "\n", " ", $match );
			}

			$text = $this->translateTitleInLink( $match, $lang );
			if ( !$text ) {
				continue;
			}

			$wikitext = str_replace(
				$matches[0][$index],
				"[[$text]]",
				$wikitext
			);
		}

		// We do not need to handle gallery here, as soon as gallery contains only files
		// And we do not translate files titles for technical reasons
	}

	/**
	 * @param string $link
	 * @param string $lang
	 * @return string|null
	 */
	private function translateTitleInLink( string $link, string $lang ): ?string {
		$mainBits = explode( '|', $link );
		$target = array_shift( $mainBits );
		$label = '';

		if ( !empty( $mainBits ) ) {
			$label = implode( '|', $mainBits );
		}

		$mainNs = false;
		if ( strpos( $target, ':' ) === false ) {
			$mainNs = true;
		}

		$colonStart = false;
		if ( strpos( $target, ':' ) === 0 ) {
			$colonStart = true;
			$target = substr( $target, 1 );
		}

		if ( strpos( $target, '::' ) !== false ) {
			$this->logger->warning( "Link - '$link'. Semantic property?" );

			// Do not need to translate semantic properties
			return null;
		}

		// We should probably also handle "MissingDictionary/..." links for categories here
		if ( strpos( $target, 'MissingDictionary/' ) === 0 ) {
			// Most likely that's previously processed category which has "missing dictionary" problem
			// We should not translate title here
			return null;
		}

		$title = Title::newFromText( $target );
		if ( !$title ) {
			$this->logger->warning( "Link - '$link'. Title '$target' is not correct title" );
			return null;
		}

		// There is a ":" symbol in the link
		if ( !$mainNs ) {
			// We do not handle files translation for technical reasons
			if ( $title->getNamespace() === NS_FILE || $title->getNamespace() === NS_MEDIA ) {
				return null;
			}

			if ( $title->getNamespace() === NS_CATEGORY ) {
				// Categories are already translated beforewards
				// As soon as they are always translated, independently of "translatePageTitle" config
				return null;
			}

			// Interwiki or language link, no need to translate
			// Example: [[de:Hauptseite]]
			if ( $title->isExternal() ) {
				return null;
			}

			$bits = explode( ':', $target );

			$ns = array_shift( $bits );
		} else {
			$ns = null;
		}

		$fragment = '';
		if ( $title->getFragment() ) {
			$fragment = $title->getFragment();

			// TODO: Translate fragment assuming headings
			$status = $this->deepL->translateText(
				$fragment, $this->deepL->extractSourceLanguage(),
				$lang
			);

			if ( $status->isOK() ) {
				$translatedFragment = $status->getValue();
			} else {
				$this->logger->warning( "Link - '$link'. Fail when translating fragment with DeepL: '$fragment'" );
				$this->logger->debug( "Errors: " . $status->getWikiText() );
				return null;
			}

			if ( $title->getPrefixedText() === '' ) {
				// If that's anchor link to the current page - there is no title to translate here
				$translated = $translatedFragment;
				if ( $label ) {
					$translated = "$translatedFragment|$label";
				}

				return $translated;
			}
		}

		$cachedTranslation = $this->titleDictionary->get( $title->getPrefixedText(), $lang );

		if ( $cachedTranslation === null ) {
			// Translate page title to the necessary language
			$status = $this->deepL->translateText(
				$title->getText(),
				$this->deepL->extractSourceLanguage(),
				$lang
			);

			if ( !$status->isOK() ) {
				return null;
			}

			$translated = $status->getValue();

			try {
				$this->titleDictionary->insert( $title->getPrefixedText(), $lang, $translated );
			} catch ( Exception $e ) {
				// If there is already another translation for that title - just mark it for user and do logging
				$this->logger->error( "Link - '$link'. Such translation already exists!" );

				// Add "MissingDictionary/..." prefix to indicate the issue for user
				$linkText = 'MissingDictionary/' . $title->getPrefixedText();

				if ( $label ) {
					$linkText = "$linkText|$label";
				}

				if ( $colonStart ) {
					$linkText = ':' . $linkText;
				}

				return $linkText;
			}
		} else {
			$translated = $cachedTranslation;
		}

		$translatedTitle = Title::newFromText( $translated );
		$translated = $translatedTitle->getText();

		if ( $fragment !== '' ) {
			// Replace original fragment with translated one
			// It was translated previously
			$translated .= '#' . $translatedFragment;
		}

		if ( $label ) {
			$translated = "$translated|$label";
		}

		if ( $colonStart ) {
			$translated = ':' . $translated;
		}

		if ( $ns !== null ) {
			return "$ns:$translated";
		}

		return $translated;
	}

	/**
	 * @param string &$wikitext
	 * @param string $lang
	 */
	private function translateNamespacesInLinks( &$wikitext, $lang ) {
		$matches = [];
		preg_match_all( '/\[\[(.*?)\]\]/s', $wikitext, $matches );
		foreach ( $matches[1] as $index => $match ) {
			// TODO: Make with "explode/implode" and trim spaces
			if ( strpos( $match, "\n" ) ) {
				$match = str_replace( "\n", " ", $match );
			}

			$text = $this->translateNamespaceInLink( $match, $lang );
			if ( !$text ) {
				continue;
			}

			$wikitext = str_replace(
				$matches[0][$index],
				"[[$text]]",
				$wikitext
			);
		}

		$this->translateGallery( $wikitext, $lang );
	}

	/**
	 * @param string &$wikitext
	 * @param string $lang
	 */
	private function translateGallery( &$wikitext, $lang ) {
		$matches = [];
		preg_match_all( '/(<gallery>|<gallery.*?>)(.*?)<\/gallery>/sm', $wikitext, $matches );
		foreach ( $matches[2] as $index => $match ) {
			$match = trim( $match );
			$lines = explode( "\n", $match );
			$newLines = '';
			foreach ( $lines as $line ) {
				$text = $this->translateNamespaceInLink( $line, $lang );
				if ( !$text ) {
					$newLines .= "$line\n";
				} else {
					$newLines .= "$text\n";
				}
			}
			$newText = "{$matches[1][$index]}\n{$newLines}</gallery>";

			$wikitext = str_replace(
				$matches[0][$index],
				$newText,
				$wikitext
			);
		}
	}

	/**
	 * @param string $link
	 * @param string $lang
	 * @return string|null
	 */
	private function translateNamespaceInLink( $link, $lang ) {
		$mainBits = explode( '|', $link );
		$target = array_shift( $mainBits );
		$label = '';
		if ( !empty( $mainBits ) ) {
			$label = implode( '|', $mainBits );
		}
		if ( strpos( $target, ':' ) === false ) {
			// Not a NS link
			return null;
		}

		if ( strpos( $target, '::' ) !== false ) {
			// Do not need to translate semantic properties
			return null;
		}

		$colonStart = false;
		if ( strpos( $target, ':' ) === 0 ) {
			$colonStart = true;
			$target = substr( $target, 1 );
		}
		$bits = explode( ':', $target );
		$ns = array_shift( $bits );
		// Join back together any remaining NSs
		$title = implode( ':', $bits );
		$dummyTitle = Title::newFromText( "$ns:Dummy" );
		if ( !$dummyTitle instanceof Title || $dummyTitle->getNamespace() === NS_MAIN ) {
			return null;
		}

		$translated = $this->getNsTextInternal( $dummyTitle->getNamespace(), $lang );
		if ( $colonStart ) {
			$translated = ':' . $translated;
		}
		if ( $translated !== $ns ) {
			if ( $label ) {
				return "$translated:$title|$label";
			}
			return "$translated:$title";
		}

		return null;
	}

	/**
	 * @param string &$wikitext
	 */
	private function escapeTemplateParameters( &$wikitext ) {
		$wikitext = preg_replace(
			'/({{{.*?}}})/s',
			"<deepl:ignore>$1</deepl:ignore>",
			$wikitext
		);
	}

	/**
	 * @param string &$wikitext
	 * @return void
	 */
	private function escapeWikitext( string &$wikitext ): void {
		$services = MediaWikiServices::getInstance();

		$contentLang = $services->getContentLanguage();
		$englishLang = $services->getLanguageFactory()->getLanguage( 'en' );

		$escapeWikitext = new EscapeWikitext( $wikitext, $englishLang, $contentLang );
		$escapeWikitext->setLogger( $this->logger );

		$escapeWikitext->process();

		$wikitext = $escapeWikitext->getResultWikitext();
	}

	/**
	 * @param string &$wikitext
	 * @throws Exception
	 */
	private function translateMagicWords( &$wikitext ) {
		$englishLang = MediaWikiServices::getInstance()->getLanguageFactory()->getLanguage( 'en' );
		$enMagic = $englishLang->getMagicWords();

		$doubleUnderscores = MediaWikiServices::getInstance()->getMagicWordFactory()
			->getDoubleUnderscoreArray();
		$vars = MediaWikiServices::getInstance()->getMagicWordFactory()
			->getVariableIDs();
		foreach ( $doubleUnderscores->getNames() as $key ) {
			$this->tryReplaceMagic( $key, $enMagic, $wikitext );

		}

		$matches = [];
		preg_match_all( '#{{(.*?)}}#', $wikitext, $matches );

		// Process "int:" magic words separately,
		// Because we do not want to accidentally translate parts of message key
		// Just because they match with some magic word (that's how MagicWord class regex work)
		$intIndexes = [];
		foreach ( $matches[0] as $index => $match ) {
			$mwId = 'int';

			$mw = MediaWikiServices::getInstance()->getMagicWordFactory()->get( $mwId );
			if ( $mw->match( $match ) ) {
				$newMatch = $match;

				// 1. Translate to English to avoid problems on target wiki
				$this->tryReplaceMagic( $mwId, $enMagic, $newMatch );

				if ( $newMatch !== $match ) {
					$wikitext = preg_replace( "#" . preg_quote( $match ) . "#", $newMatch, $wikitext );
				}

				// 2. Save index to not process "{{int:...}}" strings when translating other magic words
				$intIndexes[] = $index;
			}
		}

		// Recognize templates to not accidentally translate some part of template call
		$templatesIndexes = [];
		foreach ( $matches[0] as $index => $match ) {
			// If there is a '|' symbol - it may be separator for template parameters
			// To correctly recognize template we should separate if from parameters
			$probablyTemplateName = $matches[1][$index];

			if ( strpos( $probablyTemplateName, '|' ) ) {
				$pipeIndex = strpos( $probablyTemplateName, '|' );

				$probablyTemplateName = substr( $probablyTemplateName, 0, $pipeIndex );
			}

			$title = Title::newFromText( $probablyTemplateName, NS_TEMPLATE );

			// The only good way I see here is to check if template actually exists on the wiki
			if ( $title && $title->exists() ) {
				if ( $title->getNamespace() === NS_TEMPLATE ) {
					$templatesIndexes[] = $index;
				} else {
					// Also it could be a transclusion of some existing page (not template)
					// TODO: Probably such cases should be handled in separate method?
					// In that case we should translate title using dictionary
				}
			}
		}

		// Translate variables
		foreach ( $matches[0] as $index => $match ) {
			// Skip templates here, we do not want to translate separate parts of template name
			if ( in_array( $index, $templatesIndexes ) ) {
				continue;
			}

			// Also do not touch "{{int:...}}}" strings
			if ( in_array( $index, $intIndexes ) ) {
				continue;
			}

			$newMatch = $match;
			foreach ( $vars as $key ) {
				$this->tryReplaceMagic( $key, $enMagic, $newMatch );
			}

			if ( $match !== $newMatch ) {
				$wikitext = preg_replace( "#" . preg_quote( $match ) . "#", $newMatch, $wikitext );
			}
		}

		// Collection of English magic may contain some magic words which $vars array does not contain
		// For example, "DISPLAYTITLE" magic word.
		// TODO: Do we really need processing of this $vars array above?
		foreach ( $matches[0] as $index => $match ) {
			// Skip templates here, we do not want to translate separate parts of template name
			if ( in_array( $index, $templatesIndexes ) ) {
				continue;
			}

			// Also do not touch "{{int:...}}}" strings
			if ( in_array( $index, $intIndexes ) ) {
				continue;
			}

			$newMatch = $match;
			foreach ( array_keys( $enMagic ) as $key ) {
				$this->tryReplaceMagic( $key, $enMagic, $newMatch );
			}

			if ( $match !== $newMatch ) {
				$wikitext = preg_replace( "#" . preg_quote( $match ) . "#", $newMatch, $wikitext );
			}
		}
	}

	/**
	 * @param string &$wikitext
	 * @throws Exception
	 */
	private function translateImgAttributes( &$wikitext ) {
		$englishLang = MediaWikiServices::getInstance()->getLanguageFactory()->getLanguage( 'en' );
		$enMagic = $englishLang->getMagicWords();

		$matches = [];
		preg_match_all( '/\[\[.*?\]\]/', $wikitext, $matches );
		foreach ( $matches[0] as $index => $link ) {
			if ( strpos( $link, '|' ) === false ) {
				continue;
			}

			$bits = explode( '|', $link );
			$target = array_shift( $bits );

			if ( !empty( $bits ) ) {
				// Translate only image options
				// If we pass image name as well, then some part of name can be recognized as a magic word
				// Like "[[File:Server.jpg|thumb]] can be changed to "[[File:SERVER.jpg|thumb]]"
				$options = implode( '|', $bits );

				$title = Title::newFromText( $target );
				// Process only "[[File:...]]" links here, because only they can contain options
				if ( !$title || $title->getNamespace() !== NS_FILE ) {
					continue;
				}

				$newLink = $link;
				foreach ( array_keys( $enMagic ) as $key ) {
					if ( strpos( $key, 'img_' ) !== 0 ) {
						continue;
					}
					if ( $this->tryReplaceMagic( $key, $enMagic, $options ) ) {
						continue;
					}
				}

				$link = $target . '|' . $options;

				if ( $link !== $newLink ) {
					$wikitext = preg_replace( "#" . preg_quote( $link ) . "#", $newLink, $wikitext );
				}
			}
		}
	}

	/**
	 * @param string $id
	 * @param array $english
	 * @param string &$wikitext
	 * @return bool
	 */
	private function tryReplaceMagic( $id, $english, &$wikitext ) {
		$mw = MediaWikiServices::getInstance()->getMagicWordFactory()
			->get( $id );
		// TODO: That does not work for cases with values like "alt=<something>" for images
		// But does work for cases like "thumb"
		if ( !$mw->match( $wikitext ) ) {
			return false;
		}
		if ( !isset( $english[$id] ) ) {
			return false;
		}

		$regex = $mw->getRegex();
		$matches = [];
		preg_match_all( $regex, $wikitext, $matches );
		if ( !isset( $matches[0] ) || empty( $matches[0] ) ) {
			return false;
		}

		$toReplace = $matches[0][0];
		$replacement = $english[$id][1];
		if ( $toReplace === $replacement ) {
			return false;
		}

		$wikitext = preg_replace( "/$toReplace/", $replacement, $wikitext );
		return true;
	}

	/**
	 * @param int $nsId
	 * @param string $lang
	 * @return string
	 * @throws ConfigException
	 * @throws Exception
	 */
	private function getNsTextInternal( $nsId, $lang ) {
		$map = $this->config->get( 'namespaceMap' );
		if ( isset( $map[$nsId] ) && isset( $map[$nsId][$lang] ) ) {
			return $map[$nsId][$lang];
		}

		if ( $nsId === NS_FILE ) {
			// Force English for files to prevent issues with attributes
			return "File";
		}

		if ( $nsId === NS_MEDIA ) {
			// Force English for files to prevent issues with attributes
			return "Media";
		}
		$language = MediaWikiServices::getInstance()->getLanguageFactory()->getLanguage( $lang );
		return $language->getNsText( $nsId );
	}

	/**
	 * The same categories translation as in {@link TranslationWikitextConverter::translateCategories()},
	 * but also with translating local version of NS_CATEGORY to target one.
	 *
	 * That is needed when we need to just translate categories in specified wikitext, nothing more.
	 * Without any title-specific context.
	 * Currently, it is done for transcluded titles when we translate page where they are used.
	 * So the only content we need to translate there - category links. Because categories are
	 * always translated with any translation, by design.
	 *
	 * This method can be used outside of {@link TranslationWikitextConverter::preTranslationProcessing()},
	 * but there'll still be conflict in case with usage of both, in any order.
	 * Because here we'll translate NS_CATEGORY to target wiki language, so these internal links will not be
	 * correctly recognized on the source wiki after that. That may cause hardly-recognizable issues
	 * with broken internal links, or adding "rubbish" records to "title dictionary".
	 *
	 * @param string &$wikitext
	 * @param string $lang
	 * @return void
	 * @see \BlueSpice\TranslationTransfer\Util\TranslationPusher::transferTranscludedTitle()
	 */
	public function translateCategoriesWithNs( string &$wikitext, string $lang ): void {
		$this->translateCategories( $wikitext, $lang, true );
	}

	/**
	 * @param string &$wikitext
	 * @param string $lang
	 * @param bool $withNs [false] Whether we should also translate NS_CATEGORY text to target language as well.
	 * @return string
	 */
	private function translateCategories( &$wikitext, $lang, bool $withNs = false ) {
		preg_match_all( '/\[\[(.*?)\]\]/', $wikitext, $matches );
		foreach ( $matches[0] as $index => $link ) {
			$colonStart = false;
			$linkBits = explode( '|', $matches[1][$index] );
			$linkTitle = array_shift( $linkBits );
			if ( strpos( $linkTitle, ':' ) === 0 ) {
				$colonStart = true;
				$linkTitle = substr( $linkTitle, 1 );
			}
			$title = Title::newFromText( $linkTitle );

			if ( !$title || $title->getNamespace() !== NS_CATEGORY ) {
				continue;
			}

			$missingDictionary = false;

			try {
				$translation = $this->getCategoryTranslation( $title, $lang );
			} catch ( Exception $e ) {
				// If there is already another translation for that title - just mark it and do logging
				$this->logger->error( "Category link - '$link'. Such translation already exists!" );

				$missingDictionary = true;
			}

			if ( $missingDictionary ) {
				// Add "MissingDictionary/..." prefix to indicate issue for user
				$targetText = 'MissingDictionary/' . $title->getPrefixedText();
			} else {
				$translatedTitle = Title::makeTitle( NS_CATEGORY, $translation );

				if ( !$withNs ) {
					$targetText = $translatedTitle->getPrefixedText();
				} else {
					$targetText = $this->getNsTextInternal( NS_CATEGORY, $lang ) .
						':' . $translatedTitle->getText();
				}
			}

			array_unshift( $linkBits, $targetText );

			$translatedContent = implode( '|', $linkBits );
			if ( $colonStart ) {
				$translatedContent = ':' . $translatedContent;
			}
			$wikitext = str_replace( $link, "[[" . $translatedContent . "]]", $wikitext );
		}

		return $wikitext;
	}

	/**
	 * @param Title $categoryTitle
	 * @param string $targetLang
	 * @return string
	 */
	private function getCategoryTranslation( Title $categoryTitle, $targetLang ) {
		$cached = $this->titleDictionary->get( $categoryTitle->getPrefixedText(), $targetLang );
		if ( $cached !== null ) {
			return $cached;
		}
		$status = $this->deepL->translateText(
			$categoryTitle->getText(), $this->deepL->extractSourceLanguage(),
			$targetLang
		);

		if ( $status->isOK() ) {
			$translation = $status->getValue();
			$this->titleDictionary->insert( $categoryTitle->getPrefixedText(), $targetLang, $translation );

			return $translation;
		}

		// If for some reason we failed to translate category using DeepL
		// Really rare case, just save logs
		$this->logger->warning( "Failed to translate with DeepL category - '{$categoryTitle->getPrefixedText()}'" );
		$this->logger->debug( "Status from DeepL translation - " . print_r( $status->getErrors(), true ) );

		return $categoryTitle->getText();
	}

	/**
	 * Remove contentTranslate parser functions
	 * @param string &$wikitext
	 */
	private function removeContentTranslation( &$wikitext ) {
		$wikitext = preg_replace( '/\{\{#contentTranslate.*?\}\}/', '', $wikitext );
	}

	/**
	 * @param string &$wikitext
	 * @param string $targetLang
	 * @return void
	 */
	private function translateDisplayTitle( string &$wikitext, string $targetLang ) {
		// At the step when this method is called - "display title" magic word should already be translated to EN
		// So we just need to look for "{{DISPLAYTITLE:}}
		$matches = [];
		preg_match( '#{{DISPLAYTITLE:(.*?)}}#', $wikitext, $matches );
		if ( !empty( $matches ) ) {
			$status = $this->deepL->translateText(
				$matches[1], $this->deepL->extractSourceLanguage(),
				$targetLang
			);

			if ( $status->isOK() ) {
				$translatedDisplayTitle = $status->getValue();

				$wikitext = preg_replace(
					'#' . preg_quote( $matches[0], '#' ) . '#',
					"{{DISPLAYTITLE:$translatedDisplayTitle}}",
					$wikitext
				);
			}
		}
	}
}
