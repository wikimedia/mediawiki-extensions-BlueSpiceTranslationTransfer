<?php

namespace BlueSpice\TranslationTransfer\Pipeline;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Converts inline wikitext formatting to/from HTML for DeepL translation.
 *
 * Outbound (wikitextToHtml): Prepares a segment for DeepL by converting wikitext to HTML:
 *   1. Templates, file links → opaque placeholders (not translated)
 *   2. Inline tags (code, nowiki, pre, ref, deepl:ignore, translation:ignore) → opaque placeholders
 *   3. Bold/italic → <b>/<i> tags (DeepL preserves HTML tags)
 *   4. Internal links [[Target|Label]] → <a data-link-id="N">Label</a> (target stored in linkMap)
 *   5. External links [url label] → <a data-link-id="N">label</a> (URL stored in linkMap)
 *   6. Remaining &, <, > → HTML entities (prevents DeepL from interpreting them)
 *
 * Inbound (htmlToWikitext): Reverses the conversion after DeepL returns translated HTML:
 *   1. <b>/<i>/<strong>/<em> → bold/italic wiki markup
 *   2. <a data-link-id="N">Label</a> → [[Target|Label]] or [url label]
 *   3. <span data-opaque-id="N"></span> → original opaque content
 *   4. HTML entities → decoded characters
 *
 * The linkMap and opaqueMap are captured after outbound conversion and restored before
 * inbound conversion, allowing the converter to be reused across segments.
 *
 * Note on bold/italic: Uses a simple regex approach that does not replicate MediaWiki's
 * full state machine. Pathological inputs (unbalanced quotes) may produce incorrect results.
 */
class InlineConverter implements LoggerAwareInterface {

	/** @var LoggerInterface */
	private $logger;

	/** @var array Map of link-id → original link target */
	private $linkMap = [];

	/** @var array Map of opaque-id → original wikitext */
	private $opaqueMap = [];

	/** @var int */
	private $linkCounter = 0;

	/** @var int */
	private $opaqueCounter = 0;

	/** @var string[] File namespace prefixes to recognize (e.g., ['File', 'Image', 'Datei']) */
	private $fileNamespacePrefixes;

	/**
	 * @param string[] $fileNamespacePrefixes List of namespace prefixes that indicate file links.
	 *   Gathered from NamespaceInfo at wiring time so we don't hardcode language-specific names.
	 *   Defaults to ['File', 'Image'] if not provided.
	 */
	public function __construct( array $fileNamespacePrefixes = [ 'File', 'Image' ] ) {
		$this->logger = new NullLogger();
		$this->fileNamespacePrefixes = $fileNamespacePrefixes;
	}

	/**
	 * @param LoggerInterface $logger
	 */
	public function setLogger( LoggerInterface $logger ): void {
		$this->logger = $logger;
	}

	/**
	 * Reset internal state between segments.
	 */
	public function reset(): void {
		$this->linkMap = [];
		$this->opaqueMap = [];
		$this->linkCounter = 0;
		$this->opaqueCounter = 0;
	}

	/**
	 * Restore maps from a previously captured state (for inbound conversion).
	 *
	 * @param array $linkMap
	 * @param array $opaqueMap
	 */
	public function restoreMaps( array $linkMap, array $opaqueMap ): void {
		$this->linkMap = $linkMap;
		$this->opaqueMap = $opaqueMap;
	}

	/**
	 * @return array
	 */
	public function getLinkMap(): array {
		return $this->linkMap;
	}

	/**
	 * @return array
	 */
	public function getOpaqueMap(): array {
		return $this->opaqueMap;
	}

	/**
	 * Convert wikitext inline formatting to HTML for DeepL.
	 *
	 * @param string $wikitext
	 * @return string HTML fragment
	 */
	public function wikitextToHtml( string $wikitext ): string {
		$html = $wikitext;

		// 1. Replace opaque elements first (templates, file links)
		$html = $this->replaceOpaqueElements( $html );

		// 2. Convert wikitext formatting to HTML
		// Bold+italic must come before bold and italic (longest match first)
		$html = $this->convertBoldItalic( $html );
		$html = $this->convertBold( $html );
		$html = $this->convertItalic( $html );

		// 3. Convert internal links
		$html = $this->convertInternalLinks( $html );

		// 4. Convert external links
		$html = $this->convertExternalLinks( $html );

		// 5. HTML-encode remaining special characters in prose
		// (but NOT inside tags we already created)
		$html = $this->encodeSpecialChars( $html );

		return $html;
	}

	/**
	 * Convert HTML back to wikitext after DeepL translation.
	 *
	 * @param string $html
	 * @return string wikitext
	 */
	public function htmlToWikitext( string $html ): string {
		$wikitext = $html;

		// 1. Convert formatting tags back
		$wikitext = $this->revertBoldItalic( $wikitext );
		$wikitext = $this->revertBold( $wikitext );
		$wikitext = $this->revertItalic( $wikitext );

		// 2. Convert links back (both internal and external are handled via the shared linkMap)
		$wikitext = $this->revertLinks( $wikitext );

		// 3. Restore opaque elements
		$wikitext = $this->restoreOpaqueElements( $wikitext );

		// 4. Decode HTML entities
		$wikitext = html_entity_decode( $wikitext, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// 5. Log any remaining unrecognized HTML tags
		$this->logUnrecognizedTags( $wikitext );

		return $wikitext;
	}

	/**
	 * Replace templates {{...}}, file links [[File:...]], inline opaque tags
	 * (<code>, <nowiki>, <pre>, <ref>), and translation-ignore tags with opaque placeholders.
	 * Uses brace-depth counting for proper nested template handling.
	 *
	 * @param string $text
	 * @return string
	 */
	private function replaceOpaqueElements( string $text ): string {
		// Replace nested templates using brace-depth counter
		$text = $this->replaceNestedTemplates( $text );

		// Replace file/image links [[File:...]], [[Image:...]], etc.
		$prefixPattern = implode( '|', array_map( 'preg_quote', $this->fileNamespacePrefixes ) );
		$text = preg_replace_callback(
			'/\[\[(' . $prefixPattern . '):([^\]]*)\]\]/i',
			function ( $matches ) {
				$id = $this->opaqueCounter++;
				$this->opaqueMap[$id] = $matches[0];
				return "<span data-opaque-id=\"{$id}\"></span>";
			},
			$text
		);

		// Replace inline opaque HTML tags: <code>...</code>, <nowiki>...</nowiki>, <pre>...</pre>
		$text = $this->replaceInlineOpaqueTags( $text );

		// Replace <ref>...</ref> and self-closing <ref .../> tags
		$text = $this->replaceRefTags( $text );

		// Replace <deepl:ignore>...</deepl:ignore> and <translation:ignore>...</translation:ignore>
		$text = $this->replaceIgnoreTags( $text );

		return $text;
	}

	/**
	 * Replace inline opaque HTML tags with placeholders.
	 * Handles <code>...</code>, <nowiki>...</nowiki>, <pre>...</pre>.
	 *
	 * @param string $text
	 * @return string
	 */
	private function replaceInlineOpaqueTags( string $text ): string {
		$inlineTags = [ 'code', 'nowiki', 'pre' ];
		foreach ( $inlineTags as $tag ) {
			$text = preg_replace_callback(
				'/<' . $tag . '(\s[^>]*)?>.*?<\/' . $tag . '>/si',
				function ( $matches ) {
					$id = $this->opaqueCounter++;
					$this->opaqueMap[$id] = $matches[0];
					return "<span data-opaque-id=\"{$id}\"></span>";
				},
				$text
			);
		}
		return $text;
	}

	/**
	 * Replace <ref>...</ref> and self-closing <ref .../> tags with opaque placeholders.
	 *
	 * @param string $text
	 * @return string
	 */
	private function replaceRefTags( string $text ): string {
		// Self-closing <ref name="..." /> first (must come before the paired version)
		$text = preg_replace_callback(
			'/<ref\s[^>]*\/>/si',
			function ( $matches ) {
				$id = $this->opaqueCounter++;
				$this->opaqueMap[$id] = $matches[0];
				return "<span data-opaque-id=\"{$id}\"></span>";
			},
			$text
		);

		// Paired <ref>...</ref> and <ref name="...">...</ref>
		$text = preg_replace_callback(
			'/<ref(\s[^>]*)?>.*?<\/ref>/si',
			function ( $matches ) {
				$id = $this->opaqueCounter++;
				$this->opaqueMap[$id] = $matches[0];
				return "<span data-opaque-id=\"{$id}\"></span>";
			},
			$text
		);

		return $text;
	}

	/**
	 * Replace <deepl:ignore>...</deepl:ignore> and <translation:ignore>...</translation:ignore>
	 * with opaque placeholders. The tags are stripped on the way back (content is preserved).
	 *
	 * @param string $text
	 * @return string
	 */
	private function replaceIgnoreTags( string $text ): string {
		$ignoreTags = [ 'deepl:ignore', 'translation:ignore' ];
		foreach ( $ignoreTags as $tag ) {
			$quotedTag = preg_quote( $tag, '/' );
			$text = preg_replace_callback(
				'/<' . $quotedTag . '>(.*?)<\/' . $quotedTag . '>/si',
				function ( $matches ) {
					$id = $this->opaqueCounter++;
					// Store only the inner content — the ignore tags themselves are stripped
					$this->opaqueMap[$id] = $matches[1];
					return "<span data-opaque-id=\"{$id}\"></span>";
				},
				$text
			);
		}
		return $text;
	}

	/**
	 * Replace templates with proper brace-depth counting.
	 *
	 * @param string $text
	 * @return string
	 */
	private function replaceNestedTemplates( string $text ): string {
		$result = '';
		$len = strlen( $text );
		$i = 0;

		while ( $i < $len ) {
			if ( $i < $len - 1 && $text[$i] === '{' && $text[$i + 1] === '{' ) {
				// Found template opening — find matching close
				$depth = 0;
				$start = $i;

				while ( $i < $len ) {
					if ( $i < $len - 1 && $text[$i] === '{' && $text[$i + 1] === '{' ) {
						$depth++;
						$i += 2;
					} elseif ( $i < $len - 1 && $text[$i] === '}' && $text[$i + 1] === '}' ) {
						$depth--;
						$i += 2;
						if ( $depth === 0 ) {
							break;
						}
					} else {
						$i++;
					}
				}

				$templateText = substr( $text, $start, $i - $start );
				$id = $this->opaqueCounter++;
				$this->opaqueMap[$id] = $templateText;
				$result .= "<span data-opaque-id=\"{$id}\"></span>";
			} else {
				$result .= $text[$i];
				$i++;
			}
		}

		return $result;
	}

	/**
	 * Convert wikitext bold+italic to HTML.
	 *
	 * NOTE: This uses a simple regex that does not replicate MediaWiki's full
	 * bold/italic state machine. Pathological inputs like unbalanced quotes
	 * (e.g., "'''a'' b ''c'''") may produce incorrect results. This is acceptable
	 * because such wikitext is rare in practice and would also render
	 * unpredictably in MediaWiki itself.
	 *
	 * @param string $html
	 * @return string
	 */
	private function convertBoldItalic( string $html ): string {
		return preg_replace(
			"/'{5}(.*?)'{5}/s",
			'<b><i>$1</i></b>',
			$html
		);
	}

	/**
	 * @param string $html
	 * @return string
	 */
	private function convertBold( string $html ): string {
		return preg_replace(
			"/'{3}(.*?)'{3}/s",
			'<b>$1</b>',
			$html
		);
	}

	/**
	 * @param string $html
	 * @return string
	 */
	private function convertItalic( string $html ): string {
		return preg_replace(
			"/'{2}(.*?)'{2}/s",
			'<i>$1</i>',
			$html
		);
	}

	/**
	 * Convert [[Target|Label]] and [[Target]] to HTML anchors.
	 *
	 * @param string $html
	 * @return string
	 */
	private function convertInternalLinks( string $html ): string {
		// [[Target|Label]]
		$html = preg_replace_callback(
			'/\[\[([^\]|]+)\|([^\]]+)\]\]/',
			function ( $matches ) {
				$id = $this->linkCounter++;
				$this->linkMap[$id] = $matches[1];
				return "<a data-link-id=\"{$id}\">{$matches[2]}</a>";
			},
			$html
		);

		// [[Target]] (no pipe) — empty tag so DeepL won't translate the title
		$html = preg_replace_callback(
			'/\[\[([^\]|]+)\]\]/',
			function ( $matches ) {
				$id = $this->linkCounter++;
				$this->linkMap[$id] = $matches[1];
				return "<a data-link-id=\"{$id}\"></a>";
			},
			$html
		);

		return $html;
	}

	/**
	 * Convert [url label] external links to HTML anchors.
	 *
	 * @param string $html
	 * @return string
	 */
	private function convertExternalLinks( string $html ): string {
		// [url label]
		$html = preg_replace_callback(
			'/\[(https?:\/\/[^\s\]]+)\s+([^\]]+)\]/',
			function ( $matches ) {
				$id = $this->linkCounter++;
				$this->linkMap[$id] = $matches[1];
				return "<a data-link-id=\"{$id}\">{$matches[2]}</a>";
			},
			$html
		);

		// [url] (no label) — treat as opaque
		$html = preg_replace_callback(
			'/\[(https?:\/\/[^\s\]]+)\]/',
			function ( $matches ) {
				$id = $this->opaqueCounter++;
				$this->opaqueMap[$id] = $matches[0];
				return "<span data-opaque-id=\"{$id}\"></span>";
			},
			$html
		);

		return $html;
	}

	/**
	 * HTML-encode &, <, > that are NOT inside already-created HTML tags.
	 *
	 * @param string $html
	 * @return string
	 */
	private function encodeSpecialChars( string $html ): string {
		// Split by valid HTML tags (opening, closing, self-closing)
		$parts = preg_split(
			'/(<\/?[a-z][a-z0-9]*(?:\s[^>]*)?\s*\/?>)/i',
			$html,
			-1,
			PREG_SPLIT_DELIM_CAPTURE
		);

		$result = '';
		foreach ( $parts as $part ) {
			if ( preg_match( '/^<\/?[a-z][a-z0-9]*(?:\s[^>]*)?\s*\/?>$/i', $part ) ) {
				// This is an HTML tag — leave unchanged
				$result .= $part;
			} else {
				// Text content — encode < and >, and encode & only when
				// it is NOT already part of an HTML entity (e.g., &amp; &lt; &mdash;)
				$part = preg_replace( '/&(?!#?\w+;)/', '&amp;', $part );
				$part = str_replace( [ '<', '>' ], [ '&lt;', '&gt;' ], $part );
				$result .= $part;
			}
		}

		return $result;
	}

	/**
	 * @param string $wikitext
	 * @return string
	 */
	private function revertBoldItalic( string $wikitext ): string {
		// <b><i>X</i></b> or <strong><em>X</em></strong>
		$wikitext = preg_replace(
			'#<b>\s*<i>(.*?)</i>\s*</b>#si',
			"'''''$1'''''",
			$wikitext
		);
		$wikitext = preg_replace(
			'#<strong>\s*<em>(.*?)</em>\s*</strong>#si',
			"'''''$1'''''",
			$wikitext
		);
		return $wikitext;
	}

	/**
	 * @param string $wikitext
	 * @return string
	 */
	private function revertBold( string $wikitext ): string {
		$wikitext = preg_replace( '#<b>(.*?)</b>#si', "'''$1'''", $wikitext );
		$wikitext = preg_replace( '#<strong>(.*?)</strong>#si', "'''$1'''", $wikitext );
		return $wikitext;
	}

	/**
	 * @param string $wikitext
	 * @return string
	 */
	private function revertItalic( string $wikitext ): string {
		$wikitext = preg_replace( '#<i>(.*?)</i>#si', "''$1''", $wikitext );
		$wikitext = preg_replace( '#<em>(.*?)</em>#si', "''$1''", $wikitext );
		return $wikitext;
	}

	/**
	 * Convert <a data-link-id="N">X</a> back to wikitext links.
	 * Handles both internal wiki links and external URL links via the shared linkMap.
	 *
	 * @param string $wikitext
	 * @return string
	 */
	private function revertLinks( string $wikitext ): string {
		return preg_replace_callback(
			'#<a\s+data-link-id="(\d+)">(.*?)</a>#si',
			function ( $matches ) {
				$id = (int)$matches[1];
				$label = $matches[2];
				$target = $this->linkMap[$id] ?? $label;

				// Check if target is a URL (external link)
				if ( preg_match( '#^https?://#', $target ) ) {
					if ( trim( $label ) === $target ) {
						return "[$target]";
					}
					return "[$target $label]";
				}

				// Internal link — empty label means no-pipe link (title not translated)
				if ( trim( $label ) === '' || trim( $label ) === trim( $target ) ) {
					return "[[{$target}]]";
				}
				return "[[{$target}|{$label}]]";
			},
			$wikitext
		);
	}

	/**
	 * Restore opaque elements from placeholders.
	 *
	 * @param string $wikitext
	 * @return string
	 */
	private function restoreOpaqueElements( string $wikitext ): string {
		return preg_replace_callback(
			'#<span\s+data-opaque-id="(\d+)">\s*</span>#',
			function ( $matches ) {
				$id = (int)$matches[1];
				if ( isset( $this->opaqueMap[$id] ) ) {
					return $this->opaqueMap[$id];
				}
				$this->logger->error( "Opaque placeholder id={$id} not found in map" );
				return '';
			},
			$wikitext
		);
	}

	/**
	 * Log any remaining HTML tags that we didn't handle.
	 *
	 * @param string $wikitext
	 */
	private function logUnrecognizedTags( string $wikitext ): void {
		$allowedTags = [ 'u', 'sup', 'sub', 'br', 's', 'del', 'ins', 'small', 'big',
			'code', 'pre', 'nowiki', 'ref', 'references', 'gallery', 'syntaxhighlight',
			'math', 'inputbox', 'poem', 'score', 'div', 'span', 'p', 'table', 'tr', 'td', 'th' ];

		if ( preg_match_all( '#</?([a-z][a-z0-9]*)[^>]*>#i', $wikitext, $matches ) ) {
			foreach ( $matches[1] as $tag ) {
				if ( !in_array( strtolower( $tag ), $allowedTags, true ) ) {
					$this->logger->warning(
						"Unrecognized HTML tag in translation output: <{tag}>",
						[ 'tag' => $tag ]
					);
				}
			}
		}
	}
}
