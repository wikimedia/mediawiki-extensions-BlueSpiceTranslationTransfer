<?php

namespace BlueSpice\TranslationTransfer\Tests\Pipeline;

use BlueSpice\TranslationTransfer\Pipeline\InlineConverter;
use PHPUnit\Framework\TestCase;

/**
 * @covers \BlueSpice\TranslationTransfer\Pipeline\InlineConverter
 */
class InlineConverterTest extends TestCase {

	/** @var InlineConverter */
	private $converter;

	protected function setUp(): void {
		parent::setUp();
		$this->converter = new InlineConverter();
	}

	public function testBoldRoundTrip(): void {
		$input = "This is '''bold''' text.";
		$html = $this->converter->wikitextToHtml( $input );

		$this->assertStringContainsString( '<b>bold</b>', $html );

		$result = $this->converter->htmlToWikitext( $html );
		$this->assertSame( $input, $result );
	}

	public function testItalicRoundTrip(): void {
		$input = "This is ''italic'' text.";
		$html = $this->converter->wikitextToHtml( $input );

		$this->assertStringContainsString( '<i>italic</i>', $html );

		$result = $this->converter->htmlToWikitext( $html );
		$this->assertSame( $input, $result );
	}

	public function testBoldItalicRoundTrip(): void {
		$input = "This is '''''bold italic''''' text.";
		$html = $this->converter->wikitextToHtml( $input );

		$this->assertStringContainsString( '<b><i>bold italic</i></b>', $html );

		$result = $this->converter->htmlToWikitext( $html );
		$this->assertSame( $input, $result );
	}

	public function testInternalLinkWithLabelRoundTrip(): void {
		$input = "See [[Main Page|the main page]] for details.";
		$html = $this->converter->wikitextToHtml( $input );

		$this->assertMatchesRegularExpression( '/<a data-link-id="\d+">the main page<\/a>/', $html );

		$result = $this->converter->htmlToWikitext( $html );
		$this->assertSame( $input, $result );
	}

	public function testInternalLinkWithoutLabelRoundTrip(): void {
		$input = "See [[Main Page]] for details.";
		$html = $this->converter->wikitextToHtml( $input );

		// No-pipe link: tag should be empty so DeepL won't translate the title
		$this->assertMatchesRegularExpression( '/<a data-link-id="\d+"><\/a>/', $html );

		$result = $this->converter->htmlToWikitext( $html );
		$this->assertSame( $input, $result );
	}

	public function testInternalLinkLabelChangedByTranslation(): void {
		$input = "See [[Main Page|the main page]] for details.";
		$html = $this->converter->wikitextToHtml( $input );

		// Simulate DeepL changing the label
		$translatedHtml = str_replace( 'the main page', 'die Hauptseite', $html );

		$result = $this->converter->htmlToWikitext( $translatedHtml );
		$this->assertSame( "See [[Main Page|die Hauptseite]] for details.", $result );
	}

	public function testExternalLinkWithLabelRoundTrip(): void {
		$input = "Visit [https://example.com Example Site] today.";
		$html = $this->converter->wikitextToHtml( $input );

		$this->assertMatchesRegularExpression( '/<a data-link-id="\d+">Example Site<\/a>/', $html );

		$result = $this->converter->htmlToWikitext( $html );
		$this->assertSame( $input, $result );
	}

	public function testExternalLinkWithoutLabel(): void {
		$input = "Visit [https://example.com] today.";
		$html = $this->converter->wikitextToHtml( $input );

		// Should be opaque
		$this->assertMatchesRegularExpression( '/<span data-opaque-id="\d+"><\/span>/', $html );

		$result = $this->converter->htmlToWikitext( $html );
		$this->assertSame( $input, $result );
	}

	public function testTemplateOpaque(): void {
		$input = "Before {{MyTemplate|arg=value}} after.";
		$html = $this->converter->wikitextToHtml( $input );

		$this->assertStringNotContainsString( '{{', $html );
		$this->assertMatchesRegularExpression( '/<span data-opaque-id="\d+"><\/span>/', $html );

		$result = $this->converter->htmlToWikitext( $html );
		$this->assertSame( $input, $result );
	}

	public function testNestedTemplateOpaque(): void {
		$input = "Before {{Outer|{{Inner|val}}}} after.";
		$html = $this->converter->wikitextToHtml( $input );

		$this->assertStringNotContainsString( '{{', $html );

		$result = $this->converter->htmlToWikitext( $html );
		$this->assertSame( $input, $result );
	}

	public function testFileLinkOpaque(): void {
		$input = "See [[File:Example.png|thumb|200px|Caption text]] here.";
		$html = $this->converter->wikitextToHtml( $input );

		$this->assertStringNotContainsString( '[[File:', $html );
		$this->assertMatchesRegularExpression( '/<span data-opaque-id="\d+"><\/span>/', $html );

		$result = $this->converter->htmlToWikitext( $html );
		$this->assertSame( $input, $result );
	}

	public function testHtmlSpecialCharsEncoded(): void {
		$input = "A & B < C > D";
		$html = $this->converter->wikitextToHtml( $input );

		$this->assertStringContainsString( '&amp;', $html );
		$this->assertStringContainsString( '&lt;', $html );
		$this->assertStringContainsString( '&gt;', $html );

		$result = $this->converter->htmlToWikitext( $html );
		$this->assertSame( $input, $result );
	}

	public function testMixedInlineFormatting(): void {
		$input = "This is '''bold''' and ''italic'' with [[Link|label]].";
		$html = $this->converter->wikitextToHtml( $input );

		$this->assertStringContainsString( '<b>bold</b>', $html );
		$this->assertStringContainsString( '<i>italic</i>', $html );
		$this->assertMatchesRegularExpression( '/<a data-link-id="\d+">label<\/a>/', $html );

		$result = $this->converter->htmlToWikitext( $html );
		$this->assertSame( $input, $result );
	}

	public function testDeepLReturnsStrongAndEm(): void {
		// DeepL might return <strong> instead of <b> and <em> instead of <i>
		$html = "This is <strong>bold</strong> and <em>italic</em> text.";

		$result = $this->converter->htmlToWikitext( $html );
		$this->assertSame( "This is '''bold''' and ''italic'' text.", $result );
	}

	public function testResetClearsState(): void {
		$input1 = "[[Page1|Label1]]";
		$this->converter->wikitextToHtml( $input1 );
		$this->assertNotEmpty( $this->converter->getLinkMap() );

		$this->converter->reset();
		$this->assertSame( [], $this->converter->getLinkMap() );
		$this->assertSame( [], $this->converter->getOpaqueMap() );
	}

	public function testRestoreMaps(): void {
		$linkMap = [ 0 => 'Target Page' ];
		$opaqueMap = [ 0 => '{{Template}}' ];

		$this->converter->restoreMaps( $linkMap, $opaqueMap );

		$html = '<a data-link-id="0">Translated Label</a> and <span data-opaque-id="0"></span>';
		$result = $this->converter->htmlToWikitext( $html );

		$this->assertSame( '[[Target Page|Translated Label]] and {{Template}}', $result );
	}

	// --- Inline opaque tag tests ---

	/** @return void */
	public function testInlineCodeRoundTrip(): void {
		$input = "Use the <code>git status</code> command.";
		$html = $this->converter->wikitextToHtml( $input );

		// Code tag should be replaced with opaque placeholder
		$this->assertStringNotContainsString( '<code>', $html );
		$this->assertMatchesRegularExpression( '/<span data-opaque-id="\d+"><\/span>/', $html );

		$result = $this->converter->htmlToWikitext( $html );
		$this->assertSame( $input, $result );
	}

	public function testInlineNowikiRoundTrip(): void {
		$input = "Show literal <nowiki>'''bold'''</nowiki> markup.";
		$html = $this->converter->wikitextToHtml( $input );

		$this->assertStringNotContainsString( '<nowiki>', $html );

		$result = $this->converter->htmlToWikitext( $html );
		$this->assertSame( $input, $result );
	}

	public function testInlinePreRoundTrip(): void {
		$input = "Check <pre>example code</pre> here.";
		$html = $this->converter->wikitextToHtml( $input );

		$this->assertStringNotContainsString( '<pre>', $html );

		$result = $this->converter->htmlToWikitext( $html );
		$this->assertSame( $input, $result );
	}

	// --- Ref tag tests ---

	/** @return void */
	public function testRefTagRoundTrip(): void {
		$input = "Some fact.<ref>Source: Wikipedia</ref> More text.";
		$html = $this->converter->wikitextToHtml( $input );

		$this->assertStringNotContainsString( '<ref>', $html );
		$this->assertMatchesRegularExpression( '/<span data-opaque-id="\d+"><\/span>/', $html );

		$result = $this->converter->htmlToWikitext( $html );
		$this->assertSame( $input, $result );
	}

	public function testSelfClosingRefTagRoundTrip(): void {
		$input = 'Citation needed.<ref name="source1" /> End.';
		$html = $this->converter->wikitextToHtml( $input );

		$this->assertStringNotContainsString( '<ref', $html );

		$result = $this->converter->htmlToWikitext( $html );
		$this->assertSame( $input, $result );
	}

	public function testNamedRefTagRoundTrip(): void {
		$input = 'Statement.<ref name="src">Full citation text</ref> More.';
		$html = $this->converter->wikitextToHtml( $input );

		$this->assertStringNotContainsString( '<ref', $html );

		$result = $this->converter->htmlToWikitext( $html );
		$this->assertSame( $input, $result );
	}

	// --- Ignore tag tests ---

	/** @return void */
	public function testDeeplIgnoreTagRoundTrip(): void {
		$input = "Translate this <deepl:ignore>but not this</deepl:ignore> and this.";
		$html = $this->converter->wikitextToHtml( $input );

		$this->assertStringNotContainsString( '<deepl:ignore>', $html );
		$this->assertStringNotContainsString( 'but not this', $html );

		$result = $this->converter->htmlToWikitext( $html );
		// Ignore tags are stripped — only inner content remains
		$this->assertSame( "Translate this but not this and this.", $result );
	}

	public function testTranslationIgnoreTagRoundTrip(): void {
		$input = "Text <translation:ignore>API endpoint</translation:ignore> more.";
		$html = $this->converter->wikitextToHtml( $input );

		$this->assertStringNotContainsString( '<translation:ignore>', $html );
		$this->assertStringNotContainsString( 'API endpoint', $html );

		$result = $this->converter->htmlToWikitext( $html );
		$this->assertSame( "Text API endpoint more.", $result );
	}

	public function testMultipleIgnoreTagsRoundTrip(): void {
		$input = "<deepl:ignore>Keep1</deepl:ignore> translate <translation:ignore>Keep2</translation:ignore>.";
		$html = $this->converter->wikitextToHtml( $input );

		$this->assertStringNotContainsString( 'Keep1', $html );
		$this->assertStringNotContainsString( 'Keep2', $html );

		$result = $this->converter->htmlToWikitext( $html );
		$this->assertSame( "Keep1 translate Keep2.", $result );
	}

	// --- HTML entity preservation ---

	/** @return void */
	public function testHtmlEntityPreservation(): void {
		$input = "Use &mdash; for em-dash and &nbsp; for space.";
		$html = $this->converter->wikitextToHtml( $input );

		// Existing entities should NOT be double-encoded
		$this->assertStringNotContainsString( '&amp;mdash;', $html );
		$this->assertStringContainsString( '&mdash;', $html );

		$result = $this->converter->htmlToWikitext( $html );
		// Entities are decoded to their Unicode character equivalents on the way back
		$this->assertStringContainsString( '—', $result );
	}

	public function testAmpersandEncodedButEntitiesPreserved(): void {
		$input = "A & B &mdash; C &#8212; D";
		$html = $this->converter->wikitextToHtml( $input );

		// Bare '&' gets encoded; named/numeric entities stay
		$this->assertStringContainsString( '&amp;', $html );
		$this->assertStringContainsString( '&mdash;', $html );
		$this->assertStringContainsString( '&#8212;', $html );

		$result = $this->converter->htmlToWikitext( $html );
		// Bare & is restored, entities become Unicode chars
		$this->assertStringContainsString( '&', $result );
		$this->assertStringContainsString( '—', $result );
	}
}
