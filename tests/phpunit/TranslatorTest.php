<?php

namespace BlueSpice\TranslationTransfer\Tests;

use BlueSpice\TranslationTransfer\DeepL;
use BlueSpice\TranslationTransfer\Dictionary\TitleDictionary;
use BlueSpice\TranslationTransfer\TranslationWikitextConverter;
use BlueSpice\TranslationTransfer\Translator;
use BlueSpice\TranslationTransfer\Util\TranslationsDao;
use MediaWiki\Content\WikitextContent;
use MediaWiki\Context\RequestContext;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use PHPUnit\Framework\TestCase;
use StatusValue;
use WikiPage;

/**
 * @covers \BlueSpice\TranslationTransfer\Translator
 */
class TranslatorTest extends TestCase {

	/**
	 * @param string $sourceLang
	 * @param string $targetLang
	 * @param string $titleText Title text which should also be translated (or not, depends on configuration)
	 * @param string $textContent Initial wikitext content of the title which is translated
	 * @param string $expectedTitleText Expected translated title text
	 * @param string $expectedTextContentWikitext
	 * 		Translated wiki page text content after "post-translation processing" and converting back to wikitext
	 * @return void
	 *
	 * @dataProvider provideTranslateData
	 */
	public function testTranslateTitle(
		string $sourceLang, string $targetLang,
		string $titleText, string $textContent,
		string $expectedTitleText,
		string $expectedTextContentWikitext
	): void {
		// Unfortunately, there is no easy way to avoid API calls in "Translator"
		// So to get "read" access to the wiki to be able to execute that API - we need to set a context user
		$systemUser = User::newSystemUser( 'BSMaintenance' );
		RequestContext::getMain()->setUser( $systemUser );

		// Here we mock translation which we could receive from DeepL
		$expectedTranslationRes = [
			$targetLang => [
				'title' => $expectedTitleText,
				'text' => $expectedTextContentWikitext
			]
		];

		$translationResStatus = StatusValue::newGood( $expectedTranslationRes );

		$deeplMock = $this->createMock( DeepL::class );
		$deeplMock->method( 'translate' )->willReturn( $translationResStatus );

		// We do not check work of "TranslationWikitextConverter" here, as it has separate unit test
		// So just mock methods below, without any processing, as that is not a purpose of this unit test
		$wtConverterMock = $this->createMock( TranslationWikitextConverter::class );
		$wtConverterMock->method( 'preTranslationProcessing' )->willReturnCallback(
			static function ( $wikitext, $lang ) {
				return $wikitext;
			}
		);

		$wtConverterMock->method( 'postTranslationProcessing' )->willReturnCallback(
			static function ( $translateData ) {
				return $translateData['wikitext'];
			}
		);

		$wtConverterMock->method( 'getTitleText' )->willReturnCallback(
			static function ( $original, $translated, $lang ) {
				return [ $translated, false ];
			}
		);

		$contentMock = $this->createMock( WikitextContent::class );
		$contentMock->method( 'getText' )->willReturn( $textContent );

		$wikiPageMock = $this->createMock( WikiPage::class );
		$wikiPageMock->method( 'getContent' )->willReturn( $contentMock );

		$wikiPageFactoryMock = $this->createMock( WikiPageFactory::class );
		$wikiPageFactoryMock->method( 'newFromTitle' )->willReturn( $wikiPageMock );

		$translationsDaoMock = $this->createMock( TranslationsDao::class );
		$translationsDaoMock->method( 'getSourceFromTarget' )->willReturn( null );

		$titleDictionaryMock = $this->createMock( TitleDictionary::class );

		$translator = new Translator( $deeplMock, $wtConverterMock, $wikiPageFactoryMock, $translationsDaoMock, $titleDictionaryMock );

		$titleMock = $this->createMock( Title::class );
		$titleMock->method( 'exists' )->willReturn( true );

		$translationRes = $translator->translateTitle( $titleMock, $targetLang );

		$this->assertEquals( $expectedTitleText, $translationRes['title'] );
		$this->assertEquals( $expectedTextContentWikitext, $translationRes['wikitext'] );
	}

	/**
	 * @return array
	 */
	public function provideTranslateData(): array {
		return [
			'Regular translation test case' => [
				'en', 'de',
				'English title A',
				"English wikitext '''content''' A",
				'German title A',
				"German wikitext '''content''' A",
			]
		];
	}
}
