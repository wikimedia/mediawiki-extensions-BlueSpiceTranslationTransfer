<?php

namespace BlueSpice\TranslationTransfer\Tests\Util;

use BlueSpice\TranslationTransfer\TranslationWikitextConverter;
use BlueSpice\TranslationTransfer\Util\ContentTransfer\TargetRecognizer;
use BlueSpice\TranslationTransfer\Util\TranslationPusher;
use BlueSpice\TranslationTransfer\Util\TranslationsDao;
use ContentTransfer\AuthenticatedRequestHandler;
use ContentTransfer\AuthenticatedRequestHandlerFactory;
use ContentTransfer\PageContentProvider;
use ContentTransfer\PageContentProviderFactory;
use ContentTransfer\Target;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\RevisionStore;
use MediaWiki\Status\Status;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Wikimedia\Rdbms\ILoadBalancer;

/**
 * @covers \BlueSpice\TranslationTransfer\Util\TranslationPusher
 */
class TranslationPusherTest extends TestCase {
	/**
	 * @var MockObject|TargetRecognizer
	 */
	private MockObject|TargetRecognizer $targetRecognizer;

	/**
	 * @var MockObject|TranslationsDao
	 */
	private MockObject|TranslationsDao $translationsDao;

	/** @var TranslationPusher */
	private TranslationPusher $translationPusher;

	/**
	 * @var int
	 */
	private int $now;

	public function setUp(): void {
		$services = MediaWikiServices::getInstance();

		$this->now = time();

		$revisionRecord = $this->createMock( RevisionRecord::class );
		$revisionRecord->method( 'getTimestamp' )->willReturn( $this->now );
		$revisionStore = $this->createMock( RevisionStore::class );
		$revisionStore->method( 'getRevisionByTitle' )->willReturn( $revisionRecord );

		$requestHandler = $this->createMock( AuthenticatedRequestHandler::class );
		$requestHandler->method( 'runAuthenticatedRequest' )->willReturn(
			Status::newGood( [ 'edit' => [ 'pageid' => 1 ] ] )
		);
		$authenticatedRequestHandlerFactory = $this->createMock( AuthenticatedRequestHandlerFactory::class );
		$authenticatedRequestHandlerFactory->method( 'newFromTarget' )->willReturn( $requestHandler );

		$pageContentProvider = $this->getMockBuilder( PageContentProvider::class )
			->disableOriginalConstructor()
			->getMock();
		$pageContentProvider->method( 'getRelatedTitles' )->willReturn( [] );
		$pageContentProvider->method( 'getTranscluded' )->willReturn( [] );
		$pageContentProviderFactory = $this->createMock( PageContentProviderFactory::class );
		$pageContentProviderFactory->method( 'newFromTitle' )->willReturn( $pageContentProvider );

		$this->targetRecognizer = $this->createMock( TargetRecognizer::class );
		$this->translationsDao = $this->createMock( TranslationsDao::class );
		$loadBalancer = $this->createMock( ILoadBalancer::class );
		$titleFactory = $this->createMock( TitleFactory::class );
		$wikiPageFactory = $this->createMock( WikiPageFactory::class );
		$wtConverter = $this->createMock( TranslationWikitextConverter::class );

		$this->translationPusher = new TranslationPusher(
			$services->getConfigFactory()->makeConfig( 'bsg' ),
			$this->targetRecognizer,
			$services->getHookContainer(),
			$revisionStore,
			$this->translationsDao,
			$authenticatedRequestHandlerFactory,
			$pageContentProviderFactory,
			$loadBalancer,
			$titleFactory,
			$wikiPageFactory,
			$wtConverter
		);
	}

	/**
	 * @covers \BlueSpice\TranslationTransfer\Util\TranslationPusher::push
	 */
	public function testPush(): void {
		$sourceLang = 'de';
		$targetLang = 'en';

		$wikitext = 'Test content';
		$targetTitleDbKey = 'TargetTitleTestPage';
		$target = Target::newFromData( 'key', $this->getTargetFixtureData() );
		$sourceTitle = Title::newFromText( 'SourceTitleTestPage', NS_MAIN );

		$this->targetRecognizer->expects( $this->once() )
			->method( 'recognizeCurrentTarget' )
			->willReturn( [
				'key' => 'de',
				'lang' => 'de'
			] );
		$this->targetRecognizer->expects( $this->once() )
			->method( 'getLangToTargetKeyMap' )
			->willReturn( [
				'de' => 'deKey',
				'en' => 'enKey',
			] );
		$this->targetRecognizer->expects( $this->once() )
			->method( 'getTargetKeyFromTargetUrl' )
			->with( $target->getUrl() )
			->willReturn( 'enKey' );

		// Test updateTranslation method arguments
		$this->translationsDao->expects( $this->once() )->method( 'updateTranslation' )->with(
			$sourceLang,
			$sourceTitle->getPrefixedDBkey(),
			$targetLang,
			$targetTitleDbKey,
			$this->now
		);

		$status = $this->translationPusher->push(
			$wikitext,
			$targetTitleDbKey,
			$sourceTitle,
			$target
		);

		$this->assertTrue( $status->isOK() );
	}

	private function getTargetFixtureData(): array {
		return [
			'url' => 'http://dummy',
			'users' => [
				[
					'user' => 'name@user',
					'password' => '3893883',
				],
				[
					'user' => 'user2@user',
					'password' => '3893883asd',
				]
			],
			'pushToDraft' => true,
			'draftNamespace' => 'Draft',
			'displayText' => 'Dummy'
		];
	}
}
