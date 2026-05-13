<?php

namespace BlueSpice\TranslationTransfer\Tests\Pipeline;

use BlueSpice\TranslationTransfer\IDictionary;
use BlueSpice\TranslationTransfer\Pipeline\TemplateTranslator;
use MWStake\MediaWiki\Component\DeeplTranslator\DeepLTranslator;
use PHPUnit\Framework\TestCase;
use StatusValue;

/**
 * @covers \BlueSpice\TranslationTransfer\Pipeline\TemplateTranslator
 */
class TemplateTranslatorTest extends TestCase {

	/**
	 * @return void
	 */
	public function testUnregisteredTemplateLeftUntouched(): void {
		$wikitext = '{{UnknownTemplate|arg1=value1|arg2=value2}}';

		$translator = $this->makeTranslator( [] );

		$result = $translator->translateTemplates( $wikitext, 'en', 'de' );

		$this->assertSame( $wikitext, $result );
	}

	/**
	 * @return void
	 */
	public function testEmptyRegistryReturnsOriginal(): void {
		$wikitext = '{{Hint box|Note text=Click here}}';

		$translator = $this->makeTranslator( [] );

		$result = $translator->translateTemplates( $wikitext, 'en', 'de' );

		$this->assertSame( $wikitext, $result );
	}

	/**
	 * @return void
	 */
	public function testTextArgTranslated(): void {
		$registry = [
			'Hint box' => [ 'Note text' => 'text' ]
		];

		$wikitext = '{{Hint box|Note text=Click here to continue}}';

		$translator = $this->makeTranslator( $registry, [
			'Click here to continue' => 'Klicken Sie hier, um fortzufahren'
		] );

		$result = $translator->translateTemplates( $wikitext, 'en', 'de' );

		$this->assertStringContainsString(
			'Note text=Klicken Sie hier, um fortzufahren',
			$result
		);
		$this->assertStringStartsWith( '{{Hint box|', $result );
		$this->assertStringEndsWith( '}}', $result );
	}

	/**
	 * @return void
	 */
	public function testTitleArgTranslated(): void {
		$registry = [
			'ButtonLink' => [ 'target' => 'title' ]
		];

		$wikitext = '{{ButtonLink|target=Main Page|label=Home}}';

		$translator = $this->makeTranslator( $registry, [
			'Main Page' => 'Hauptseite'
		] );

		$result = $translator->translateTemplates( $wikitext, 'en', 'de' );

		$this->assertStringContainsString( 'target=Hauptseite', $result );
		// "label" is not registered, should stay unchanged
		$this->assertStringContainsString( 'label=Home', $result );
	}

	/**
	 * @return void
	 */
	public function testMultipleRegisteredArgs(): void {
		$registry = [
			'ButtonLink' => [ 'label' => 'text', 'target' => 'title' ]
		];

		$wikitext = '{{ButtonLink|label=Click me|target=Help Page}}';

		$translator = $this->makeTranslator( $registry, [
			'Click me' => 'Klick mich',
			'Help Page' => 'Hilfeseite'
		] );

		$result = $translator->translateTemplates( $wikitext, 'en', 'de' );

		$this->assertStringContainsString( 'label=Klick mich', $result );
		$this->assertStringContainsString( 'target=Hilfeseite', $result );
	}

	/**
	 * @return void
	 */
	public function testNonRegisteredArgLeftUntouched(): void {
		$registry = [
			'Hint box' => [ 'Note text' => 'text' ]
		];

		$wikitext = '{{Hint box|Note text=Hello|icon=warning|style=bold}}';

		$translator = $this->makeTranslator( $registry, [
			'Hello' => 'Hallo'
		] );

		$result = $translator->translateTemplates( $wikitext, 'en', 'de' );

		$this->assertStringContainsString( 'Note text=Hallo', $result );
		$this->assertStringContainsString( 'icon=warning', $result );
		$this->assertStringContainsString( 'style=bold', $result );
	}

	/**
	 * @return void
	 */
	public function testNestedTemplatePreserved(): void {
		$registry = [
			'Outer' => [ 'text' => 'text' ]
		];

		$wikitext = '{{Outer|text=Hello {{Inner|x=1}} world}}';

		$translator = $this->makeTranslator( $registry, [
			'Hello {{Inner|x=1}} world' => 'Hallo {{Inner|x=1}} Welt'
		] );

		$result = $translator->translateTemplates( $wikitext, 'en', 'de' );

		// The nested template should be preserved in the output
		$this->assertStringContainsString( '{{Inner|x=1}}', $result );
	}

	/**
	 * @return void
	 */
	public function testParserFunctionSkipped(): void {
		$wikitext = '{{#if:condition|then text|else text}}';

		$registry = [
			'#if' => [ 'condition' => 'text' ]
		];

		$translator = $this->makeTranslator( $registry );

		$result = $translator->translateTemplates( $wikitext, 'en', 'de' );

		// Parser functions should never be processed
		$this->assertSame( $wikitext, $result );
	}

	/**
	 * @return void
	 */
	public function testInternalLinkInArgPreserved(): void {
		$registry = [
			'MyTemplate' => [ 'desc' => 'text' ]
		];

		$wikitext = '{{MyTemplate|desc=See [[Main Page|home]] for details}}';

		$translator = $this->makeTranslator( $registry, [
			// InlineConverter converts links to HTML, DeepL translates, then back
			// For this test, the DeepL mock just returns the HTML as-is
		] );

		$result = $translator->translateTemplates( $wikitext, 'en', 'de' );

		// The template structure should be preserved
		$this->assertStringStartsWith( '{{MyTemplate|', $result );
		$this->assertStringEndsWith( '}}', $result );
	}

	/**
	 * @return void
	 */
	public function testPositionalArgNotTranslated(): void {
		$registry = [
			'SimpleTemplate' => [ 'named' => 'text' ]
		];

		$wikitext = '{{SimpleTemplate|positional value|named=translatable}}';

		$translator = $this->makeTranslator( $registry, [
			'translatable' => 'übersetzbar'
		] );

		$result = $translator->translateTemplates( $wikitext, 'en', 'de' );

		// Positional arg should be unchanged
		$this->assertStringContainsString( '|positional value|', $result );
		$this->assertStringContainsString( 'named=übersetzbar', $result );
	}

	/**
	 * @return void
	 */
	public function testSurroundingTextPreserved(): void {
		$registry = [
			'Hint box' => [ 'Note text' => 'text' ]
		];

		$wikitext = 'Before {{Hint box|Note text=Hello}} after text';

		$translator = $this->makeTranslator( $registry, [
			'Hello' => 'Hallo'
		] );

		$result = $translator->translateTemplates( $wikitext, 'en', 'de' );

		$this->assertStringStartsWith( 'Before ', $result );
		$this->assertStringEndsWith( ' after text', $result );
		$this->assertStringContainsString( 'Note text=Hallo', $result );
	}

	/**
	 * @return void
	 */
	public function testMultipleTemplatesInText(): void {
		$registry = [
			'Hint box' => [ 'Note text' => 'text' ],
			'ButtonLink' => [ 'label' => 'text' ]
		];

		$wikitext = '{{Hint box|Note text=First}} and {{ButtonLink|label=Second}}';

		$translator = $this->makeTranslator( $registry, [
			'First' => 'Erste',
			'Second' => 'Zweite'
		] );

		$result = $translator->translateTemplates( $wikitext, 'en', 'de' );

		$this->assertStringContainsString( 'Note text=Erste', $result );
		$this->assertStringContainsString( 'label=Zweite', $result );
	}

	/**
	 * @return void
	 */
	public function testEmptyArgValueNotTranslated(): void {
		$registry = [
			'Hint box' => [ 'Note text' => 'text' ]
		];

		$wikitext = '{{Hint box|Note text=}}';

		$deepL = $this->createMock( DeepLTranslator::class );
		$deepL->expects( $this->never() )->method( 'translateText' );

		$dictionary = $this->createMock( IDictionary::class );

		$translator = new TemplateTranslator( $registry, $deepL, $dictionary );

		$result = $translator->translateTemplates( $wikitext, 'en', 'de' );

		$this->assertSame( $wikitext, $result );
	}

	/**
	 * @return void
	 */
	public function testTitleDictionaryCachePreventsDeepLCall(): void {
		$registry = [
			'ButtonLink' => [ 'target' => 'title' ]
		];

		$wikitext = '{{ButtonLink|target=Main Page}}';

		$deepL = $this->createMock( DeepLTranslator::class );
		$deepL->expects( $this->never() )->method( 'translateText' );

		$dictionary = $this->createMock( IDictionary::class );
		$dictionary->method( 'get' )->with( 'Main Page', 'de' )->willReturn( 'Hauptseite' );

		$translator = new TemplateTranslator( $registry, $deepL, $dictionary );

		$result = $translator->translateTemplates( $wikitext, 'en', 'de' );

		$this->assertStringContainsString( 'target=Hauptseite', $result );
	}

	/**
	 * @return void
	 */
	public function testWhitespaceAroundValuePreserved(): void {
		$registry = [
			'Hint box' => [ 'Note text' => 'text' ]
		];

		$wikitext = '{{Hint box|Note text= Hello world }}';

		$translator = $this->makeTranslator( $registry, [
			'Hello world' => 'Hallo Welt'
		] );

		$result = $translator->translateTemplates( $wikitext, 'en', 'de' );

		// Whitespace around the value should be preserved
		$this->assertStringContainsString( 'Note text= Hallo Welt ', $result );
	}

	/**
	 * Create a TemplateTranslator with mock dependencies.
	 *
	 * @param array $registry Template args registry
	 * @param array $translationMap Map of source text → translated text for DeepL mock
	 * @return TemplateTranslator
	 */
	private function makeTranslator(
		array $registry,
		array $translationMap = []
	): TemplateTranslator {
		$deepL = $this->createMock( DeepLTranslator::class );
		$deepL->method( 'translateText' )->willReturnCallback(
			static function ( $text, $sourceLang, $targetLang ) use ( $translationMap ) {
				// Strip HTML tags for lookup (InlineConverter may wrap in tags)
				$stripped = strip_tags( $text );
				$stripped = html_entity_decode( $stripped, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				$stripped = trim( $stripped );

				if ( isset( $translationMap[$stripped] ) ) {
					// Return translation wrapped in same HTML structure if input had tags
					if ( $text !== $stripped ) {
						// Simple case: replace the text content within the HTML
						return StatusValue::newGood(
							str_replace( $stripped, $translationMap[$stripped], $text )
						);
					}
					return StatusValue::newGood( $translationMap[$stripped] );
				}
				return StatusValue::newGood( $text );
			}
		);

		$dictionary = $this->createMock( IDictionary::class );
		$dictionary->method( 'get' )->willReturn( null );
		$dictionary->method( 'insert' )->willReturn( true );

		return new TemplateTranslator( $registry, $deepL, $dictionary );
	}
}
