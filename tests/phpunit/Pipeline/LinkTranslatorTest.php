<?php

namespace BlueSpice\TranslationTransfer\Tests\Pipeline;

use BlueSpice\TranslationTransfer\IDictionary;
use BlueSpice\TranslationTransfer\Pipeline\LinkTranslator;
use MediaWiki\Config\HashConfig;
use MWStake\MediaWiki\Component\DeeplTranslator\DeepLTranslator;
use PHPUnit\Framework\TestCase;
use StatusValue;

/**
 * @covers \BlueSpice\TranslationTransfer\Pipeline\LinkTranslator
 */
class LinkTranslatorTest extends TestCase {

	/**
	 * @return void
	 */
	public function testCategoryAlwaysTranslated(): void {
		$wikitext = 'Some text [[Category:Test Category]] more text';

		$translator = $this->makeLinkTranslator(
			[ 'translatePageTitle' => false, 'translateNamespaces' => false ],
			[ 'Category:Test Category' => 'Testkategorie' ]
		);

		$result = $translator->translateLinks( $wikitext, 'en', 'de' );

		// Category should be translated regardless of config
		$this->assertStringContainsString( 'Testkategorie', $result );
		// Namespace should also be translated to German
		$this->assertStringContainsString( 'Kategorie:', $result );
	}

	/**
	 * @return void
	 */
	public function testCategoryWithColonPrefix(): void {
		$wikitext = 'A link to [[:Category:Visible Category]] here';

		$translator = $this->makeLinkTranslator(
			[ 'translatePageTitle' => false, 'translateNamespaces' => false ],
			[ 'Category:Visible Category' => 'Sichtbare Kategorie' ]
		);

		$result = $translator->translateLinks( $wikitext, 'en', 'de' );

		// Should preserve leading colon
		$this->assertStringContainsString( '[[:Kategorie:Sichtbare Kategorie]]', $result );
	}

	/**
	 * @return void
	 */
	public function testCategoryWithSortKey(): void {
		$wikitext = '[[Category:Animals|Zebra]]';

		$translator = $this->makeLinkTranslator(
			[ 'translatePageTitle' => false, 'translateNamespaces' => false ],
			[ 'Category:Animals' => 'Tiere' ]
		);

		$result = $translator->translateLinks( $wikitext, 'en', 'de' );

		// Sort key "Zebra" should be preserved
		$this->assertStringContainsString( '[[Kategorie:Tiere|Zebra]]', $result );
	}

	/**
	 * @return void
	 */
	public function testNamespaceTranslatedWhenConfigured(): void {
		$wikitext = 'See [[Help:Getting Started]] for info';

		$translator = $this->makeLinkTranslator(
			[ 'translatePageTitle' => false, 'translateNamespaces' => true ]
		);

		$result = $translator->translateLinks( $wikitext, 'en', 'de' );

		// Help namespace should be translated to German "Hilfe"
		$this->assertStringContainsString( '[[Hilfe:Getting Started]]', $result );
	}

	/**
	 * @return void
	 */
	public function testNamespaceNotTranslatedWhenNotConfigured(): void {
		$wikitext = 'See [[Help:Getting Started]] for info';

		$translator = $this->makeLinkTranslator(
			[ 'translatePageTitle' => false, 'translateNamespaces' => false ]
		);

		$result = $translator->translateLinks( $wikitext, 'en', 'de' );

		// Nothing should change
		$this->assertStringContainsString( '[[Help:Getting Started]]', $result );
	}

	/**
	 * @return void
	 */
	public function testTitleTranslatedWhenConfigured(): void {
		$wikitext = 'Visit [[Main Page]] now';

		$translator = $this->makeLinkTranslator(
			[ 'translatePageTitle' => true, 'translateNamespaces' => false ],
			[ 'Main Page' => 'Hauptseite' ]
		);

		$result = $translator->translateLinks( $wikitext, 'en', 'de' );

		$this->assertStringContainsString( '[[Hauptseite]]', $result );
	}

	/**
	 * @return void
	 */
	public function testTitleNotTranslatedWhenNotConfigured(): void {
		$wikitext = 'Visit [[Main Page]] now';

		$translator = $this->makeLinkTranslator(
			[ 'translatePageTitle' => false, 'translateNamespaces' => false ]
		);

		$result = $translator->translateLinks( $wikitext, 'en', 'de' );

		$this->assertStringContainsString( '[[Main Page]]', $result );
	}

	/**
	 * @return void
	 */
	public function testTitleAndNamespaceTranslatedTogether(): void {
		$wikitext = 'See [[Help:Getting Started]] for info';

		$translator = $this->makeLinkTranslator(
			[ 'translatePageTitle' => true, 'translateNamespaces' => true ],
			[ 'Help:Getting Started' => 'Erste Schritte' ]
		);

		$result = $translator->translateLinks( $wikitext, 'en', 'de' );

		$this->assertStringContainsString( '[[Hilfe:Erste Schritte]]', $result );
	}

	/**
	 * @return void
	 */
	public function testTitleWithDisplayText(): void {
		$wikitext = 'Click [[Main Page|here]] to go home';

		$translator = $this->makeLinkTranslator(
			[ 'translatePageTitle' => true, 'translateNamespaces' => false ],
			[ 'Main Page' => 'Hauptseite' ]
		);

		$result = $translator->translateLinks( $wikitext, 'en', 'de' );

		// Display text should be preserved
		$this->assertStringContainsString( '[[Hauptseite|here]]', $result );
	}

	/**
	 * @return void
	 */
	public function testExternalLinksNotAffected(): void {
		$wikitext = 'Visit [https://example.com Example] and [[Main Page]]';

		$translator = $this->makeLinkTranslator(
			[ 'translatePageTitle' => true, 'translateNamespaces' => true ],
			[ 'Main Page' => 'Hauptseite' ]
		);

		$result = $translator->translateLinks( $wikitext, 'en', 'de' );

		// External link should be untouched
		$this->assertStringContainsString( '[https://example.com Example]', $result );
		$this->assertStringContainsString( '[[Hauptseite]]', $result );
	}

	/**
	 * @return void
	 */
	public function testSemanticPropertySkipped(): void {
		$wikitext = '[[Property::Value]] and [[Main Page]]';

		$translator = $this->makeLinkTranslator(
			[ 'translatePageTitle' => true, 'translateNamespaces' => true ],
			[ 'Main Page' => 'Hauptseite' ]
		);

		$result = $translator->translateLinks( $wikitext, 'en', 'de' );

		$this->assertStringContainsString( '[[Property::Value]]', $result );
		$this->assertStringContainsString( '[[Hauptseite]]', $result );
	}

	/**
	 * @return void
	 */
	public function testNamespaceMapOverridesDefault(): void {
		$wikitext = 'See [[Help:Some Page]]';

		$translator = $this->makeLinkTranslator(
			[
				'translatePageTitle' => false,
				'translateNamespaces' => true,
				'namespaceMap' => [ NS_HELP => [ 'de' => 'Anleitung' ] ],
			]
		);

		$result = $translator->translateLinks( $wikitext, 'en', 'de' );

		$this->assertStringContainsString( '[[Anleitung:Some Page]]', $result );
	}

	/**
	 * @return void
	 */
	public function testDictionaryCachePreventsDeepLCall(): void {
		$wikitext = '[[Main Page]]';

		// Dictionary has cached translation — DeepL should NOT be called
		$deepL = $this->createMock( DeepLTranslator::class );
		$deepL->expects( $this->never() )->method( 'translateText' );

		$dictionary = $this->createMock( IDictionary::class );
		$dictionary->method( 'get' )->willReturn( 'Hauptseite' );

		$translator = $this->makeLinkTranslatorWithMocks(
			[ 'translatePageTitle' => true, 'translateNamespaces' => false ],
			$deepL,
			$dictionary
		);

		$result = $translator->translateLinks( $wikitext, 'en', 'de' );

		$this->assertStringContainsString( '[[Hauptseite]]', $result );
	}

	/**
	 * @return void
	 */
	public function testMultipleLinksInText(): void {
		$wikitext = '[[Main Page]] and [[Category:Science]] and [[Help:FAQ]]';

		$translator = $this->makeLinkTranslator(
			[ 'translatePageTitle' => true, 'translateNamespaces' => true ],
			[
				'Main Page' => 'Hauptseite',
				'Category:Science' => 'Wissenschaft',
				'Help:FAQ' => 'Häufige Fragen',
			]
		);

		$result = $translator->translateLinks( $wikitext, 'en', 'de' );

		$this->assertStringContainsString( '[[Hauptseite]]', $result );
		$this->assertStringContainsString( 'Wissenschaft', $result );
		$this->assertStringContainsString( 'Kategorie:', $result );
		$this->assertStringContainsString( '[[Hilfe:Häufige Fragen]]', $result );
	}

	/**
	 * @return void
	 */
	public function testLinksInsideTemplatesTranslated(): void {
		$wikitext = '{{Infobox|link=[[Help:Getting Started]]}} and [[Main Page]]';

		$translator = $this->makeLinkTranslator(
			[ 'translatePageTitle' => true, 'translateNamespaces' => true ],
			[
				'Help:Getting Started' => 'Erste Schritte',
				'Main Page' => 'Hauptseite',
			]
		);

		$result = $translator->translateLinks( $wikitext, 'en', 'de' );

		// Link inside template should also be translated
		$this->assertStringContainsString( '[[Hilfe:Erste Schritte]]', $result );
		$this->assertStringContainsString( '[[Hauptseite]]', $result );
	}

	/**
	 * @return void
	 */
	public function testGalleryNamespaceTranslation(): void {
		// English source wiki → German target: "File:" should become "Datei:" via namespaceMap
		$wikitext = "<gallery>\nFile:Photo.jpg|A caption\nFile:Other.png|Another\n</gallery>";

		$translator = $this->makeLinkTranslator(
			[
				'translatePageTitle' => false,
				'translateNamespaces' => true,
				'namespaceMap' => [ NS_FILE => [ 'de' => 'Datei' ] ],
			]
		);

		$result = $translator->translateLinks( $wikitext, 'en', 'de' );

		// "File:" should become "Datei:" via namespace map
		$this->assertStringContainsString( 'Datei:Photo.jpg', $result );
		$this->assertStringContainsString( 'Datei:Other.png', $result );
	}

	/**
	 * @return void
	 */
	public function testGalleryNamespaceSkippedWhenNotConfigured(): void {
		$wikitext = "<gallery>\nFile:Photo.jpg|A caption\n</gallery>";

		$translator = $this->makeLinkTranslator(
			[ 'translatePageTitle' => false, 'translateNamespaces' => false ]
		);

		$result = $translator->translateLinks( $wikitext, 'en', 'de' );

		// Nothing should change when translateNamespaces is false
		$this->assertStringContainsString( 'File:Photo.jpg', $result );
	}

	/**
	 * @return void
	 */
	public function testFileLinksSkipped(): void {
		$wikitext = '[[File:Image.png|thumb|A nice photo]] and [[Main Page]]';

		$translator = $this->makeLinkTranslator(
			[ 'translatePageTitle' => true, 'translateNamespaces' => true ],
			[ 'Main Page' => 'Hauptseite' ]
		);

		$result = $translator->translateLinks( $wikitext, 'en', 'de' );

		// File link should not be translated (it contains |thumb|... options)
		// Main page should be translated
		$this->assertStringContainsString( '[[Hauptseite]]', $result );
	}

	/**
	 * Create a LinkTranslator with mock dependencies.
	 *
	 * @param array $configValues Config for DeeplTranslateConversionConfig
	 * @param array $dictionaryMap Map of prefixedTitle => translation (for dictionary lookups/DeepL)
	 * @return LinkTranslator
	 */
	private function makeLinkTranslator(
		array $configValues = [],
		array $dictionaryMap = []
	): LinkTranslator {
		$deepL = $this->createMock( DeepLTranslator::class );
		$deepL->method( 'translateText' )->willReturnCallback(
			static function ( $text, $sourceLang, $targetLang ) use ( $dictionaryMap ) {
				// Search by title text (without namespace prefix)
				foreach ( $dictionaryMap as $key => $translation ) {
					$parts = explode( ':', $key, 2 );
					$titleText = count( $parts ) > 1 ? $parts[1] : $parts[0];
					if ( $text === $titleText ) {
						return StatusValue::newGood( $translation );
					}
				}
				return StatusValue::newGood( $text );
			}
		);

		$dictionary = $this->createMock( IDictionary::class );
		$dictionary->method( 'get' )->willReturn( null );
		$dictionary->method( 'insert' )->willReturn( true );

		return $this->makeLinkTranslatorWithMocks( $configValues, $deepL, $dictionary );
	}

	/**
	 * @param array $configValues
	 * @param DeepLTranslator $deepL
	 * @param IDictionary $dictionary
	 * @return LinkTranslator
	 */
	private function makeLinkTranslatorWithMocks(
		array $configValues,
		DeepLTranslator $deepL,
		IDictionary $dictionary
	): LinkTranslator {
		$defaults = [
			'translatePageTitle' => false,
			'translateNamespaces' => false,
			'namespaceMap' => [],
		];
		$configValues = array_merge( $defaults, $configValues );

		$config = new HashConfig( $configValues );

		$services = \MediaWiki\MediaWikiServices::getInstance();
		$titleFactory = $services->getTitleFactory();
		$languageFactory = $services->getLanguageFactory();

		$translator = new LinkTranslator(
			$config, $deepL, $dictionary, $titleFactory, $languageFactory
		);

		return $translator;
	}
}
