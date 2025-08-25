<?php

namespace BlueSpice\TranslationTransfer\Tests\Util;

use BlueSpice\TranslationTransfer\Util\TranslationsDao;
use PHPUnit\Framework\TestCase;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\ILoadBalancer;

/**
 * @covers \BlueSpice\TranslationTransfer\Util\TranslationsDao
 */
class TranslationsDaoTest extends TestCase {

	/**
	 * @var string
	 */
	private $table = 'bs_translationtransfer_translations';

	/**
	 * @var string
	 */
	private $targetLang = 'en';

	/**
	 * @var string
	 */
	private $targetPrefixedTitle = 'Demo:Test_title';

	/**
	 * @var string
	 */
	private $targetNormalizedTitle = 'demo:test title';

	/**
	 * @var string
	 */
	private $sourceLang = 'de';

	/**
	 * @var string
	 */
	private $sourcePrefixedTitle = 'Draft:Demo:Translated_test_title';

	/**
	 * @var string
	 */
	private $sourceNormalizedTitle = 'draft:demo:translated test title';

	/**
	 * @return array[]
	 */
	public function provideIsSourceTestData(): array {
		return [
			'Source page' => [
				$this->sourcePrefixedTitle, $this->sourceLang, $this->sourcePrefixedTitle, true
			],
			'Target page' => [
				$this->targetPrefixedTitle, $this->targetLang, false, false
			]
		];
	}

	/**
	 * @covers \BlueSpice\TranslationTransfer\Util\TranslationsDao::isSource
	 * @dataProvider provideIsSourceTestData
	 */
	public function testIsSource( $prefixedTitle, $lang, $dbSelectRes, $expectedRes ) {
		$lb = $this->mockLoadBalancer();
		$lb->getConnection( DB_REPLICA )->method( 'selectField' )->willReturn( $dbSelectRes );

		$lb->getConnection( DB_REPLICA )->expects( $this->once() )->method( 'selectField' )->with(
			$this->table,
			'tt_translations_source_prefixed_title_key',
			[
				'tt_translations_source_prefixed_title_key' => $prefixedTitle,
				'tt_translations_source_lang' => strtoupper( $lang )
			]
		);

		$dao = new TranslationsDao( $lb );
		$isSource = $dao->isSource( $lang, $prefixedTitle );

		$this->assertEquals( $expectedRes, $isSource );
	}

	/**
	 * @return array[]
	 */
	public function provideIsTargetTestData(): array {
		return [
			'Source page' => [
				$this->sourcePrefixedTitle, $this->sourceLang, false, false
			],
			'Target page' => [
				$this->targetPrefixedTitle, $this->targetLang, $this->targetPrefixedTitle, true
			]
		];
	}

	/**
	 * @covers \BlueSpice\TranslationTransfer\Util\TranslationsDao::isTarget
	 * @dataProvider provideIsTargetTestData
	 */
	public function testIsTarget( $prefixedTitle, $lang, $dbSelectRes, $expectedRes ) {
		$lb = $this->mockLoadBalancer();
		$lb->getConnection( DB_REPLICA )->method( 'selectField' )->willReturn( $dbSelectRes );

		$lb->getConnection( DB_REPLICA )->expects( $this->once() )->method( 'selectField' )->with(
			$this->table,
			'tt_translations_target_prefixed_title_key',
			[
				'tt_translations_target_prefixed_title_key' => $prefixedTitle,
				'tt_translations_target_lang' => strtoupper( $lang )
			]
		);

		$dao = new TranslationsDao( $lb );
		$isTarget = $dao->isTarget( $lang, $prefixedTitle );

		$this->assertEquals( $expectedRes, $isTarget );
	}

	/**
	 * @covers \BlueSpice\TranslationTransfer\Util\TranslationsDao::updateTranslation
	 */
	public function testUpdateTranslation() {
		$newSourceLastChangeTimestamp = '20230926020202';

		$row = [
			'tt_translations_source_prefixed_title_key' => $this->sourcePrefixedTitle,
			'tt_translations_source_normalized_title' => $this->sourceNormalizedTitle,
			'tt_translations_source_lang' => strtoupper( $this->sourceLang ),
			'tt_translations_target_prefixed_title_key' => $this->targetPrefixedTitle,
			'tt_translations_target_normalized_title' => $this->targetNormalizedTitle,
			'tt_translations_target_lang' => strtoupper( $this->targetLang ),
			// TODO: Won't we have problems with that timestamp in test?
			'tt_translations_release_date' => wfTimestamp( TS_MW ),
			'tt_translations_source_last_change_date' => $newSourceLastChangeTimestamp,
			'tt_translations_target_last_change_date' => ''
		];

		$lb = $this->mockLoadBalancer();
		$lb->getConnection( DB_PRIMARY )->expects( $this->once() )->method( 'upsert' )->with(
			$this->table,
			$row,
			[
				[
					'tt_translations_target_prefixed_title_key',
					'tt_translations_target_lang'
				],
			],
			$row
		);

		$dao = new TranslationsDao( $lb );
		$dao->updateTranslation(
			$this->sourceLang, $this->sourcePrefixedTitle,
			$this->targetLang, $this->targetPrefixedTitle,
			$newSourceLastChangeTimestamp
		);
	}

	/**
	 * @covers \BlueSpice\TranslationTransfer\Util\TranslationsDao::updateTranslationTarget
	 */
	public function testUpdateTranslationTarget() {
		$newPrefixedTitle = 'Demo:Moved_Target_page';
		$newNormalizedTitle = 'demo:moved target page';

		$lb = $this->mockLoadBalancer();
		$lb->getConnection( DB_PRIMARY )->expects( $this->once() )->method( 'update' )->with(
			$this->table,
			[
				'tt_translations_target_prefixed_title_key' => $newPrefixedTitle,
				'tt_translations_target_normalized_title' => $newNormalizedTitle
			],
			[
				'tt_translations_target_prefixed_title_key' => $this->targetPrefixedTitle,
				'tt_translations_target_lang' => strtoupper( $this->targetLang )
			]
		);

		$dao = new TranslationsDao( $lb );
		$dao->updateTranslationTarget(
			$this->targetPrefixedTitle, $this->targetLang,
			$newPrefixedTitle
		);
	}

	/**
	 * @covers \BlueSpice\TranslationTransfer\Util\TranslationsDao::updateTranslationSource
	 */
	public function testUpdateTranslationSource() {
		$newPrefixedTitle = 'Demo:Moved_Source_page';
		$newNormalizedTitle = 'demo:moved source page';

		$lb = $this->mockLoadBalancer();
		$lb->getConnection( DB_PRIMARY )->expects( $this->once() )->method( 'update' )->with(
			$this->table,
			[
				'tt_translations_source_prefixed_title_key' => $newPrefixedTitle,
				'tt_translations_source_normalized_title' => $newNormalizedTitle
			],
			[
				'tt_translations_source_prefixed_title_key' => $this->sourcePrefixedTitle,
				'tt_translations_source_lang' => strtoupper( $this->sourceLang )
			]
		);

		$dao = new TranslationsDao( $lb );
		$dao->updateTranslationSource(
			$this->sourcePrefixedTitle, $this->sourceLang,
			$newPrefixedTitle
		);
	}

	/**
	 * @covers \BlueSpice\TranslationTransfer\Util\TranslationsDao::updateTranslationSourceLastChange
	 */
	public function testUpdateTranslationSourceLastChange() {
		$newSourceLastChangeTimestamp = '20230824010101';

		$lb = $this->mockLoadBalancer();
		$lb->getConnection( DB_PRIMARY )->expects( $this->once() )->method( 'update' )->with(
			$this->table,
			[
				'tt_translations_source_last_change_date' => $newSourceLastChangeTimestamp,
				'tt_translations_translation_acked' => 0
			],
			[
				'tt_translations_source_prefixed_title_key' => $this->sourcePrefixedTitle,
				'tt_translations_source_lang' => strtoupper( $this->sourceLang )
			]
		);

		$dao = new TranslationsDao( $lb );
		$dao->updateTranslationSourceLastChange(
			$this->sourcePrefixedTitle, $this->sourceLang,
			$newSourceLastChangeTimestamp
		);
	}

	/**
	 * @covers \BlueSpice\TranslationTransfer\Util\TranslationsDao::updateTranslationTargetLastChange
	 */
	public function testUpdateTranslationTargetLastChange() {
		$newTargetLastChangeTimestamp = '20230824010101';

		$lb = $this->mockLoadBalancer();
		$lb->getConnection( DB_PRIMARY )->expects( $this->once() )->method( 'update' )->with(
			$this->table,
			[
				'tt_translations_target_last_change_date' => $newTargetLastChangeTimestamp,
			],
			[
				'tt_translations_target_prefixed_title_key' => $this->targetPrefixedTitle,
				'tt_translations_target_lang' => strtoupper( $this->targetLang )
			]
		);

		$dao = new TranslationsDao( $lb );
		$dao->updateTranslationTargetLastChange(
			$this->targetPrefixedTitle, $this->targetLang,
			$newTargetLastChangeTimestamp
		);
	}

	/**
	 * @covers \BlueSpice\TranslationTransfer\Util\TranslationsDao::updateTranslationReleaseTimestamp
	 */
	public function testUpdateTranslationReleaseTimestamp() {
		$translationReleaseTimestamp = '20240824010101';

		$lb = $this->mockLoadBalancer();
		$lb->getConnection( DB_PRIMARY )->expects( $this->once() )->method( 'update' )->with(
			$this->table,
			[
				'tt_translations_release_date' => $translationReleaseTimestamp
			],
			[
				'tt_translations_target_prefixed_title_key' => $this->targetPrefixedTitle,
				'tt_translations_target_lang' => strtoupper( $this->targetLang )
			]
		);

		$dao = new TranslationsDao( $lb );
		$dao->updateTranslationReleaseTimestamp(
			$this->targetPrefixedTitle, $this->targetLang,
			$translationReleaseTimestamp
		);
	}

	/**
	 * @covers \BlueSpice\TranslationTransfer\Util\TranslationsDao::ackTranslation
	 */
	public function testAckTranslation() {
		$lb = $this->mockLoadBalancer();
		$lb->getConnection( DB_PRIMARY )->expects( $this->once() )->method( 'update' )->with(
			$this->table,
			[
				'tt_translations_translation_acked' => 1
			],
			[
				'tt_translations_target_prefixed_title_key' => $this->targetPrefixedTitle,
				'tt_translations_target_lang' => strtoupper( $this->targetLang )
			]
		);

		$dao = new TranslationsDao( $lb );
		$dao->ackTranslation(
			$this->targetPrefixedTitle, $this->targetLang
		);
	}

	/**
	 * @return array[]
	 */
	public function provideIsTranslationAckedTestData(): array {
		return [
			'Translation is acked' => [
				1, true
			],
			'Translation is not acked' => [
				0, false
			]
		];
	}

	/**
	 * @covers \BlueSpice\TranslationTransfer\Util\TranslationsDao::isTranslationAcked
	 * @dataProvider provideIsTranslationAckedTestData
	 */
	public function testIsTranslationAcked( $dbSelectRes, $expectedRes ) {
		$lb = $this->mockLoadBalancer();
		$lb->getConnection( DB_REPLICA )->method( 'selectField' )->willReturn( $dbSelectRes );

		$lb->getConnection( DB_REPLICA )->expects( $this->once() )->method( 'selectField' )->with(
			$this->table,
			'tt_translations_translation_acked',
			[
				'tt_translations_target_prefixed_title_key' => $this->targetPrefixedTitle,
				'tt_translations_target_lang' => strtoupper( $this->targetLang )
			]
		);

		$dao = new TranslationsDao( $lb );
		$actualRes = $dao->isTranslationAcked(
			$this->targetPrefixedTitle, $this->targetLang
		);

		$this->assertEquals( $expectedRes, $actualRes );
	}

	/**
	 * @covers \BlueSpice\TranslationTransfer\Util\TranslationsDao::removeTranslation
	 */
	public function testRemoveTranslation() {
		$lb = $this->mockLoadBalancer();
		$lb->getConnection( DB_PRIMARY )->expects( $this->once() )->method( 'delete' )->with(
			$this->table,
			[
				'tt_translations_target_prefixed_title_key' => $this->targetPrefixedTitle,
				'tt_translations_target_lang' => strtoupper( $this->targetLang )
			]
		);

		$dao = new TranslationsDao( $lb );
		$dao->removeTranslation(
			$this->targetPrefixedTitle, $this->targetLang,
		);
	}

	/**
	 * @covers \BlueSpice\TranslationTransfer\Util\TranslationsDao::removeAllSourceTranslations
	 */
	public function testRemoveAllSourceTranslations() {
		$lb = $this->mockLoadBalancer();
		$lb->getConnection( DB_PRIMARY )->expects( $this->once() )->method( 'delete' )->with(
			$this->table,
			[
				'tt_translations_source_prefixed_title_key' => $this->sourcePrefixedTitle,
				'tt_translations_source_lang' => strtoupper( $this->sourceLang )
			]
		);

		$dao = new TranslationsDao( $lb );
		$dao->removeAllSourceTranslations(
			$this->sourcePrefixedTitle, $this->sourceLang,
		);
	}

	/**
	 * @covers \BlueSpice\TranslationTransfer\Util\TranslationsDao::getSourceFromTarget
	 */
	public function testGetTranslation() {
		$expectedRes = [
			'tt_translations_source_prefixed_title_key' => $this->sourcePrefixedTitle,
			'tt_translations_source_normalized_title' => $this->sourceNormalizedTitle,
			'tt_translations_source_lang' => strtoupper( $this->sourceLang ),
			'tt_translations_target_prefixed_title_key' => $this->targetPrefixedTitle,
			'tt_translations_target_normalized_title' => $this->targetNormalizedTitle,
			'tt_translations_target_lang' => strtoupper( $this->targetLang ),
			'tt_translations_release_date' => '20230926030303',
			'tt_translations_source_last_change_date' => '20230926020202',
			'tt_translations_target_last_change_date' => '20240926020202'
		];

		$lb = $this->mockLoadBalancer();
		$lb->getConnection( DB_REPLICA )->method( 'selectRow' )->willReturn(
			(object)$expectedRes
		);
		$lb->getConnection( DB_REPLICA )->expects( $this->once() )->method( 'selectRow' )->with(
			$this->table,
			[
				'tt_translations_source_prefixed_title_key',
				'tt_translations_source_normalized_title',
				'tt_translations_source_lang',
				'tt_translations_target_prefixed_title_key',
				'tt_translations_target_normalized_title',
				'tt_translations_target_lang',
				'tt_translations_release_date',
				'tt_translations_source_last_change_date',
				'tt_translations_target_last_change_date'
			],
			[
				'tt_translations_target_prefixed_title_key' => $this->targetPrefixedTitle,
				'tt_translations_target_lang' => strtoupper( $this->targetLang )
			]
		);

		$dao = new TranslationsDao( $lb );
		$actualRes = $dao->getTranslation(
			$this->targetPrefixedTitle, $this->targetLang
		);

		$this->assertEquals( $expectedRes, $actualRes );
	}

	/**
	 * @covers \BlueSpice\TranslationTransfer\Util\TranslationsDao::getSourceFromTarget
	 */
	public function testGetSourceFromTarget() {
		$expectedRes = [
			'key' => $this->sourcePrefixedTitle,
			'lang' => $this->sourceLang
		];

		$lb = $this->mockLoadBalancer();
		$lb->getConnection( DB_REPLICA )->method( 'selectRow' )->willReturn(
			(object)[
				'tt_translations_source_prefixed_title_key' => $this->sourcePrefixedTitle,
				'tt_translations_source_lang' => $this->sourceLang
			]
		);
		$lb->getConnection( DB_REPLICA )->expects( $this->once() )->method( 'selectRow' )->with(
			$this->table,
			[
				'tt_translations_source_prefixed_title_key',
				'tt_translations_source_lang'
			],
			[
				'tt_translations_target_prefixed_title_key' => $this->targetPrefixedTitle,
				'tt_translations_target_lang' => strtoupper( $this->targetLang )
			]
		);

		$dao = new TranslationsDao( $lb );
		$actualRes = $dao->getSourceFromTarget(
			$this->targetPrefixedTitle, $this->targetLang
		);

		$this->assertEquals( $expectedRes, $actualRes );
	}

	/**
	 * @covers \BlueSpice\TranslationTransfer\Util\TranslationsDao::getSourceLastChangeTimestamp
	 */
	public function testGetSourceLastChangeTimestamp() {
		$currentSourceLastChangeTimestamp = '20230926020202';

		$lb = $this->mockLoadBalancer();
		$lb->getConnection( DB_REPLICA )->method( 'selectField' )->willReturn(
			$currentSourceLastChangeTimestamp
		);
		$lb->getConnection( DB_REPLICA )->expects( $this->once() )->method( 'selectField' )->with(
			$this->table,
			'tt_translations_source_last_change_date',
			[
				'tt_translations_target_prefixed_title_key' => $this->targetPrefixedTitle,
				'tt_translations_target_lang' => strtoupper( $this->targetLang )
			]
		);

		$dao = new TranslationsDao( $lb );
		$currentTimestamp = $dao->getSourceLastChangeTimestamp(
			$this->targetPrefixedTitle, $this->targetLang
		);

		$this->assertEquals( $currentSourceLastChangeTimestamp, $currentTimestamp );
	}

	/**
	 * @covers \BlueSpice\TranslationTransfer\Util\TranslationsDao::getSourceTranslations
	 */
	public function testGetSourceTranslations() {
		$expectedTranslations = [
			'it' => [
				'target_prefixed_key' => 'Some title A',
				'release_date' => '20230926020202',
				'last_change_date' => '20240926020202'
			],
			'fr' => [
				'target_prefixed_key' => 'Some title B',
				'release_date' => '20231026020202',
				'last_change_date' => '20241026020202'
			]
		];

		$lb = $this->mockLoadBalancer();
		$lb->getConnection( DB_REPLICA )->method( 'select' )->willReturn(
			[
				(object)[
					'tt_translations_target_prefixed_title_key' => 'Some title A',
					'tt_translations_target_lang' => 'IT',
					'tt_translations_release_date' => '20230926020202',
					'tt_translations_source_last_change_date' => '20240926020202'
				],
				(object)[
					'tt_translations_target_prefixed_title_key' => 'Some title B',
					'tt_translations_target_lang' => 'FR',
					'tt_translations_release_date' => '20231026020202',
					'tt_translations_source_last_change_date' => '20241026020202'
				]
			]
		);
		$lb->getConnection( DB_REPLICA )->expects( $this->once() )->method( 'select' )->with(
			$this->table,
			[
				'tt_translations_target_prefixed_title_key',
				'tt_translations_target_lang',
				'tt_translations_release_date',
				'tt_translations_source_last_change_date'
			],
			[
				'tt_translations_source_prefixed_title_key' => $this->sourcePrefixedTitle,
				'tt_translations_source_lang' => strtoupper( $this->sourceLang )
			]
		);

		$dao = new TranslationsDao( $lb );
		$actualTranslations = $dao->getSourceTranslations(
			$this->sourcePrefixedTitle, $this->sourceLang
		);

		$this->assertEquals( $expectedTranslations, $actualTranslations );
	}

	/**
	 * @covers \BlueSpice\TranslationTransfer\Util\TranslationsDao::getReleaseTimestamp
	 */
	public function testGetReleaseTimestamp() {
		$releaseTimestamp = '20220624060303';

		$lb = $this->mockLoadBalancer();
		$lb->getConnection( DB_REPLICA )->method( 'selectField' )->willReturn(
			$releaseTimestamp
		);
		$lb->getConnection( DB_PRIMARY )->expects( $this->once() )->method( 'selectField' )->with(
			$this->table,
			'tt_translations_release_date',
			[
				'tt_translations_target_prefixed_title_key' => $this->targetPrefixedTitle,
				'tt_translations_target_lang' => strtoupper( $this->targetLang )
			]
		);

		$dao = new TranslationsDao( $lb );
		$dao->getReleaseTimestamp(
			$this->targetPrefixedTitle, $this->targetLang
		);
	}

	private function mockLoadBalancer() {
		$db = $this->createMock( IDatabase::class );

		$lb = $this->createMock( ILoadBalancer::class );
		$lb->method( 'getConnection' )->willReturn( $db );

		return $lb;
	}
}
