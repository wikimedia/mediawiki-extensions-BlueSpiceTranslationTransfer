<?php

namespace BlueSpice\TranslationTransfer\Tests\Dictionary;

use BlueSpice\TranslationTransfer\Dictionary\TitleDictionary;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use PHPUnit\Framework\TestCase;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\ILoadBalancer;

/**
 * @covers \BlueSpice\TranslationTransfer\Dictionary\TitleDictionary
 */
class TitleDictionaryTest extends TestCase {

	/**
	 * @var string
	 */
	private $table = 'bs_tt_dictionary_title';

	/**
	 * Regular case when translation exists.
	 *
	 * @covers \BlueSpice\TranslationTransfer\Dictionary\TitleDictionary::get()
	 */
	public function testGetTranslation(): void {
		$sourceTitle = 'EN source title';
		$title = Title::newFromText( $sourceTitle );
		$targetLang = 'de';

		$expectedTranslatedTitle = 'DE translated title';

		$dbMock = $this->createMock( IDatabase::class );
		$dbMock->method( 'selectField' )->willReturn( $expectedTranslatedTitle );

		$dbMock->expects( $this->once() )->method( 'selectField' )->with(
			$this->table,
			[ 'tt_dt_translation' ],
			[
				'tt_dt_source_ns_id' => $title->getNamespace(),
				'tt_dt_source_text' => $title->getText(),
				'tt_dt_lang' => $targetLang,
			]
		);

		$lbMock = $this->createMock( ILoadBalancer::class );
		$lbMock->method( 'getConnection' )->willReturn( $dbMock );

		$titleDictionary = new TitleDictionary( $lbMock, new TitleFactory() );
		$actualTranslatedTitle = $titleDictionary->get( $sourceTitle, $targetLang );

		$this->assertEquals( $expectedTranslatedTitle, $actualTranslatedTitle );
	}

	/**
	 * Case when translation for the source title does not exist yet.
	 *
	 * @covers \BlueSpice\TranslationTransfer\Dictionary\TitleDictionary::get()
	 */
	public function testGetTranslationNotExists(): void {
		$sourceTitle = 'EN source title';
		$title = Title::newFromText( $sourceTitle );
		$targetLang = 'de';

		$dbMock = $this->createMock( IDatabase::class );
		// Assume that we did not find translation
		$dbMock->method( 'selectField' )->willReturn( false );

		$dbMock->expects( $this->once() )->method( 'selectField' )->with(
			$this->table,
			[ 'tt_dt_translation' ],
			[
				'tt_dt_source_ns_id' => $title->getNamespace(),
				'tt_dt_source_text' => $title->getText(),
				'tt_dt_lang' => $targetLang,
			]
		);

		$lbMock = $this->createMock( ILoadBalancer::class );
		$lbMock->method( 'getConnection' )->willReturn( $dbMock );

		$titleDictionary = new TitleDictionary( $lbMock, new TitleFactory() );
		$actualTranslatedTitle = $titleDictionary->get( $sourceTitle, $targetLang );

		// If translation does not exist - we'll get "null"
		$this->assertNull( $actualTranslatedTitle );
	}
}
