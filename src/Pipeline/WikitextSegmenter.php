<?php

namespace BlueSpice\TranslationTransfer\Pipeline;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Parses wikitext into translatable segments and a structural skeleton.
 *
 * The skeleton retains all non-translatable structure (table markup, headings markers,
 * list prefixes, opaque blocks) with PUA-character markers replacing translatable content.
 * Segments contain only the translatable text (paragraphs, headings, list items, table cells,
 * gallery captions).
 *
 * Processing order for each line:
 * 1. Opaque block tags (syntaxhighlight, math, etc.) — content skipped entirely
 * 2. Gallery blocks — captions extracted as segments, file references kept in skeleton
 * 3. Table rows — cell content extracted as segments, markup kept in skeleton
 * 4. Headings — content extracted as segment
 * 5. Redirects — kept verbatim in skeleton
 * 6. List items — content extracted as segment
 * 7. Behavior switches (__TOC__, etc.) — kept verbatim in skeleton
 * 8. Regular content — accumulated into paragraph buffer, flushed as single segment
 *
 * Multi-line templates are kept together by detecting unbalanced {{ braces in the
 * paragraph buffer and suppressing paragraph breaks until braces balance.
 *
 * @see Segment For the segment data object
 * @see SkeletonAssembler For the reverse operation (markers → translated text)
 */
class WikitextSegmenter implements LoggerAwareInterface {

	/** @var LoggerInterface */
	private $logger;

	/**
	 * Tags whose content should not be translated (treated as opaque blocks).
	 * Only includes tags that are inherently block-level.
	 * Inline tags (code, nowiki, pre) are handled by InlineConverter instead,
	 * so that surrounding prose on the same line is still translated.
	 * Gallery is handled separately to allow caption translation.
	 * @var string[]
	 */
	private const OPAQUE_BLOCK_TAGS = [
		'syntaxhighlight', 'source',
		'math', 'inputbox', 'poem', 'score',
		'html', 'categorytree'
	];

	public function __construct() {
		$this->logger = new NullLogger();
	}

	/**
	 * @param LoggerInterface $logger
	 */
	public function setLogger( LoggerInterface $logger ): void {
		$this->logger = $logger;
	}

	/**
	 * Segment wikitext into translatable units.
	 *
	 * @param string $wikitext
	 * @return array [ string $skeleton, Segment[] $segments ]
	 */
	public function segment( string $wikitext ): array {
		if ( trim( $wikitext ) === '' ) {
			return [ $wikitext, [] ];
		}

		$lines = explode( "\n", $wikitext );
		$segments = [];
		$skeletonLines = [];
		$segmentId = 0;

		$inOpaqueBlock = false;
		$opaqueBlockTag = '';
		$inTable = false;
		$tableDepth = 0;
		$inGallery = false;
		$paragraphBuffer = [];
		$paragraphLineIndices = [];

		$lineCount = count( $lines );

		for ( $i = 0; $i < $lineCount; $i++ ) {
			$line = $lines[$i];

			// Check for opaque block tag opening
			if ( !$inOpaqueBlock && !$inGallery && $this->isOpaqueBlockOpen( $line, $opaqueBlockTag ) ) {
				// Flush any paragraph buffer
				$this->flushParagraph(
					$paragraphBuffer, $paragraphLineIndices, $skeletonLines, $segments, $segmentId
				);

				$inOpaqueBlock = true;
				$skeletonLines[] = $line;

				// Check if block closes on the same line
				if ( $this->isOpaqueBlockClose( $line, $opaqueBlockTag ) ) {
					$inOpaqueBlock = false;
					$opaqueBlockTag = '';
				}
				continue;
			}

			// Inside opaque block — pass through
			if ( $inOpaqueBlock ) {
				$skeletonLines[] = $line;
				if ( $this->isOpaqueBlockClose( $line, $opaqueBlockTag ) ) {
					$inOpaqueBlock = false;
					$opaqueBlockTag = '';
				}
				continue;
			}

			// Gallery handling: <gallery> blocks have translatable captions
			if ( !$inGallery && preg_match( '/<gallery(\s|>|$)/i', $line ) ) {
				$this->flushParagraph(
					$paragraphBuffer, $paragraphLineIndices, $skeletonLines, $segments, $segmentId
				);
				$inGallery = true;
				$skeletonLines[] = $line;

				// Check if gallery closes on the same line
				if ( stripos( $line, '</gallery>' ) !== false ) {
					$inGallery = false;
				}
				continue;
			}

			if ( $inGallery ) {
				if ( stripos( $line, '</gallery>' ) !== false ) {
					$inGallery = false;
					$skeletonLines[] = $line;
					continue;
				}

				$skeletonLines[] = $this->processGalleryLine( $line, $segments, $segmentId );
				continue;
			}

			// Table handling (with nesting support)
			if ( preg_match( '/^\{\|/', $line ) ) {
				$this->flushParagraph(
					$paragraphBuffer, $paragraphLineIndices, $skeletonLines, $segments, $segmentId
				);
				$tableDepth++;
				$inTable = true;
				$skeletonLines[] = $line;
				continue;
			}

			if ( $inTable ) {
				if ( preg_match( '/^\|\}/', $line ) ) {
					$tableDepth--;
					if ( $tableDepth <= 0 ) {
						$inTable = false;
						$tableDepth = 0;
					}
					$skeletonLines[] = $line;
					continue;
				}

				$skeletonLines[] = $this->processTableLine( $line, $segments, $segmentId );
				continue;
			}

			// Heading
			if ( preg_match( '/^(={2,6})\s*(.*?)\s*\1\s*$/', $line, $matches ) ) {
				$this->flushParagraph(
					$paragraphBuffer, $paragraphLineIndices, $skeletonLines, $segments, $segmentId
				);

				$marker = $matches[1];
				$content = $matches[2];
				$level = strlen( $marker );

				if ( trim( $content ) !== '' ) {
					$segment = new Segment( $segmentId, Segment::TYPE_HEADING, $content, $level );
					$segments[] = $segment;
					$skeletonLines[] = "{$marker} {$segment->getMarker()} {$marker}";
					$segmentId++;
				} else {
					$skeletonLines[] = $line;
				}
				continue;
			}

			// Redirect (must be checked before list items since #REDIRECT starts with #)
			if ( preg_match( '/^#REDIRECT\s*\[\[/i', $line ) ) {
				$this->flushParagraph(
					$paragraphBuffer, $paragraphLineIndices, $skeletonLines, $segments, $segmentId
				);
				$skeletonLines[] = $line;
				continue;
			}

			// List item
			if ( preg_match( '/^([*#;:]+)\s*(.*)$/', $line, $matches ) ) {
				$this->flushParagraph(
					$paragraphBuffer, $paragraphLineIndices, $skeletonLines, $segments, $segmentId
				);

				$prefix = $matches[1];
				$content = $matches[2];

				if ( trim( $content ) !== '' ) {
					$segment = new Segment( $segmentId, Segment::TYPE_LIST_ITEM, $content );
					$segments[] = $segment;
					$skeletonLines[] = "{$prefix} {$segment->getMarker()}";
					$segmentId++;
				} else {
					$skeletonLines[] = $line;
				}
				continue;
			}

			// Blank line — paragraph separator (unless inside an open template)
			if ( trim( $line ) === '' ) {
				if ( $this->hasUnbalancedBraces( $paragraphBuffer ) ) {
					// Inside a multi-line template — keep accumulating
					$paragraphBuffer[] = $line;
					$paragraphLineIndices[] = count( $skeletonLines );
					$skeletonLines[] = null;
					continue;
				}
				$this->flushParagraph(
					$paragraphBuffer, $paragraphLineIndices, $skeletonLines, $segments, $segmentId
				);
				$skeletonLines[] = $line;
				continue;
			}

			// Behavior switches (__TOC__, __NOTOC__, etc.)
			if ( preg_match( '/^__[A-Z]+__$/', trim( $line ) ) ) {
				$this->flushParagraph(
					$paragraphBuffer, $paragraphLineIndices, $skeletonLines, $segments, $segmentId
				);
				$skeletonLines[] = $line;
				continue;
			}

			// Regular content line — accumulate as paragraph
			$paragraphBuffer[] = $line;
			$paragraphLineIndices[] = count( $skeletonLines );
			// placeholder, will be filled on flush
			$skeletonLines[] = null;
		}

		// Flush remaining paragraph
		$this->flushParagraph(
			$paragraphBuffer, $paragraphLineIndices, $skeletonLines, $segments, $segmentId
		);

		// Build skeleton string
		$skeleton = implode( "\n", $skeletonLines );

		$this->logger->debug( 'WikitextSegmenter: segmented into {count} segments', [
			'count' => count( $segments ),
			'types' => array_count_values( array_map( static function ( Segment $s ) {
				return $s->getType();
			}, $segments ) )
		] );

		return [ $skeleton, $segments ];
	}

	/**
	 * Flush accumulated paragraph lines into a single segment.
	 *
	 * @param array &$buffer
	 * @param array &$lineIndices
	 * @param array &$skeletonLines
	 * @param Segment[] &$segments
	 * @param int &$segmentId
	 */
	private function flushParagraph(
		array &$buffer, array &$lineIndices, array &$skeletonLines, array &$segments, int &$segmentId
	): void {
		if ( empty( $buffer ) ) {
			return;
		}

		$content = implode( "\n", $buffer );

		if ( trim( $content ) !== '' ) {
			$segment = new Segment( $segmentId, Segment::TYPE_PARAGRAPH, $content );
			$segments[] = $segment;

			// Replace the placeholder lines with the marker on the first line,
			// and remove the rest
			$skeletonLines[$lineIndices[0]] = $segment->getMarker();
			for ( $i = 1, $count = count( $lineIndices ); $i < $count; $i++ ) {
				unset( $skeletonLines[$lineIndices[$i]] );
			}

			$segmentId++;
		} else {
			// Whitespace-only — restore original lines
			foreach ( $lineIndices as $idx => $skIdx ) {
				$skeletonLines[$skIdx] = $buffer[$idx];
			}
		}

		$buffer = [];
		$lineIndices = [];
	}

	/**
	 * Process a single table line and extract cell segments.
	 *
	 * @param string $line
	 * @param Segment[] &$segments
	 * @param int &$segmentId
	 * @return string skeleton line
	 */
	private function processTableLine( string $line, array &$segments, int &$segmentId ): string {
		// Row separator: |-
		if ( preg_match( '/^\|\-/', $line ) ) {
			return $line;
		}

		// Caption: |+ text
		if ( preg_match( '/^\|\+\s*(.*)$/', $line, $matches ) ) {
			$content = $matches[1];
			if ( trim( $content ) !== '' ) {
				$segment = new Segment( $segmentId, Segment::TYPE_TABLE_CAPTION, $content );
				$segments[] = $segment;
				$segmentId++;
				return "|+ {$segment->getMarker()}";
			}
			return $line;
		}

		// Header cells: ! or !!
		if ( preg_match( '/^!\s*(.*)$/', $line, $matches ) ) {
			return '! ' . $this->processTableCells( $matches[1], '!!', $segments, $segmentId );
		}

		// Data cells: | or ||
		if ( preg_match( '/^\|\s*(.*)$/', $line, $matches ) ) {
			return '| ' . $this->processTableCells( $matches[1], '||', $segments, $segmentId );
		}

		return $line;
	}

	/**
	 * Split multi-cell line and create segments for each cell's content.
	 *
	 * @param string $cellsContent
	 * @param string $separator '||' or '!!'
	 * @param Segment[] &$segments
	 * @param int &$segmentId
	 * @return string
	 */
	private function processTableCells(
		string $cellsContent, string $separator, array &$segments, int &$segmentId
	): string {
		$cells = explode( $separator, $cellsContent );
		$resultParts = [];

		foreach ( $cells as $cell ) {
			$cell = trim( $cell );
			$resultParts[] = $this->processOneCell( $cell, $segments, $segmentId );
		}

		return implode( " {$separator} ", $resultParts );
	}

	/**
	 * Process a single table cell — separate attributes from content.
	 *
	 * @param string $cell
	 * @param Segment[] &$segments
	 * @param int &$segmentId
	 * @return string
	 */
	private function processOneCell( string $cell, array &$segments, int &$segmentId ): string {
		if ( trim( $cell ) === '' ) {
			return $cell;
		}

		// Check for cell attributes: "attributes | content"
		// Attributes typically contain '=' (e.g., style="...", class="...")
		// But be careful — wikitext links also contain '|', so only split on single '|'
		// that's preceded by something containing '='
		$content = $cell;
		$attrPrefix = '';

		if ( preg_match( '/^([^|]*=[^|]*)\|\s*(.*)$/s', $cell, $attrMatch ) ) {
			$attrPrefix = $attrMatch[1] . '| ';
			$content = $attrMatch[2];
		}

		if ( trim( $content ) === '' ) {
			return $cell;
		}

		$segment = new Segment( $segmentId, Segment::TYPE_TABLE_CELL, $content );
		$segments[] = $segment;
		$segmentId++;

		return $attrPrefix . $segment->getMarker();
	}

	/**
	 * Process a gallery line: extract the caption (after the first pipe) as a translatable segment.
	 * Gallery lines look like "File:Name.png|Caption text" or just "File:Name.png".
	 *
	 * Inside a gallery block, every non-empty line is expected to be a file reference
	 * (optionally followed by "|Caption"). We detect file references by looking for
	 * a word-character prefix followed by ":" (matching any language's file namespace).
	 *
	 * @param string $line
	 * @param Segment[] &$segments
	 * @param int &$segmentId
	 * @return string skeleton line
	 */
	private function processGalleryLine( string $line, array &$segments, int &$segmentId ): string {
		$trimmed = trim( $line );

		// Empty lines or lines without a namespace-like prefix (Word:...) — pass through
		if ( $trimmed === '' || !preg_match( '/^\w+:/u', $trimmed ) ) {
			return $line;
		}

		// Split on the first pipe to separate file reference from caption
		$pipePos = strpos( $trimmed, '|' );
		if ( $pipePos === false ) {
			// No caption — pass through
			return $line;
		}

		$fileRef = substr( $trimmed, 0, $pipePos );
		$caption = substr( $trimmed, $pipePos + 1 );

		if ( trim( $caption ) === '' ) {
			return $line;
		}

		$segment = new Segment( $segmentId, Segment::TYPE_GALLERY_CAPTION, $caption );
		$segments[] = $segment;
		$segmentId++;

		return "{$fileRef}|{$segment->getMarker()}";
	}

	/**
	 * @param string $line
	 * @param string &$tag
	 * @return bool
	 */
	private function isOpaqueBlockOpen( string $line, string &$tag ): bool {
		foreach ( self::OPAQUE_BLOCK_TAGS as $blockTag ) {
			if ( preg_match( '/<' . preg_quote( $blockTag, '/' ) . '(\s|>|$)/i', $line ) ) {
				$tag = $blockTag;
				return true;
			}
		}
		return false;
	}

	/**
	 * @param string $line
	 * @param string $tag
	 * @return bool
	 */
	private function isOpaqueBlockClose( string $line, string $tag ): bool {
		return stripos( $line, "</$tag>" ) !== false;
	}

	/**
	 * Check if the accumulated paragraph buffer has unclosed {{ braces,
	 * indicating we're inside a multi-line template and should not flush.
	 *
	 * @param array $buffer
	 * @return bool
	 */
	private function hasUnbalancedBraces( array $buffer ): bool {
		if ( empty( $buffer ) ) {
			return false;
		}
		$text = implode( "\n", $buffer );
		$depth = 0;
		$len = strlen( $text );
		for ( $i = 0; $i < $len - 1; $i++ ) {
			if ( $text[$i] === '{' && $text[$i + 1] === '{' ) {
				$depth++;
				$i++;
			} elseif ( $text[$i] === '}' && $text[$i + 1] === '}' ) {
				$depth--;
				$i++;
			}
		}
		return $depth > 0;
	}
}
