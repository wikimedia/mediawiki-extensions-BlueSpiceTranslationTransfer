<?php

namespace BlueSpice\TranslationTransfer\Tests\Pipeline;

use BlueSpice\TranslationTransfer\Pipeline\InlineConverter;
use BlueSpice\TranslationTransfer\Pipeline\Segment;
use BlueSpice\TranslationTransfer\Pipeline\SkeletonAssembler;
use BlueSpice\TranslationTransfer\Pipeline\WikitextSegmenter;
use PHPUnit\Framework\TestCase;

/**
 * Integration test for the full pipeline (segmenter → inline converter → assembler).
 * DeepL calls are simulated by uppercasing the prose in HTML.
 *
 * @covers \BlueSpice\TranslationTransfer\Pipeline\WikitextSegmenter
 * @covers \BlueSpice\TranslationTransfer\Pipeline\InlineConverter
 * @covers \BlueSpice\TranslationTransfer\Pipeline\SkeletonAssembler
 */
class PipelineIntegrationTest extends TestCase {

	/**
	 * Simulate DeepL: uppercase all text content, leave HTML tags unchanged.
	 *
	 * @param string $html
	 * @return string
	 */
	private function simulateDeepL( string $html ): string {
		// Capture group is required for PREG_SPLIT_DELIM_CAPTURE to keep tags in the output
		$parts = preg_split( '/(<[^>]+>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		$result = '';
		foreach ( $parts as $part ) {
			if ( isset( $part[0] ) && $part[0] === '<' && substr( $part, -1 ) === '>' ) {
				$result .= $part;
			} else {
				$result .= mb_strtoupper( $part );
			}
		}
		return $result;
	}

	/**
	 * Translate a segment using the simulated pipeline.
	 *
	 * @param Segment $segment
	 * @param InlineConverter $converter
	 */
	private function translateSegment( Segment $segment, InlineConverter $converter ): void {
		if ( !$segment->isTranslatable() ) {
			$segment->setTranslatedText( $segment->getSourceText() );
			return;
		}

		$converter->reset();
		$html = $converter->wikitextToHtml( $segment->getSourceText() );

		// Skip if no translatable prose remains
		if ( trim( strip_tags( $html ) ) === '' ) {
			$segment->setTranslatedText( $segment->getSourceText() );
			return;
		}

		$linkMap = $converter->getLinkMap();
		$opaqueMap = $converter->getOpaqueMap();

		$translatedHtml = $this->simulateDeepL( $html );

		$converter->reset();
		$converter->restoreMaps( $linkMap, $opaqueMap );
		$segment->setTranslatedText( $converter->htmlToWikitext( $translatedHtml ) );
	}

	public function testFullPipelinePreservesStructure(): void {
		$input = implode( "\n", [
			"== Introduction ==",
			"",
			"This is a '''bold''' paragraph with [[Link|a link]].",
			"",
			"* First item",
			"* Second item",
			"",
			"== Details ==",
			"",
			"Another paragraph."
		] );

		$expected = implode( "\n", [
			"== INTRODUCTION ==",
			"",
			"THIS IS A '''BOLD''' PARAGRAPH WITH [[Link|A LINK]].",
			"",
			"* FIRST ITEM",
			"* SECOND ITEM",
			"",
			"== DETAILS ==",
			"",
			"ANOTHER PARAGRAPH."
		] );

		$result = $this->runPipeline( $input );
		$this->assertSame( $expected, $result );
	}

	public function testTableStructurePreserved(): void {
		$input = implode( "\n", [
			'{| class="wikitable"',
			'|-',
			'! Header A !! Header B',
			'|-',
			'| Cell one || Cell two',
			'|}',
		] );

		$expected = implode( "\n", [
			'{| class="wikitable"',
			'|-',
			'! HEADER A !! HEADER B',
			'|-',
			'| CELL ONE || CELL TWO',
			'|}',
		] );

		$result = $this->runPipeline( $input );
		$this->assertSame( $expected, $result );
	}

	public function testOpaqueBlocksUntouched(): void {
		$input = implode( "\n", [
			"Some text before.",
			"<syntaxhighlight lang=\"php\">",
			"\$variable = 'hello';",
			"</syntaxhighlight>",
			"Some text after."
		] );

		$expected = implode( "\n", [
			"SOME TEXT BEFORE.",
			"<syntaxhighlight lang=\"php\">",
			"\$variable = 'hello';",
			"</syntaxhighlight>",
			"SOME TEXT AFTER."
		] );

		$result = $this->runPipeline( $input );
		$this->assertSame( $expected, $result );
	}

	public function testTemplatePreservedInParagraph(): void {
		$input = "Please see {{InfoBox|title=Help}} for more info.";
		$expected = "PLEASE SEE {{InfoBox|title=Help}} FOR MORE INFO.";

		$result = $this->runPipeline( $input );
		$this->assertSame( $expected, $result );
	}

	public function testRoundTripWithNoTranslation(): void {
		$input = "== Title ==\n\nA paragraph with '''bold'''.\n\n* Item 1\n* Item 2";

		$segmenter = new WikitextSegmenter();
		$assembler = new SkeletonAssembler();

		[ $skeleton, $segments ] = $segmenter->segment( $input );

		// Don't translate — use source text as fallback
		$result = $assembler->assemble( $skeleton, $segments );

		$this->assertSame( $input, $result );
	}

	public function testGalleryCaptionTranslated(): void {
		$input = "<gallery>\nFile:Photo.jpg|A nice photo\nFile:Diagram.png|Technical diagram\n</gallery>";
		$result = $this->runPipeline( $input );

		// Captions should be uppercased (translated), file refs preserved
		$this->assertStringContainsString( 'File:Photo.jpg', $result );
		$this->assertStringContainsString( 'File:Diagram.png', $result );
		$this->assertStringContainsString( 'A NICE PHOTO', $result );
		$this->assertStringContainsString( 'TECHNICAL DIAGRAM', $result );
	}

	public function testInlineCodePreservedInParagraph(): void {
		$input = "Use the <code>git status</code> command to check.";
		$result = $this->runPipeline( $input );

		// "git status" inside <code> should be preserved, surrounding text uppercased
		$this->assertStringContainsString( '<code>git status</code>', $result );
		$this->assertStringContainsString( 'USE THE', $result );
	}

	public function testRefTagPreservedInParagraph(): void {
		$input = "A known fact.<ref>Source: Wikipedia</ref> More text.";
		$result = $this->runPipeline( $input );

		$this->assertStringContainsString( '<ref>Source: Wikipedia</ref>', $result );
		$this->assertStringContainsString( 'A KNOWN FACT.', $result );
		$this->assertStringContainsString( 'MORE TEXT.', $result );
	}

	public function testIgnoreTagContentPreservedTagsStripped(): void {
		$input = "Translate this <deepl:ignore>API endpoint</deepl:ignore> word.";
		$result = $this->runPipeline( $input );

		$this->assertStringContainsString( 'API endpoint', $result );
		$this->assertStringNotContainsString( '<deepl:ignore>', $result );
		$this->assertStringContainsString( 'TRANSLATE THIS', $result );
	}

	/**
	 * Run the full pipeline with simulated DeepL (uppercase transform).
	 *
	 * @param string $input
	 * @return string
	 */
	private function runPipeline( string $input ): string {
		$segmenter = new WikitextSegmenter();
		$assembler = new SkeletonAssembler();
		$converter = new InlineConverter();

		[ $skeleton, $segments ] = $segmenter->segment( $input );

		foreach ( $segments as $segment ) {
			$this->translateSegment( $segment, $converter );
		}

		return $assembler->assemble( $skeleton, $segments );
	}
}
