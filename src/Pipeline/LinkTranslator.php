<?php

namespace BlueSpice\TranslationTransfer\Pipeline;

use BlueSpice\TranslationTransfer\IDictionary;
use Exception;
use MediaWiki\Config\Config;
use MediaWiki\Languages\LanguageFactory;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use MWStake\MediaWiki\Component\DeepLTranslator\DeepLTranslator;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Post-processing step that translates internal link targets in assembled wikitext.
 *
 * Runs after the segmenter→translator→assembler pipeline has produced the translated
 * wikitext. Processes ALL [[...]] links in the final output, including those inside
 * templates and other opaque blocks.
 *
 * Processing order (matching legacy TranslationWikitextConverter):
 * 1. Categories — always translated (both namespace and title)
 * 2. Page titles — translated when `translatePageTitle` config is true
 * 3. Namespaces — translated when `translateNamespaces` config is true
 * 4. Gallery file namespaces — translated when `translateNamespaces` is true
 *
 * Title translation before namespace translation is important: namespace text must
 * still be in the source language when titles are parsed, otherwise TitleFactory
 * may not recognise the namespace.
 *
 * Uses TitleDictionary as cache. Falls back to DeepL for uncached translations.
 */
class LinkTranslator implements LoggerAwareInterface {

	/** @var Config */
	private $conversionConfig;

	/** @var DeepLTranslator */
	private $deepL;

	/** @var IDictionary */
	private $titleDictionary;

	/** @var TitleFactory */
	private $titleFactory;

	/** @var LanguageFactory */
	private $languageFactory;

	/** @var LoggerInterface */
	private $logger;

	/** @var string */
	private $sourceLang;

	/**
	 * @param Config $conversionConfig DeeplTranslateConversionConfig values
	 * @param DeepLTranslator $deepL
	 * @param IDictionary $titleDictionary
	 * @param TitleFactory $titleFactory
	 * @param LanguageFactory $languageFactory
	 */
	public function __construct(
		Config $conversionConfig,
		DeepLTranslator $deepL,
		IDictionary $titleDictionary,
		TitleFactory $titleFactory,
		LanguageFactory $languageFactory
	) {
		$this->conversionConfig = $conversionConfig;
		$this->deepL = $deepL;
		$this->titleDictionary = $titleDictionary;
		$this->titleFactory = $titleFactory;
		$this->languageFactory = $languageFactory;
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
	 * Translate all internal links in the assembled wikitext.
	 *
	 * Processes [[...]] links across the entire output, including inside templates,
	 * tables, and other structural elements. Order: categories → titles → namespaces → galleries.
	 *
	 * @param string $wikitext Assembled wikitext (output of SkeletonAssembler)
	 * @param string $sourceLang Source language code
	 * @param string $targetLang Target language code
	 * @return string Wikitext with translated links
	 */
	public function translateLinks( string $wikitext, string $sourceLang, string $targetLang ): string {
		$this->sourceLang = $sourceLang;

		// 1. Categories — always translated
		$wikitext = $this->translateCategories( $wikitext, $targetLang );

		// 2. Titles — before namespaces (namespace text must be in source language for parsing)
		if ( $this->conversionConfig->get( 'translatePageTitle' ) ) {
			$wikitext = $this->translateTitlesInLinks( $wikitext, $targetLang );
		}

		// 3. Namespaces in [[...]] links
		if ( $this->conversionConfig->get( 'translateNamespaces' ) ) {
			$wikitext = $this->translateNamespacesInLinks( $wikitext, $targetLang );
			// 4. Gallery file namespace prefixes
			$wikitext = $this->translateGalleryNamespaces( $wikitext, $targetLang );
		}

		return $wikitext;
	}

	/**
	 * Translate all category links. Categories are always translated (namespace + title).
	 *
	 * @param string $wikitext
	 * @param string $targetLang
	 * @return string
	 */
	private function translateCategories( string $wikitext, string $targetLang ): string {
		$matches = [];
		preg_match_all( '/\[\[(.*?)\]\]/s', $wikitext, $matches );

		foreach ( $matches[0] as $index => $fullMatch ) {
			$linkContent = $matches[1][$index];

			// Preserve leading colon
			$colonStart = false;
			$workContent = $linkContent;
			if ( strpos( $workContent, ':' ) === 0 ) {
				$colonStart = true;
				$workContent = substr( $workContent, 1 );
			}

			// Separate target from display text / sort key
			$linkBits = explode( '|', $workContent );
			$targetText = array_shift( $linkBits );

			// Skip semantic properties
			if ( strpos( $targetText, '::' ) !== false ) {
				continue;
			}

			$title = $this->titleFactory->newFromText( $targetText );
			if ( !$title || $title->getNamespace() !== NS_CATEGORY ) {
				continue;
			}

			$translatedTitle = $this->getTranslatedTitle( $title, $targetLang );
			$translatedNs = $this->getNsText( NS_CATEGORY, $targetLang );

			$newTarget = $translatedNs . ':' . $translatedTitle;
			if ( $colonStart ) {
				$newTarget = ':' . $newTarget;
			}

			// Re-attach display text / sort key if present
			array_unshift( $linkBits, $newTarget );
			$newContent = implode( '|', $linkBits );

			$wikitext = str_replace( $fullMatch, '[[' . $newContent . ']]', $wikitext );
		}

		return $wikitext;
	}

	/**
	 * Translate page titles in internal links (when configured).
	 *
	 * @param string $wikitext
	 * @param string $targetLang
	 * @return string
	 */
	private function translateTitlesInLinks( string $wikitext, string $targetLang ): string {
		$matches = [];
		preg_match_all( '/\[\[(.*?)\]\]/s', $wikitext, $matches );

		foreach ( $matches[0] as $index => $fullMatch ) {
			$linkContent = $matches[1][$index];

			$translated = $this->translateTitleInLink( $linkContent, $targetLang );
			if ( $translated === null ) {
				continue;
			}

			$wikitext = str_replace( $fullMatch, '[[' . $translated . ']]', $wikitext );
		}

		return $wikitext;
	}

	/**
	 * Translate the page title part of a single link.
	 *
	 * @param string $linkContent Content between [[ and ]]
	 * @param string $targetLang
	 * @return string|null Translated link content, or null if not translatable
	 */
	private function translateTitleInLink( string $linkContent, string $targetLang ): ?string {
		// Handle newlines in link content
		if ( strpos( $linkContent, "\n" ) !== false ) {
			$linkContent = str_replace( "\n", ' ', $linkContent );
		}

		$mainBits = explode( '|', $linkContent );
		$target = array_shift( $mainBits );
		$label = !empty( $mainBits ) ? implode( '|', $mainBits ) : '';

		// Check for main namespace (no colon in target)
		$mainNs = ( strpos( $target, ':' ) === false );

		// Handle leading colon
		$colonStart = false;
		if ( strpos( $target, ':' ) === 0 ) {
			$colonStart = true;
			$target = substr( $target, 1 );
		}

		// Skip semantic properties
		if ( strpos( $target, '::' ) !== false ) {
			return null;
		}

		// Skip MissingDictionary prefixed links (from previous category translation)
		if ( strpos( $target, 'MissingDictionary/' ) === 0 ) {
			return null;
		}

		$title = $this->titleFactory->newFromText( $target );
		if ( !$title ) {
			$this->logger->warning( 'LinkTranslator: invalid title in link "{link}"', [
				'link' => $linkContent,
			] );
			return null;
		}

		$ns = $title->getNamespace();

		// Skip file/media links
		if ( $ns === NS_FILE || $ns === NS_MEDIA ) {
			return null;
		}

		// Skip categories (already handled in translateCategories)
		if ( $ns === NS_CATEGORY ) {
			return null;
		}

		// Skip interwiki/language links
		if ( $title->isExternal() ) {
			return null;
		}

		// Extract namespace prefix for non-main-NS links
		$nsPrefix = null;
		if ( !$mainNs ) {
			$bits = explode( ':', $target );
			$nsPrefix = array_shift( $bits );
		}

		// Handle fragment (section anchor)
		$fragment = $title->getFragment();
		$translatedFragment = '';
		if ( $fragment !== '' ) {
			$translatedFragment = $this->translateText( $fragment, $targetLang );

			// Anchor-only link (e.g., [[#Section]])
			if ( $title->getPrefixedText() === '' ) {
				$result = '#' . $translatedFragment;
				if ( $label !== '' ) {
					$result .= '|' . $label;
				}
				return $result;
			}
		}

		// Translate the title text
		$translatedTitle = $this->getTranslatedTitle( $title, $targetLang );

		// Rebuild the link
		$result = '';
		if ( $colonStart ) {
			$result = ':';
		}
		if ( $nsPrefix !== null ) {
			$result .= $nsPrefix . ':';
		}
		$result .= $translatedTitle;

		if ( $translatedFragment !== '' ) {
			$result .= '#' . $translatedFragment;
		} elseif ( $fragment !== '' ) {
			$result .= '#' . $fragment;
		}

		if ( $label !== '' ) {
			$result .= '|' . $label;
		}

		return $result;
	}

	/**
	 * Translate namespace prefixes in internal links (when configured).
	 *
	 * @param string $wikitext
	 * @param string $targetLang
	 * @return string
	 */
	private function translateNamespacesInLinks( string $wikitext, string $targetLang ): string {
		$matches = [];
		preg_match_all( '/\[\[(.*?)\]\]/s', $wikitext, $matches );

		foreach ( $matches[0] as $index => $fullMatch ) {
			$linkContent = $matches[1][$index];

			// Handle newlines
			if ( strpos( $linkContent, "\n" ) !== false ) {
				$linkContent = str_replace( "\n", ' ', $linkContent );
			}

			$translated = $this->translateNamespaceInLink( $linkContent, $targetLang );
			if ( $translated === null ) {
				continue;
			}

			$wikitext = str_replace( $fullMatch, '[[' . $translated . ']]', $wikitext );
		}

		return $wikitext;
	}

	/**
	 * Translate the namespace prefix of a single link.
	 *
	 * @param string $linkContent Content between [[ and ]]
	 * @param string $targetLang
	 * @return string|null Translated link content, or null if not translatable
	 */
	private function translateNamespaceInLink( string $linkContent, string $targetLang ): ?string {
		$mainBits = explode( '|', $linkContent );
		$target = array_shift( $mainBits );
		$label = !empty( $mainBits ) ? implode( '|', $mainBits ) : '';

		// Must have a colon to have a namespace
		if ( strpos( $target, ':' ) === false ) {
			return null;
		}

		// Skip semantic properties
		if ( strpos( $target, '::' ) !== false ) {
			return null;
		}

		// Handle leading colon
		$colonStart = false;
		if ( strpos( $target, ':' ) === 0 ) {
			$colonStart = true;
			$target = substr( $target, 1 );
		}

		// Skip MissingDictionary links
		if ( strpos( $target, 'MissingDictionary/' ) === 0 ) {
			return null;
		}

		$title = $this->titleFactory->newFromText( $target );
		if ( !$title ) {
			return null;
		}

		$ns = $title->getNamespace();

		// Skip interwiki
		if ( $title->isExternal() ) {
			return null;
		}

		// Skip main namespace
		if ( $ns === NS_MAIN ) {
			return null;
		}

		// Category namespace is already translated in translateCategories
		if ( $ns === NS_CATEGORY ) {
			return null;
		}

		$translatedNs = $this->getNsText( $ns, $targetLang );
		$titleWithFragment = $title->getText();
		if ( $title->getFragment() !== '' ) {
			$titleWithFragment .= '#' . $title->getFragment();
		}

		$result = '';
		if ( $colonStart ) {
			$result = ':';
		}
		$result .= $translatedNs . ':' . $titleWithFragment;

		if ( $label !== '' ) {
			$result .= '|' . $label;
		}

		return $result;
	}

	/**
	 * Translate file namespace prefixes in gallery blocks.
	 *
	 * Gallery file references (e.g., "Datei:Photo.jpg") need their namespace prefix
	 * translated. File/Media namespaces are forced to English ("File"/"Media") per convention.
	 *
	 * @param string $wikitext Assembled wikitext
	 * @param string $targetLang
	 * @return string Wikitext with translated gallery namespaces
	 */
	private function translateGalleryNamespaces( string $wikitext, string $targetLang ): string {
		return preg_replace_callback(
			'/(<gallery[^>]*>)(.*?)(<\/gallery>)/si',
			function ( $matches ) use ( $targetLang ) {
				$openTag = $matches[1];
				$content = $matches[2];
				$closeTag = $matches[3];

				$lines = explode( "\n", $content );
				$newLines = [];
				foreach ( $lines as $line ) {
					$newLines[] = $this->translateGalleryLine( $line, $targetLang );
				}

				return $openTag . implode( "\n", $newLines ) . $closeTag;
			},
			$wikitext
		);
	}

	/**
	 * Translate the namespace in a single gallery line (e.g., "Datei:Photo.jpg|Caption").
	 *
	 * @param string $line
	 * @param string $targetLang
	 * @return string
	 */
	private function translateGalleryLine( string $line, string $targetLang ): string {
		$trimmed = trim( $line );
		if ( $trimmed === '' || strpos( $trimmed, ':' ) === false ) {
			return $line;
		}

		$parts = explode( '|', $trimmed, 2 );
		$fileRef = $parts[0];

		$title = $this->titleFactory->newFromText( $fileRef );
		if ( !$title || ( $title->getNamespace() !== NS_FILE && $title->getNamespace() !== NS_MEDIA ) ) {
			return $line;
		}

		$translatedNs = $this->getNsText( $title->getNamespace(), $targetLang );

		// Extract original NS prefix from raw text (TitleFactory normalizes to content language)
		$colonPos = strpos( $fileRef, ':' );
		$originalNs = substr( $fileRef, 0, $colonPos );

		if ( $translatedNs === $originalNs ) {
			return $line;
		}

		$newFileRef = $translatedNs . ':' . $title->getText();
		if ( isset( $parts[1] ) ) {
			return $newFileRef . '|' . $parts[1];
		}
		return $newFileRef;
	}

	/**
	 * Get the translated namespace text for a target language.
	 *
	 * Checks config namespaceMap first, then falls back to MediaWiki language NS names.
	 * File/Media namespaces are forced to English for compatibility.
	 *
	 * @param int $nsId
	 * @param string $targetLang
	 * @return string
	 */
	private function getNsText( int $nsId, string $targetLang ): string {
		$map = $this->conversionConfig->get( 'namespaceMap' );
		if ( isset( $map[$nsId] ) && isset( $map[$nsId][$targetLang] ) ) {
			return $map[$nsId][$targetLang];
		}

		if ( $nsId === NS_FILE ) {
			return 'File';
		}
		if ( $nsId === NS_MEDIA ) {
			return 'Media';
		}

		$language = $this->languageFactory->getLanguage( $targetLang );
		return $language->getNsText( $nsId );
	}

	/**
	 * Get translated title text using TitleDictionary, falling back to DeepL.
	 *
	 * @param Title $title
	 * @param string $targetLang
	 * @return string Translated title text
	 */
	private function getTranslatedTitle( Title $title, string $targetLang ): string {
		$cached = $this->titleDictionary->get( $title->getPrefixedText(), $targetLang );
		if ( $cached !== null ) {
			return $cached;
		}

		// Translate via DeepL
		$translated = $this->translateText( $title->getText(), $targetLang );

		if ( $translated !== $title->getText() ) {
			try {
				$this->titleDictionary->insert( $title->getPrefixedText(), $targetLang, $translated );
			} catch ( Exception $e ) {
				$this->logger->error(
					'LinkTranslator: dictionary insert failed for "{title}": {error}',
					[ 'title' => $title->getPrefixedText(), 'error' => $e->getMessage() ]
				);
				return 'MissingDictionary/' . $title->getPrefixedText();
			}
		}

		return $translated;
	}

	/**
	 * Translate a short text via DeepL (for titles, fragments).
	 *
	 * @param string $text
	 * @param string $targetLang
	 * @return string Translated text, or original on failure
	 */
	private function translateText( string $text, string $targetLang ): string {
		$status = $this->deepL->translateText( $text, $this->sourceLang, $targetLang );
		if ( $status->isOK() ) {
			return $status->getValue();
		}

		$this->logger->warning( 'LinkTranslator: DeepL translation failed for "{text}"', [
			'text' => $text,
		] );
		return $text;
	}
}
