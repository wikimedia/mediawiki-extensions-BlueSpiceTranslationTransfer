<?php

namespace BlueSpice\TranslationTransfer\Tests;

use BlueSpice\TranslationTransfer\DeepL;
use BlueSpice\TranslationTransfer\Dictionary\TitleDictionary;
use BlueSpice\TranslationTransfer\TranslationWikitextConverter;
use MediaWiki\Config\HashConfig;
use MediaWiki\Status\Status;
use MediaWiki\Title\Title;
use PHPUnit\Framework\TestCase;

/**
 * @covers \BlueSpice\TranslationTransfer\TranslationWikitextConverter
 */
class TranslationWikitextConverterTest extends TestCase {

	/**
	 * @return array
	 */
	public function provideDataForPreProcessing(): array {
		return [
			'Regular case' => [
				[
					'translatePageTitle' => true,
					'translateNamespaces' => false,
					'translateMagicWords' => false,
					'namespaceMap' => [
						3000 => [
							'en' => 'Demo_EN',
							'de' => 'Demo_DE'
						]
					]
				],
				<<<HERE
Some content and [[Page A EN|link]] to some [[Demo_EN:Page B EN|page]]
HERE
,
				'de',
				<<<HERE
Some content and <deepl:ignore>[[Page A DE|</deepl:ignore>link<deepl:ignore>]]</deepl:ignore> to some <deepl:ignore>[[Demo_EN:Page B DE|</deepl:ignore>page<deepl:ignore>]]</deepl:ignore>
HERE
			]
			// TODO Escape wikitext, Translate categories, Translate magic words
		];
	}

	/**
	 * @covers \BlueSpice\TranslationTransfer\TranslationWikitextConverter::preTranslationProcessing
	 * @dataProvider provideDataForPreProcessing
	 */
	public function testPreTranslationProcessing(
		array $translateConversionConfig,
		string $wikitext, string $lang,
		string $expectedWikitext
	) {
		$config = new HashConfig( $translateConversionConfig );

		$deepLMock = $this->createMock( DeepL::class );
		$deepLMock->method( 'extractSourceLanguage' )->willReturn( 'en' );
		$deepLMock->method( 'translateText' )
			->willReturnCallback( static function ( $text, $source, $target, $options = [] ) {
				switch ( $text ) {
					case 'Page A EN':
						return Status::newGood( 'Page A DE' );
					case 'Page B EN':
					case 'Demo EN:Page B EN':
						return Status::newGood( 'Page B DE' );
					default:
						return Status::newGood( $text );
				}
			} );

		$dictionaryMock = $this->createMock( TitleDictionary::class );
		$dictionaryMock->method( 'get' )->willReturnMap(
			[
				[ 'Page_A_EN', 'de', 'Page A DE' ],
				[ 'Page_B_EN', 'de', 'Page B DE' ],
			]
		);

		$wikitextConverter = new TranslationWikitextConverter( $config, $deepLMock, $dictionaryMock );
		$actualWikitext = $wikitextConverter->preTranslationProcessing( $wikitext, $lang );

		$this->assertEquals( $expectedWikitext, $actualWikitext );
	}

	/**
	 * @group Broken
	 * @covers \BlueSpice\TranslationTransfer\TranslationWikitextConverter::postTranslationProcessing
	 */
	public function testPostTranslationProcessing() {
	}

	/**
	 * @group Broken
	 * @covers \BlueSpice\TranslationTransfer\TranslationWikitextConverter::getTitleText
	 * @dataProvider provideGetTitleTextData
	 */
	public function testGetTitleText(
		array $translateConversionConfig,
		string $originalTitleText,
		string $translatedTitle, string $lang,
		string $expectedTitleTranslation
	) {
		$config = new HashConfig( $translateConversionConfig );

		$deepLMock = $this->createMock( DeepL::class );
		$deepLMock->method( 'extractSourceLanguage' )->willReturn( 'en' );

		$dictionaryMock = $this->createMock( TitleDictionary::class );
		$dictionaryMock->method( 'get' )->willReturnMap(
			[
				[ '' ]
			]
		);

		$title = Title::newFromText( $originalTitleText );

		$wikitextConverter = new TranslationWikitextConverter( $config, $deepLMock, $dictionaryMock );
		$actualTitleTranslation = $wikitextConverter->getTitleText( $title, $translatedTitle, $lang );

		$this->assertEquals( $expectedTitleTranslation, $actualTitleTranslation );
	}

	/**
	 * @return array[]
	 */
	public function provideGetTitleTextData(): array {
		return [
			'Regular case' => []
		];
	}
}
