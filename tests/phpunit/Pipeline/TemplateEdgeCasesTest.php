<?php

namespace BlueSpice\TranslationTransfer\Tests\Pipeline;

use BlueSpice\TranslationTransfer\Pipeline\InlineConverter;
use BlueSpice\TranslationTransfer\Pipeline\Segment;
use BlueSpice\TranslationTransfer\Pipeline\WikitextSegmenter;
use PHPUnit\Framework\TestCase;

/**
 * Edge case tests for templates, parser functions, and magic words
 * in both InlineConverter (inline context) and WikitextSegmenter (block context).
 *
 * @covers \BlueSpice\TranslationTransfer\Pipeline\InlineConverter
 * @covers \BlueSpice\TranslationTransfer\Pipeline\WikitextSegmenter
 */
class TemplateEdgeCasesTest extends TestCase {

	/** @var InlineConverter */
	private $converter;

	/** @var WikitextSegmenter */
	private $segmenter;

	protected function setUp(): void {
		parent::setUp();
		$this->converter = new InlineConverter();
		$this->segmenter = new WikitextSegmenter();
	}

	// ─── InlineConverter: templates as opaque elements ───

	/** @return void */
	public function testSimpleTemplateRoundTrip(): void {
		$input = 'Text with {{Infobox}} here.';
		$html = $this->converter->wikitextToHtml( $input );

		$this->assertStringNotContainsString( '{{', $html );
		$this->assertStringNotContainsString( '}}', $html );

		$result = $this->converter->htmlToWikitext( $html );
		$this->assertSame( $input, $result );
	}

	public function testTemplateWithPipeParameters(): void {
		$input = 'Before {{Navbox|title=Main|group1=Links|list1=A, B}} after.';
		$html = $this->converter->wikitextToHtml( $input );

		$this->assertStringNotContainsString( '{{Navbox', $html );

		$result = $this->converter->htmlToWikitext( $html );
		$this->assertSame( $input, $result );
	}

	public function testTemplateWithMultipleEquals(): void {
		// Template parameters can contain = in values
		$input = 'See {{Note|text=A=B means equality}} here.';
		$html = $this->converter->wikitextToHtml( $input );

		$result = $this->converter->htmlToWikitext( $html );
		$this->assertSame( $input, $result );
	}

	public function testNestedTemplatesTwoLevels(): void {
		$input = 'Before {{Outer|param={{Inner|val}}}} after.';
		$html = $this->converter->wikitextToHtml( $input );

		// The entire outer template (including the inner one) should be one opaque block
		$this->assertStringNotContainsString( '{{', $html );
		$this->assertStringNotContainsString( '}}', $html );

		$result = $this->converter->htmlToWikitext( $html );
		$this->assertSame( $input, $result );
	}

	public function testNestedTemplatesThreeLevels(): void {
		$input = 'X {{A|{{B|{{C|deep}}}}}} Y';
		$html = $this->converter->wikitextToHtml( $input );

		$this->assertStringNotContainsString( '{{', $html );

		$result = $this->converter->htmlToWikitext( $html );
		$this->assertSame( $input, $result );
	}

	public function testMultipleTemplatesOnSameLine(): void {
		$input = 'Start {{T1|a}} middle {{T2|b}} end.';
		$html = $this->converter->wikitextToHtml( $input );

		// Both should produce separate opaque spans
		preg_match_all( '/data-opaque-id="\d+"/', $html, $matches );
		$this->assertCount( 2, $matches[0] );

		$result = $this->converter->htmlToWikitext( $html );
		$this->assertSame( $input, $result );
	}

	public function testAdjacentTemplatesNoSpace(): void {
		$input = '{{First}}{{Second}}';
		$html = $this->converter->wikitextToHtml( $input );

		preg_match_all( '/data-opaque-id="\d+"/', $html, $matches );
		$this->assertCount( 2, $matches[0] );

		$result = $this->converter->htmlToWikitext( $html );
		$this->assertSame( $input, $result );
	}

	public function testTemplateInsideBoldText(): void {
		$input = "This is '''bold {{Icon|star}} text''' here.";
		$html = $this->converter->wikitextToHtml( $input );

		// Template should be opaque inside the <b> tag
		$this->assertStringContainsString( '<b>', $html );
		$this->assertMatchesRegularExpression( '/data-opaque-id="\d+"/', $html );

		$result = $this->converter->htmlToWikitext( $html );
		$this->assertSame( $input, $result );
	}

	public function testTemplateInsideItalicText(): void {
		$input = "Text ''italic {{Ref|123}}'' end.";
		$html = $this->converter->wikitextToHtml( $input );

		$result = $this->converter->htmlToWikitext( $html );
		$this->assertSame( $input, $result );
	}

	public function testTemplateAdjacentToLink(): void {
		$input = '{{Flag|DE}} [[Germany|Deutschland]]';
		$html = $this->converter->wikitextToHtml( $input );

		$this->assertMatchesRegularExpression( '/data-opaque-id="\d+"/', $html );
		$this->assertMatchesRegularExpression( '/data-link-id="\d+"/', $html );

		$result = $this->converter->htmlToWikitext( $html );
		$this->assertSame( $input, $result );
	}

	// ─── Parser functions ───

	/** @return void */
	public function testParserFunctionIfRoundTrip(): void {
		$input = 'Result: {{#if:{{{show|}}}|Visible|Hidden}}';
		$html = $this->converter->wikitextToHtml( $input );

		$this->assertStringNotContainsString( '{{#if', $html );

		$result = $this->converter->htmlToWikitext( $html );
		$this->assertSame( $input, $result );
	}

	public function testParserFunctionSwitchRoundTrip(): void {
		$input = '{{#switch:{{{type}}}|a=Alpha|b=Beta|Gamma}}';
		$html = $this->converter->wikitextToHtml( $input );

		$result = $this->converter->htmlToWikitext( $html );
		$this->assertSame( $input, $result );
	}

	public function testParserFunctionIfeqRoundTrip(): void {
		$input = 'Status: {{#ifeq:{{{status}}}|active|Running|Stopped}}';
		$html = $this->converter->wikitextToHtml( $input );

		$result = $this->converter->htmlToWikitext( $html );
		$this->assertSame( $input, $result );
	}

	public function testNestedParserFunctions(): void {
		$input = '{{#if:{{{val|}}}|{{#switch:{{{val}}}|x=X|y=Y}}|default}}';
		$html = $this->converter->wikitextToHtml( $input );

		$this->assertStringNotContainsString( '{{', $html );

		$result = $this->converter->htmlToWikitext( $html );
		$this->assertSame( $input, $result );
	}

	public function testParserFunctionExprRoundTrip(): void {
		$input = 'Total: {{#expr:2+3*4}} items.';
		$html = $this->converter->wikitextToHtml( $input );

		$result = $this->converter->htmlToWikitext( $html );
		$this->assertSame( $input, $result );
	}

	public function testParserFunctionInvoke(): void {
		$input = 'Output: {{#invoke:ModuleName|functionName|arg1|arg2}}';
		$html = $this->converter->wikitextToHtml( $input );

		$result = $this->converter->htmlToWikitext( $html );
		$this->assertSame( $input, $result );
	}

	public function testParserFunctionTimeRoundTrip(): void {
		$input = 'Today is {{#time:Y-m-d}}.';
		$html = $this->converter->wikitextToHtml( $input );

		$result = $this->converter->htmlToWikitext( $html );
		$this->assertSame( $input, $result );
	}

	// ─── Magic words and variables ───

	/** @return void */
	public function testMagicWordPAGENAME(): void {
		$input = 'This page is {{PAGENAME}}.';
		$html = $this->converter->wikitextToHtml( $input );

		$this->assertStringNotContainsString( '{{PAGENAME}}', $html );

		$result = $this->converter->htmlToWikitext( $html );
		$this->assertSame( $input, $result );
	}

	public function testMagicWordSITENAME(): void {
		$input = 'Welcome to {{SITENAME}}.';
		$html = $this->converter->wikitextToHtml( $input );

		$result = $this->converter->htmlToWikitext( $html );
		$this->assertSame( $input, $result );
	}

	public function testMagicWordFULLPAGENAME(): void {
		$input = 'See {{FULLPAGENAME}} for info.';
		$html = $this->converter->wikitextToHtml( $input );

		$result = $this->converter->htmlToWikitext( $html );
		$this->assertSame( $input, $result );
	}

	public function testMultipleMagicWords(): void {
		$input = '{{PAGENAME}} in namespace {{NAMESPACENUMBER}} of {{SITENAME}}';
		$html = $this->converter->wikitextToHtml( $input );

		preg_match_all( '/data-opaque-id="\d+"/', $html, $matches );
		$this->assertCount( 3, $matches[0] );

		$result = $this->converter->htmlToWikitext( $html );
		$this->assertSame( $input, $result );
	}

	// ─── Template parameter syntax {{{...}}} ───

	/** @return void */
	public function testTripleBraceParameterRoundTrip(): void {
		$input = 'Name: {{{name|default}}}';
		$html = $this->converter->wikitextToHtml( $input );

		$result = $this->converter->htmlToWikitext( $html );
		$this->assertSame( $input, $result );
	}

	public function testTripleBraceInsideTemplate(): void {
		$input = '{{Infobox|title={{{title|Untitled}}}}}';
		$html = $this->converter->wikitextToHtml( $input );

		$this->assertStringNotContainsString( '{{', $html );

		$result = $this->converter->htmlToWikitext( $html );
		$this->assertSame( $input, $result );
	}

	// ─── Subst and safesubst ───

	/** @return void */
	public function testSubstTemplateRoundTrip(): void {
		$input = 'Generated: {{subst:Welcome|user=Admin}}';
		$html = $this->converter->wikitextToHtml( $input );

		$result = $this->converter->htmlToWikitext( $html );
		$this->assertSame( $input, $result );
	}

	public function testSafesubstRoundTrip(): void {
		$input = '{{safesubst:#if:{{{1|}}}|{{{1}}}|nothing}}';
		$html = $this->converter->wikitextToHtml( $input );

		$result = $this->converter->htmlToWikitext( $html );
		$this->assertSame( $input, $result );
	}

	// ─── Templates with wikitext inside parameters ───

	/** @return void */
	public function testTemplateWithWikitextInParams(): void {
		// Bold/italic inside template parameters — the whole template is opaque
		$input = "{{Quote|text=This is '''important''' text|author=Someone}}";
		$html = $this->converter->wikitextToHtml( $input );

		// Must be single opaque block — bold inside should NOT be converted
		preg_match_all( '/data-opaque-id="\d+"/', $html, $matches );
		$this->assertCount( 1, $matches[0] );
		$this->assertStringNotContainsString( '<b>', $html );

		$result = $this->converter->htmlToWikitext( $html );
		$this->assertSame( $input, $result );
	}

	public function testTemplateWithLinkInParams(): void {
		$input = "{{Cite|url=https://example.com|title=[[Article|Name]]}}";
		$html = $this->converter->wikitextToHtml( $input );

		// Entire template should be opaque — link inside should not be extracted
		$this->assertStringNotContainsString( 'data-link-id', $html );

		$result = $this->converter->htmlToWikitext( $html );
		$this->assertSame( $input, $result );
	}

	// ─── Segmenter: block-level templates ───

	/** @return void */
	public function testStandaloneTemplateLinePassedThrough(): void {
		$input = "Paragraph one.\n\n{{Infobox|name=Test|type=Example}}\n\nParagraph two.";
		[ $skeleton, $segments ] = $this->segmenter->segment( $input );

		// Template line is paragraph content containing the template
		$types = array_map( static function ( Segment $s ) {
			return $s->getType();
		}, $segments );

		// 3 segments: para, template-as-para, para
		$this->assertCount( 3, $segments );
		$this->assertSame( 'Paragraph one.', $segments[0]->getSourceText() );
		$this->assertSame( 'Paragraph two.', $segments[2]->getSourceText() );
	}

	public function testTemplateOnlyParagraphRoundTrip(): void {
		$input = "{{Warning|Do not edit}}";
		[ $skeleton, $segments ] = $this->segmenter->segment( $input );

		$this->assertCount( 1, $segments );

		// Verify round-trip through skeleton
		$reconstructed = str_replace(
			$segments[0]->getMarker(), $segments[0]->getSourceText(), $skeleton
		);
		$this->assertSame( $input, $reconstructed );
	}

	public function testParserFunctionInListItem(): void {
		$input = "* {{#if:{{{show|}}}|Item visible|Item hidden}}\n* Normal item";
		[ $skeleton, $segments ] = $this->segmenter->segment( $input );

		$this->assertCount( 2, $segments );
		$this->assertSame( Segment::TYPE_LIST_ITEM, $segments[0]->getType() );
		$this->assertStringContainsString( '{{#if:', $segments[0]->getSourceText() );
	}

	public function testParserFunctionInTableCell(): void {
		$input = "{|\n| {{#switch:{{{v}}}|a=Alpha|Beta}}\n|}";
		[ $skeleton, $segments ] = $this->segmenter->segment( $input );

		$cellSegments = array_filter( $segments, static function ( Segment $s ) {
			return $s->getType() === Segment::TYPE_TABLE_CELL;
		} );
		$this->assertCount( 1, $cellSegments );
		$cell = array_values( $cellSegments )[0];
		$this->assertStringContainsString( '{{#switch:', $cell->getSourceText() );
	}

	public function testParserFunctionInHeading(): void {
		$input = "== {{#if:{{{title|}}}|{{{title}}}|Default}} ==";
		[ $skeleton, $segments ] = $this->segmenter->segment( $input );

		$this->assertCount( 1, $segments );
		$this->assertSame( Segment::TYPE_HEADING, $segments[0]->getType() );
		$this->assertStringContainsString( '{{#if:', $segments[0]->getSourceText() );
	}

	// ─── Mixed: template + formatting in same segment ───

	/** @return void */
	public function testParagraphWithTemplateAndBoldRoundTrip(): void {
		$input = "This '''important''' note uses {{Icon|warning}} symbol.";
		$html = $this->converter->wikitextToHtml( $input );

		$this->assertStringContainsString( '<b>important</b>', $html );
		$this->assertMatchesRegularExpression( '/data-opaque-id="\d+"/', $html );

		$result = $this->converter->htmlToWikitext( $html );
		$this->assertSame( $input, $result );
	}

	public function testParagraphWithTemplateAndLinkRoundTrip(): void {
		$input = "See [[Help:Editing|editing help]] and {{Note|read carefully}}.";
		$html = $this->converter->wikitextToHtml( $input );

		$this->assertMatchesRegularExpression( '/data-link-id="\d+"/', $html );
		$this->assertMatchesRegularExpression( '/data-opaque-id="\d+"/', $html );

		$result = $this->converter->htmlToWikitext( $html );
		$this->assertSame( $input, $result );
	}

	public function testParagraphWithAllInlineTypes(): void {
		$input = "'''Bold''' ''italic'' [[Page|link]] {{Tpl|arg}} [https://x.com Site]";
		$html = $this->converter->wikitextToHtml( $input );

		$this->assertStringContainsString( '<b>Bold</b>', $html );
		$this->assertStringContainsString( '<i>italic</i>', $html );

		$result = $this->converter->htmlToWikitext( $html );
		$this->assertSame( $input, $result );
	}

	// ─── Edge: unclosed / malformed braces ───

	/** @return void */
	public function testSingleOpenBraceNotTemplate(): void {
		$input = 'Use { curly braces } carefully.';
		$html = $this->converter->wikitextToHtml( $input );

		// Single braces should NOT be treated as templates
		$this->assertStringNotContainsString( 'data-opaque-id', $html );

		$result = $this->converter->htmlToWikitext( $html );
		$this->assertSame( $input, $result );
	}

	public function testTripleBracesStandalone(): void {
		// {{{param}}} — triple braces (template parameter)
		$input = 'Value is {{{myParam|fallback}}}.';
		$html = $this->converter->wikitextToHtml( $input );

		$result = $this->converter->htmlToWikitext( $html );
		$this->assertSame( $input, $result );
	}

	public function testTemplateWithNewlineInParams(): void {
		// Template params spanning one line (typical for complex templates on a single line)
		$input = "{{MyTemplate|param1=value1|param2=value2|param3=value3}}";
		$html = $this->converter->wikitextToHtml( $input );

		$result = $this->converter->htmlToWikitext( $html );
		$this->assertSame( $input, $result );
	}

	public function testEmptyTemplate(): void {
		$input = 'Before {{}} after.';
		$html = $this->converter->wikitextToHtml( $input );

		$result = $this->converter->htmlToWikitext( $html );
		$this->assertSame( $input, $result );
	}

	// ─── Full round-trip: segmenter + converter together ───

	/** @return void */
	public function testFullRoundTripParagraphWithTemplateAndFormatting(): void {
		$wikitext = "This '''bold''' text has {{Tpl|x}} and [[Link|label]] inside.";
		[ $skeleton, $segments ] = $this->segmenter->segment( $wikitext );
		$this->assertCount( 1, $segments );

		// Simulate inline conversion
		$html = $this->converter->wikitextToHtml( $segments[0]->getSourceText() );

		// Simulate "identity translation" (no change)
		$backWikitext = $this->converter->htmlToWikitext( $html );
		$segments[0]->setTranslatedText( $backWikitext );

		$result = str_replace(
			$segments[0]->getMarker(), $segments[0]->getTranslatedText(), $skeleton
		);
		$this->assertSame( $wikitext, $result );
	}

	public function testFullRoundTripListWithParserFunction(): void {
		$wikitext = "* {{#if:{{{a|}}}|Yes|No}} is the answer\n* Normal item";
		[ $skeleton, $segments ] = $this->segmenter->segment( $wikitext );
		$this->assertCount( 2, $segments );

		// Round-trip each segment through converter
		foreach ( $segments as $segment ) {
			$this->converter->reset();
			$html = $this->converter->wikitextToHtml( $segment->getSourceText() );
			$back = $this->converter->htmlToWikitext( $html );
			$segment->setTranslatedText( $back );
		}

		$result = $skeleton;
		foreach ( $segments as $segment ) {
			$result = str_replace( $segment->getMarker(), $segment->getTranslatedText(), $result );
		}
		$this->assertSame( $wikitext, $result );
	}
}
