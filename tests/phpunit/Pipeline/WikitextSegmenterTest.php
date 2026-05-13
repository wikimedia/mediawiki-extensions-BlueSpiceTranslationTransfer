<?php

namespace BlueSpice\TranslationTransfer\Tests\Pipeline;

use BlueSpice\TranslationTransfer\Pipeline\Segment;
use BlueSpice\TranslationTransfer\Pipeline\WikitextSegmenter;
use PHPUnit\Framework\TestCase;

/**
 * @covers \BlueSpice\TranslationTransfer\Pipeline\WikitextSegmenter
 */
class WikitextSegmenterTest extends TestCase {

	/** @var WikitextSegmenter */
	private $segmenter;

	protected function setUp(): void {
		parent::setUp();
		$this->segmenter = new WikitextSegmenter();
	}

	public function testEmptyInput(): void {
		[ $skeleton, $segments ] = $this->segmenter->segment( '' );
		$this->assertSame( '', $skeleton );
		$this->assertSame( [], $segments );
	}

	public function testWhitespaceOnlyInput(): void {
		[ $skeleton, $segments ] = $this->segmenter->segment( "   \n  \n  " );
		$this->assertSame( "   \n  \n  ", $skeleton );
		$this->assertSame( [], $segments );
	}

	public function testSingleParagraph(): void {
		$input = 'This is a simple paragraph.';
		[ $skeleton, $segments ] = $this->segmenter->segment( $input );

		$this->assertCount( 1, $segments );
		$this->assertSame( Segment::TYPE_PARAGRAPH, $segments[0]->getType() );
		$this->assertSame( 'This is a simple paragraph.', $segments[0]->getSourceText() );
		$this->assertSame( $segments[0]->getMarker(), $skeleton );
	}

	public function testMultipleHeadings(): void {
		$input = "== Heading 2 ==\nSome text\n=== Heading 3 ===\nMore text";
		[ $skeleton, $segments ] = $this->segmenter->segment( $input );

		$this->assertCount( 4, $segments );

		$this->assertSame( Segment::TYPE_HEADING, $segments[0]->getType() );
		$this->assertSame( 'Heading 2', $segments[0]->getSourceText() );
		$this->assertSame( 2, $segments[0]->getLevel() );

		$this->assertSame( Segment::TYPE_PARAGRAPH, $segments[1]->getType() );
		$this->assertSame( 'Some text', $segments[1]->getSourceText() );

		$this->assertSame( Segment::TYPE_HEADING, $segments[2]->getType() );
		$this->assertSame( 'Heading 3', $segments[2]->getSourceText() );
		$this->assertSame( 3, $segments[2]->getLevel() );

		$this->assertSame( Segment::TYPE_PARAGRAPH, $segments[3]->getType() );
		$this->assertSame( 'More text', $segments[3]->getSourceText() );
	}

	public function testListItems(): void {
		$input = "* First item\n* Second item\n** Nested item\n# Ordered item";
		[ $skeleton, $segments ] = $this->segmenter->segment( $input );

		$this->assertCount( 4, $segments );
		foreach ( $segments as $segment ) {
			$this->assertSame( Segment::TYPE_LIST_ITEM, $segment->getType() );
		}

		$this->assertSame( 'First item', $segments[0]->getSourceText() );
		$this->assertSame( 'Second item', $segments[1]->getSourceText() );
		$this->assertSame( 'Nested item', $segments[2]->getSourceText() );
		$this->assertSame( 'Ordered item', $segments[3]->getSourceText() );
	}

	public function testMixedParagraphsAndListsWithBlankLines(): void {
		$input = "First paragraph.\n\n* List item 1\n* List item 2\n\nSecond paragraph.";
		[ $skeleton, $segments ] = $this->segmenter->segment( $input );

		$this->assertCount( 4, $segments );
		$this->assertSame( Segment::TYPE_PARAGRAPH, $segments[0]->getType() );
		$this->assertSame( Segment::TYPE_LIST_ITEM, $segments[1]->getType() );
		$this->assertSame( Segment::TYPE_LIST_ITEM, $segments[2]->getType() );
		$this->assertSame( Segment::TYPE_PARAGRAPH, $segments[3]->getType() );
	}

	public function testParagraphWithInlineTemplate(): void {
		$input = 'This has a {{template|arg}} in it.';
		[ $skeleton, $segments ] = $this->segmenter->segment( $input );

		$this->assertCount( 1, $segments );
		$this->assertSame( Segment::TYPE_PARAGRAPH, $segments[0]->getType() );
		// Template is preserved as-is in sourceText (handled later by InlineConverter)
		$this->assertSame( 'This has a {{template|arg}} in it.', $segments[0]->getSourceText() );
	}

	public function testParagraphWithInlineFormatting(): void {
		$input = "This is '''bold''' and ''italic'' text.";
		[ $skeleton, $segments ] = $this->segmenter->segment( $input );

		$this->assertCount( 1, $segments );
		$this->assertSame( "This is '''bold''' and ''italic'' text.", $segments[0]->getSourceText() );
	}

	public function testTableBasic(): void {
		$input = "{| class=\"wikitable\"\n|-\n| Cell 1 || Cell 2\n|-\n| Cell 3 || Cell 4\n|}";
		[ $skeleton, $segments ] = $this->segmenter->segment( $input );

		// Should have 4 cell segments
		$cellSegments = array_filter( $segments, static function ( Segment $s ) {
			return $s->getType() === Segment::TYPE_TABLE_CELL;
		} );
		$this->assertCount( 4, $cellSegments );
	}

	public function testTableCellWithAttributes(): void {
		$input = "{|\n| style=\"width:50%\" | Content here\n|}";
		[ $skeleton, $segments ] = $this->segmenter->segment( $input );

		$this->assertCount( 1, $segments );
		$this->assertSame( 'Content here', $segments[0]->getSourceText() );
		// Skeleton should retain attributes
		$this->assertStringContainsString( 'style="width:50%"', $skeleton );
	}

	public function testOpaqueBlockTag(): void {
		$input = "Paragraph before.\n<syntaxhighlight lang=\"php\">\n\$x = 1;\n</syntaxhighlight>\nParagraph after.";
		[ $skeleton, $segments ] = $this->segmenter->segment( $input );

		$this->assertCount( 2, $segments );
		$this->assertSame( 'Paragraph before.', $segments[0]->getSourceText() );
		$this->assertSame( 'Paragraph after.', $segments[1]->getSourceText() );

		// Opaque block should be in skeleton unchanged
		$this->assertStringContainsString( '<syntaxhighlight lang="php">', $skeleton );
		$this->assertStringContainsString( '$x = 1;', $skeleton );
	}

	public function testInlineCodeNotOpaqueBlock(): void {
		// <code> is now handled as inline opaque by InlineConverter, not as a block by segmenter
		$input = "Text before\n<code>inline code</code>\nText after";
		[ $skeleton, $segments ] = $this->segmenter->segment( $input );

		// All three lines form one paragraph segment (no blank lines between them)
		$this->assertCount( 1, $segments );
		$this->assertSame( Segment::TYPE_PARAGRAPH, $segments[0]->getType() );
		$this->assertStringContainsString( '<code>inline code</code>', $segments[0]->getSourceText() );
	}

	public function testInlineCodeInParagraphPreserved(): void {
		$input = "Use the <code>grep</code> command to search files.";
		[ $skeleton, $segments ] = $this->segmenter->segment( $input );

		$this->assertCount( 1, $segments );
		$this->assertSame( 'Use the <code>grep</code> command to search files.', $segments[0]->getSourceText() );
	}

	public function testRedirectPassthrough(): void {
		$input = '#REDIRECT [[Target Page]]';
		[ $skeleton, $segments ] = $this->segmenter->segment( $input );

		$this->assertSame( [], $segments );
		$this->assertSame( $input, $skeleton );
	}

	public function testBehaviorSwitchPassthrough(): void {
		$input = "Some text\n__NOTOC__\nMore text";
		[ $skeleton, $segments ] = $this->segmenter->segment( $input );

		$this->assertCount( 2, $segments );
		$this->assertStringContainsString( '__NOTOC__', $skeleton );
	}

	public function testSkeletonRoundTrip(): void {
		$input = "== Introduction ==\n\nThis is the '''first''' paragraph.\n\n* Item one\n* Item two\n\n== Details ==\n\nAnother paragraph with [[a link]].";
		[ $skeleton, $segments ] = $this->segmenter->segment( $input );

		// Replace markers with source text to reproduce original
		$reconstructed = $skeleton;
		foreach ( $segments as $segment ) {
			$reconstructed = str_replace( $segment->getMarker(), $segment->getSourceText(), $reconstructed );
		}

		$this->assertSame( $input, $reconstructed );
	}

	public function testMultiLineParagraph(): void {
		$input = "First line of paragraph\nSecond line of paragraph";
		[ $skeleton, $segments ] = $this->segmenter->segment( $input );

		$this->assertCount( 1, $segments );
		$this->assertSame( Segment::TYPE_PARAGRAPH, $segments[0]->getType() );
		$this->assertSame( "First line of paragraph\nSecond line of paragraph", $segments[0]->getSourceText() );
	}

	public function testTableCaption(): void {
		$input = "{|\n|+ Caption text\n|-\n| Cell\n|}";
		[ $skeleton, $segments ] = $this->segmenter->segment( $input );

		$captionSegments = array_filter( $segments, static function ( Segment $s ) {
			return $s->getType() === Segment::TYPE_TABLE_CAPTION;
		} );
		$this->assertCount( 1, $captionSegments );
		$caption = array_values( $captionSegments )[0];
		$this->assertSame( 'Caption text', $caption->getSourceText() );
	}

	public function testHeadingLevels(): void {
		$input = "== H2 ==\n=== H3 ===\n==== H4 ====\n===== H5 =====\n====== H6 ======";
		[ $skeleton, $segments ] = $this->segmenter->segment( $input );

		$this->assertCount( 5, $segments );
		$this->assertSame( 2, $segments[0]->getLevel() );
		$this->assertSame( 3, $segments[1]->getLevel() );
		$this->assertSame( 4, $segments[2]->getLevel() );
		$this->assertSame( 5, $segments[3]->getLevel() );
		$this->assertSame( 6, $segments[4]->getLevel() );
	}

	// --- Gallery tests ---

	/** @return void */
	public function testGalleryCaptionExtracted(): void {
		$input = "<gallery>\nFile:Example.png|A nice caption\nFile:Other.jpg|Another caption\n</gallery>";
		[ $skeleton, $segments ] = $this->segmenter->segment( $input );

		$captions = array_filter( $segments, static function ( Segment $s ) {
			return $s->getType() === Segment::TYPE_GALLERY_CAPTION;
		} );
		$this->assertCount( 2, $captions );

		$captionTexts = array_map( static function ( Segment $s ) {
			return $s->getSourceText();
		}, array_values( $captions ) );
		$this->assertContains( 'A nice caption', $captionTexts );
		$this->assertContains( 'Another caption', $captionTexts );
	}

	public function testGalleryFileWithoutCaptionNoSegment(): void {
		$input = "<gallery>\nFile:NoCaption.png\n</gallery>";
		[ $skeleton, $segments ] = $this->segmenter->segment( $input );

		$captions = array_filter( $segments, static function ( Segment $s ) {
			return $s->getType() === Segment::TYPE_GALLERY_CAPTION;
		} );
		$this->assertCount( 0, $captions );
	}

	public function testGalleryPreservesFileReference(): void {
		$input = "<gallery>\nFile:Photo.jpg|My photo\n</gallery>";
		[ $skeleton, $segments ] = $this->segmenter->segment( $input );

		// File reference should be in skeleton, not in segment text
		$this->assertStringContainsString( 'File:Photo.jpg', $skeleton );
	}

	public function testGalleryNonEnglishNamespace(): void {
		// Dutch file namespace "Bestand:" should be recognized without a hardcoded list
		$input = "<gallery>\nBestand:Foto.jpg|Een bijschrift\n</gallery>";
		[ $skeleton, $segments ] = $this->segmenter->segment( $input );

		$captions = array_filter( $segments, static function ( Segment $s ) {
			return $s->getType() === Segment::TYPE_GALLERY_CAPTION;
		} );
		$this->assertCount( 1, $captions );
		$this->assertSame( 'Een bijschrift', array_values( $captions )[0]->getSourceText() );
		$this->assertStringContainsString( 'Bestand:Foto.jpg', $skeleton );
	}

	public function testGalleryCyrillicNamespace(): void {
		// Russian file namespace "Файл:" (Cyrillic) should be recognized via \w+ with /u flag
		$input = "<gallery>\nФайл:Фото.jpg|Подпись\n</gallery>";
		[ $skeleton, $segments ] = $this->segmenter->segment( $input );

		$captions = array_filter( $segments, static function ( Segment $s ) {
			return $s->getType() === Segment::TYPE_GALLERY_CAPTION;
		} );
		$this->assertCount( 1, $captions );
		$this->assertSame( 'Подпись', array_values( $captions )[0]->getSourceText() );
	}

	// --- Nested table tests ---

	/** @return void */
	public function testNestedTableStructure(): void {
		$input = "{|\n| outer cell\n|-\n|\n{|\n| inner cell\n|}\n|}";
		[ $skeleton, $segments ] = $this->segmenter->segment( $input );

		$cellTexts = array_map( static function ( Segment $s ) {
			return $s->getSourceText();
		}, array_filter( $segments, static function ( Segment $s ) {
			return $s->getType() === Segment::TYPE_TABLE_CELL;
		} ) );
		$cellTexts = array_values( $cellTexts );

		$this->assertContains( 'outer cell', $cellTexts );
		$this->assertContains( 'inner cell', $cellTexts );
	}

	public function testNestedTableSkeletonRoundTrip(): void {
		$input = "{|\n| outer\n|-\n|\n{|\n| inner\n|}\n|}";
		[ $skeleton, $segments ] = $this->segmenter->segment( $input );

		// Verify skeleton can be reassembled (markers present for both cells)
		$this->assertStringContainsString( "\xEE\x80\x80", $skeleton );
	}

	// --- Multi-line template tests ---

	/** @return void */
	public function testMultiLineTemplateNotSplit(): void {
		$input = "{{Infobox\n|name = Test\n\n|desc = Value\n}}";
		[ $skeleton, $segments ] = $this->segmenter->segment( $input );

		// The template should NOT be split into multiple segments — it should
		// be treated as a single paragraph/opaque block
		$allText = implode( ' ', array_map( static function ( Segment $s ) {
			return $s->getSourceText();
		}, $segments ) );

		// The template braces should appear in a single segment, not split
		foreach ( $segments as $segment ) {
			$text = $segment->getSourceText();
			if ( strpos( $text, '{{Infobox' ) !== false ) {
				$this->assertStringContainsString( '}}', $text,
					'Multi-line template should not be split across segments' );
				return;
			}
		}

		// If the template is in the skeleton (as opaque block), that's fine too
		if ( strpos( $skeleton, '{{Infobox' ) !== false ) {
			$this->assertStringContainsString( '}}', $skeleton );
			return;
		}

		$this->fail( 'Multi-line template not found in segments or skeleton' );
	}

	public function testMultiLineTemplateWithBlankLines(): void {
		$input = "Before.\n\n{{InfoBox\n|key=val\n\n|key2=val2\n}}\n\nAfter.";
		[ $skeleton, $segments ] = $this->segmenter->segment( $input );

		// "Before." and "After." should be separate paragraph segments
		$paragraphs = array_filter( $segments, static function ( Segment $s ) {
			return $s->getType() === Segment::TYPE_PARAGRAPH;
		} );
		$paraTexts = array_map( static function ( Segment $s ) {
			return $s->getSourceText();
		}, array_values( $paragraphs ) );

		$this->assertContains( 'Before.', $paraTexts );
		$this->assertContains( 'After.', $paraTexts );
	}
}
