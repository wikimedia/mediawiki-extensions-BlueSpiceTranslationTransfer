<?php

namespace BlueSpice\TranslationTransfer\Tests\Pipeline;

use BlueSpice\TranslationTransfer\Pipeline\MagicWordTranslator;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\TitleFactory;
use MediaWikiIntegrationTestCase;
use MWStake\MediaWiki\Component\DeeplTranslator\DeepLTranslator;
use Status;
use Title;

/**
 * Tests for MagicWordTranslator.
 *
 * @covers \BlueSpice\TranslationTransfer\Pipeline\MagicWordTranslator
 */
class MagicWordTranslatorTest extends MediaWikiIntegrationTestCase {

	/** @var DeepLTranslator|\PHPUnit\Framework\MockObject\MockObject */
	private $deepL;

	/** @var TitleFactory|\PHPUnit\Framework\MockObject\MockObject */
	private $titleFactory;

	protected function setUp(): void {
		parent::setUp();

		$this->deepL = $this->createMock( DeepLTranslator::class );

		// Mock TitleFactory: returns a Title mock appropriate for the input.
		// For template checks (NS_TEMPLATE), title doesn't exist.
		// For file namespace detection in translateImgAttributes, we use the real TitleFactory
		// from services so NS_FILE detection works correctly.
		$this->titleFactory = $this->createMock( TitleFactory::class );
		$this->titleFactory->method( 'newFromText' )->willReturnCallback(
			function ( $text, $ns = NS_MAIN ) {
				if ( $ns === NS_TEMPLATE ) {
					// Template existence check — always return non-existing title
					$titleMock = $this->createMock( Title::class );
					$titleMock->method( 'exists' )->willReturn( false );
					$titleMock->method( 'getNamespace' )->willReturn( NS_TEMPLATE );
					return $titleMock;
				}
				// For file link detection, use the real TitleFactory
				return MediaWikiServices::getInstance()->getTitleFactory()->newFromText( $text, $ns );
			}
		);
	}

	/**
	 * @param bool $enabled
	 * @return MagicWordTranslator
	 */
	private function makeTranslator( bool $enabled = true ): MagicWordTranslator {
		$services = MediaWikiServices::getInstance();

		return new MagicWordTranslator(
			$services->getLanguageFactory(),
			$this->titleFactory,
			$this->deepL,
			$services->getMagicWordFactory(),
			$enabled
		);
	}

	/**
	 * Test that {{#contentTranslate...}} is always removed, regardless of enabled flag.
	 */
	public function testRemoveContentTranslateAlways(): void {
		$translator = $this->makeTranslator( false );
		$input = "Some text\n{{#contentTranslate:target=de}}\nMore text";
		$result = $translator->translateMagicWords( $input, 'en', 'de' );
		$this->assertStringNotContainsString( '#contentTranslate', $result );
		$this->assertStringContainsString( 'Some text', $result );
		$this->assertStringContainsString( 'More text', $result );
	}

	/**
	 * Test that magic words are NOT translated when disabled.
	 */
	public function testDisabledSkipsMagicWords(): void {
		$translator = $this->makeTranslator( false );
		$input = "__TOC__\n\nSome text";
		$result = $translator->translateMagicWords( $input, 'en', 'de' );
		// TOC should remain untouched when disabled
		$this->assertStringContainsString( '__TOC__', $result );
	}

	/**
	 * Test that {{DISPLAYTITLE:value}} is translated even when disabled.
	 */
	public function testDisplayTitleTranslatedWhenDisabled(): void {
		$this->deepL->method( 'translateText' )
			->willReturn( Status::newGood( 'Übersetzter Titel' ) );

		$translator = $this->makeTranslator( false );
		$input = "{{DISPLAYTITLE:Original Title}}\n\nSome content";
		$result = $translator->translateMagicWords( $input, 'en', 'de' );
		$this->assertStringContainsString( '{{DISPLAYTITLE:Übersetzter Titel}}', $result );
	}

	/**
	 * Test that __TOC__ stays as __TOC__ (already English).
	 */
	public function testDoubleUnderscoreTocEnglish(): void {
		$translator = $this->makeTranslator( true );
		$input = "Some text\n__TOC__\nMore text";
		$result = $translator->translateMagicWords( $input, 'en', 'de' );
		$this->assertStringContainsString( '__TOC__', $result );
	}

	/**
	 * Test that __NOTOC__ stays as __NOTOC__ (already English).
	 */
	public function testDoubleUnderscoreNotocEnglish(): void {
		$translator = $this->makeTranslator( true );
		$input = "__NOTOC__\n\nHello";
		$result = $translator->translateMagicWords( $input, 'en', 'de' );
		$this->assertStringContainsString( '__NOTOC__', $result );
	}

	/**
	 * Test that {{PAGENAME}} stays as {{PAGENAME}} (already English).
	 */
	public function testVariableMagicWordPagename(): void {
		$translator = $this->makeTranslator( true );
		$input = "Title: {{PAGENAME}}";
		$result = $translator->translateMagicWords( $input, 'en', 'de' );
		$this->assertStringContainsString( '{{PAGENAME}}', $result );
	}

	/**
	 * Test that {{DISPLAYTITLE:value}} has its value translated via DeepL.
	 */
	public function testDisplayTitleTranslation(): void {
		$this->deepL->method( 'translateText' )
			->willReturn( Status::newGood( 'Übersetzter Titel' ) );

		$translator = $this->makeTranslator( true );
		$input = "{{DISPLAYTITLE:Original Title}}\n\nSome content";
		$result = $translator->translateMagicWords( $input, 'en', 'de' );
		$this->assertStringContainsString( '{{DISPLAYTITLE:Übersetzter Titel}}', $result );
	}

	/**
	 * Test that {{DISPLAYTITLE:value}} is left unchanged when DeepL fails.
	 */
	public function testDisplayTitleDeeplFailure(): void {
		$this->deepL->method( 'translateText' )
			->willReturn( Status::newFatal( 'deepl-error' ) );

		$translator = $this->makeTranslator( true );
		$input = "{{DISPLAYTITLE:Original Title}}";
		$result = $translator->translateMagicWords( $input, 'en', 'de' );
		$this->assertStringContainsString( '{{DISPLAYTITLE:Original Title}}', $result );
	}

	/**
	 * Test that {{#contentTranslate...}} is removed when enabled.
	 */
	public function testRemoveContentTranslateEnabled(): void {
		$translator = $this->makeTranslator( true );
		$input = "{{#contentTranslate:target=de|status=ready}}\nSome text";
		$result = $translator->translateMagicWords( $input, 'en', 'de' );
		$this->assertStringNotContainsString( '#contentTranslate', $result );
	}

	/**
	 * Test that normal templates are not affected.
	 */
	public function testNormalTemplatesNotModified(): void {
		$translator = $this->makeTranslator( true );
		$input = "{{SomeTemplate|param=value}}\n{{AnotherTemplate}}";
		$result = $translator->translateMagicWords( $input, 'en', 'de' );
		$this->assertStringContainsString( '{{SomeTemplate|param=value}}', $result );
		$this->assertStringContainsString( '{{AnotherTemplate}}', $result );
	}

	/**
	 * Test that empty wikitext is handled gracefully.
	 */
	public function testEmptyWikitext(): void {
		$translator = $this->makeTranslator( true );
		$result = $translator->translateMagicWords( '', 'en', 'de' );
		$this->assertSame( '', $result );
	}

	/**
	 * Test that text without any magic words passes through unchanged.
	 */
	public function testPlainTextPassthrough(): void {
		$translator = $this->makeTranslator( true );
		$input = "Just some regular text with '''bold''' and [[links]].";
		$result = $translator->translateMagicWords( $input, 'en', 'de' );
		$this->assertSame( $input, $result );
	}

	/**
	 * Test image attributes in [[File:...]] links.
	 * English attributes should stay English (no change).
	 */
	public function testImageAttributesEnglishUnchanged(): void {
		$translator = $this->makeTranslator( true );
		$input = "[[File:Example.jpg|thumb|center|200px|A caption]]";
		$result = $translator->translateMagicWords( $input, 'en', 'de' );
		$this->assertStringContainsString( 'thumb', $result );
		$this->assertStringContainsString( 'center', $result );
		$this->assertStringContainsString( 'A caption', $result );
	}

	/**
	 * Test that non-file links are not affected by image attribute translation.
	 */
	public function testNonFileLinkNotAffected(): void {
		$translator = $this->makeTranslator( true );
		$input = "[[Some Page|thumb description]]";
		$result = $translator->translateMagicWords( $input, 'en', 'de' );
		$this->assertSame( $input, $result );
	}

	/**
	 * Test multiple magic words in one text.
	 */
	public function testMultipleMagicWords(): void {
		$this->deepL->method( 'translateText' )
			->willReturn( Status::newGood( 'Titel' ) );

		$translator = $this->makeTranslator( true );
		$input = "__TOC__\n\n{{PAGENAME}}\n\n{{DISPLAYTITLE:Title}}\n\n__NOTOC__";
		$result = $translator->translateMagicWords( $input, 'en', 'de' );
		$this->assertStringContainsString( '__TOC__', $result );
		$this->assertStringContainsString( '{{PAGENAME}}', $result );
		$this->assertStringContainsString( '{{DISPLAYTITLE:Titel}}', $result );
	}
}
