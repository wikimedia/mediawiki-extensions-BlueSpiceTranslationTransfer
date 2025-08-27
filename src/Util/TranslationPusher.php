<?php

namespace BlueSpice\TranslationTransfer\Util;

use BlueSpice\TranslationTransfer\TranslationWikitextConverter;
use BlueSpice\TranslationTransfer\Util\ContentTransfer\TargetRecognizer;
use ContentTransfer\AuthenticatedRequestHandler;
use ContentTransfer\AuthenticatedRequestHandlerFactory;
use ContentTransfer\PageContentProviderFactory;
use ContentTransfer\Target;
use MediaWiki\Config\Config;
use MediaWiki\Content\TextContent;
use MediaWiki\HookContainer\HookContainer;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Revision\RevisionStore;
use MediaWiki\Status\Status;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Wikimedia\Rdbms\ILoadBalancer;

class TranslationPusher implements LoggerAwareInterface {

	/** @var Config */
	private Config $config;

	/** @var TargetRecognizer */
	private TargetRecognizer $targetRecognizer;

	/** @var HookContainer */
	private HookContainer $hookContainer;

	/** @var RevisionStore */
	private RevisionStore $revisionStore;

	/** @var AuthenticatedRequestHandlerFactory */
	private AuthenticatedRequestHandlerFactory $requestHandlerFactory;

	/** @var PageContentProviderFactory */
	private PageContentProviderFactory $pageContentProviderFactory;

	/** @var TranslationsDao */
	private TranslationsDao $translationsDao;

	/**
	 * @var ILoadBalancer
	 */
	private ILoadBalancer $loadBalancer;

	/**
	 * @var TitleFactory
	 */
	private $titleFactory;

	/**
	 * @var WikiPageFactory
	 */
	private $wikiPageFactory;

	/**
	 * @var TranslationWikitextConverter
	 */
	private $wtConverter;

	/**
	 * @var array
	 */
	private $blockedFileTransferCache = [];

	/**
	 * @var array
	 */
	private $args = [];

	/**
	 * @var LoggerInterface
	 */
	private $logger;

	/**
	 * @param Config $config
	 * @param TargetRecognizer $targetRecognizer
	 * @param HookContainer $hookContainer
	 * @param RevisionStore $revisionStore
	 * @param TranslationsDao $translationsDao
	 * @param AuthenticatedRequestHandlerFactory $requestHandlerFactory
	 * @param PageContentProviderFactory $pageContentProviderFactory
	 * @param ILoadBalancer $lb
	 * @param TitleFactory $titleFactory
	 * @param WikiPageFactory $wikiPageFactory
	 * @param TranslationWikitextConverter $wtConverter
	 */
	public function __construct(
		Config $config,
		TargetRecognizer $targetRecognizer,
		HookContainer $hookContainer,
		RevisionStore $revisionStore,
		TranslationsDao $translationsDao,
		AuthenticatedRequestHandlerFactory $requestHandlerFactory,
		PageContentProviderFactory $pageContentProviderFactory,
		ILoadBalancer $lb,
		TitleFactory $titleFactory,
		WikiPageFactory $wikiPageFactory,
		TranslationWikitextConverter $wtConverter
	) {
		$this->config = $config;
		$this->targetRecognizer = $targetRecognizer;
		$this->hookContainer = $hookContainer;
		$this->revisionStore = $revisionStore;
		$this->requestHandlerFactory = $requestHandlerFactory;
		$this->pageContentProviderFactory = $pageContentProviderFactory;
		$this->translationsDao = $translationsDao;
		$this->loadBalancer = $lb;
		$this->titleFactory = $titleFactory;
		$this->wikiPageFactory = $wikiPageFactory;
		$this->wtConverter = $wtConverter;

		$this->logger = new NullLogger();
	}

	/**
	 * @param LoggerInterface $logger
	 */
	public function setLogger( LoggerInterface $logger ): void {
		$this->logger = $logger;
	}

	/**
	 * @param array $args
	 * @return void
	 */
	public function setArgs( array $args ): void {
		$this->args = $args;
	}

	/**
	 * @param string $content
	 * @param string $targetTitlePrefixedKey
	 * @param Title $sourceTitle
	 * @param Target $target
	 *
	 * @return Status
	 */
	public function push(
		string $content,
		string $targetTitlePrefixedKey,
		Title $sourceTitle,
		Target $target
	): Status {
		$this->logger->debug(
			"Start pushing translated content from '{$sourceTitle->getPrefixedDBkey()}' to '$targetTitlePrefixedKey'"
		);

		$requestHandler = $this->requestHandlerFactory->newFromTarget( $target );

		// No need to check if "lang" is "false",
		// because we get here only if current and target instances are correctly configured
		$sourceLang = $this->targetRecognizer->recognizeCurrentTarget()['lang'];

		$langWikiMap = $this->targetRecognizer->getLangToTargetKeyMap();
		$targetKey = $this->targetRecognizer->getTargetKeyFromTargetUrl( $target->getUrl() );

		$targetLang = array_search( $targetKey, $langWikiMap );

		// Update/insert new translation
		// We need to do that before pushing the page because after page creation on the target wiki
		// hook handler for "PageContentSaveComplete" hook will update target's last change timestamp
		// And translation should already exist, to update target's last change timestamp
		// aka "racing condition"
		$this->updateTranslation(
			$targetTitlePrefixedKey,
			$sourceTitle,
			$targetLang,
			$sourceLang
		);

		// Push subject page
		$status = $requestHandler->runAuthenticatedRequest( [
			'action' => 'edit',
			'token' => $requestHandler->getCSRFToken(),
			'summary' => 'Origin ' . $sourceTitle->getCanonicalURL(),
			'text' => $content,
			'title' => $targetTitlePrefixedKey,
			'format' => 'json'
		] );

		if ( !$status->isOK() ) {
			return $status;
		}

		// Get ID of the title after push
		$response = (object)$status->getValue();

		if ( !property_exists( $response, 'edit' ) ) {
			$this->logger->error( "Error when pushing the page. Response: " . print_r( $response, true ) );

			if ( property_exists( $response, 'error' ) ) {
				return Status::newFatal( 'Error when pushing the page: ' . $response->error['info'] );
			} else {
				return Status::newFatal( 'Error when pushing the page. More details in the logs.' );
			}
		}

		$editInfo = $response->edit;
		$pageId = $editInfo['pageid'];

		// Run additional requests
		$requests = [];
		$this->hookContainer->run( 'TranslationTransferAdditionalRequests', [
			$sourceTitle,
			$requestHandler->getTarget(),
			&$requests,
			$pageId
		] );

		foreach ( $requests as $requestData ) {
			$requestData['token'] = $requestHandler->getCSRFToken();
			$requestData['format'] = 'json';

			$requestHandler->runAuthenticatedRequest( $requestData );
		}

		$this->logger->debug( "Pushing related files..." );

		// Get all included files and templates
		[ $relatedFiles, $transcludedTitles ] = $this->getRelatedFilesAndTransclusions( $sourceTitle );

		// Push files
		foreach ( $relatedFiles as $fileTitle ) {
			if ( $this->fileTransferBlockedForTitle( $sourceTitle ) ) {
				$this->logger->info( 'File transfer blocked because corresponding page "'
					. $sourceTitle->getPrefixedDBkey()
					. '" contains "__BS_NO_AUTOMATIC_DOCUMENT_TRANSLATION__" magic word' );
				break;
			}

			$this->logger->debug( "Processing file '$fileTitle'..." );

			$contentProvider = $this->pageContentProviderFactory->newFromTitle( $fileTitle );
			if ( $contentProvider->isFile() ) {
				$file = $contentProvider->getFile();
				if ( !$file ) {
					$this->logger->error( "Error while pushing file: $fileTitle does not exist on the source" );
					continue;
				}

				$existsOnTarget = $this->fileExists( $fileTitle, $requestHandler );
				$filename = $file->getName();
				if ( $this->config->get( 'TranslateTransferFilesToDraft' ) && $existsOnTarget ) {
					$filename = $this->makeResourceTitleFilename(
						$this->targetRecognizer->getDraftNamespace( $targetLang ) ?? 'Draft',
						$filename
					);
				}

				$this->logger->debug( "Actual name of the file to push - '$filename'" );

				if ( !$file->getLocalRefPath() ) {
					$this->logger->error(
						"File exists on the source but does not have correct local path - '$filename'"
					);
					continue;
				}

				// Decide if we really want to push the file, assuming other extensions integrations
				$shouldPushFile = true;

				$this->hookContainer->run( 'BlueSpiceTranslationTransferBeforeFilePush', [
					$sourceTitle,
					$filename,
					$this->args,
					$existsOnTarget,
					&$shouldPushFile
				] );

				if ( !$shouldPushFile ) {
					continue;
				}

				$upload = $requestHandler->uploadFile(
					$file,
					$contentProvider->getContent(),
					$filename
				);
				if ( !$upload ) {
					$this->logger->error( "Error while pushing file: {$file->getName()}" );
				}
			} else {
				$this->logger->warning( "Skipping, because not a file..." );
			}
		}

		$this->logger->debug( "Pushing related templates..." );

		// Push templates or transcluded pages from other (not NS_TEMPLATE) namespaces
		foreach ( $transcludedTitles as $transcludedTitle ) {
			$this->logger->debug( "Processing template '$transcludedTitle'..." );

			$wikiPage = $this->wikiPageFactory->newFromTitle( $transcludedTitle );
			$content = $wikiPage->getContent();
			if ( !$content instanceof TextContent ) {
				$this->logger->error( 'Cannot translate non-text content' );
			}
			$wikitext = $content->getText();

			$sourceTemplateDbKey = $transcludedTitle->getDBkey();

			$targetNsText = $this->wtConverter->getNsText( $transcludedTitle->getNamespace(), $targetLang );
			if ( $targetNsText ) {
				$targetTemplatePrefixedDbKey = $targetNsText . ':' . $sourceTemplateDbKey;
			} else {
				$targetTemplatePrefixedDbKey = $sourceTemplateDbKey;
			}

			// Push template
			$status = $requestHandler->runAuthenticatedRequest( [
				'action' => 'edit',
				'token' => $requestHandler->getCSRFToken(),
				'summary' => 'Origin ' . $sourceTitle->getCanonicalURL(),
				'text' => $wikitext,
				'title' => $targetTemplatePrefixedDbKey,
				'format' => 'json'
			] );

			if ( !$status->isOK() ) {
				return $status;
			}

			// Get ID of the title after push
			$response = (object)$status->getValue();

			if ( !property_exists( $response, 'edit' ) ) {
				$this->logger->error( 'Error when pushing the page. Response: ' . print_r( $response, true ) );

				if ( property_exists( $response, 'error' ) ) {
					return Status::newFatal( 'Error when pushing the page: ' . $response->error['info'] );
				} else {
					return Status::newFatal( 'Error when pushing the page. More details in the logs.' );
				}
			}
		}

		/**
		 * Copied from Page purger
		 * purge the target page
		 */
		$requestHandler->runAuthenticatedRequest( [
			'action' => 'purge',
			'forcerecursivelinkupdate' => true,
			'titles' => $targetTitlePrefixedKey,
			'format' => 'json'
		] );

		return $status;
	}

	/**
	 * @param Title $sourceTitle
	 *
	 * @return Title[]
	 */
	private function getRelatedFilesAndTransclusions( Title $sourceTitle ): array {
		$contentProvider = $this->pageContentProviderFactory->newFromTitle( $sourceTitle );
		$related = $contentProvider->getRelatedTitles( [] );

		$files = [];
		foreach ( $related as $title ) {
			if ( $title->getNamespace() !== NS_FILE ) {
				continue;
			}
			$files[] = $title;
		}

		$transcludedList = $contentProvider->getTranscluded();

		$transclusions = [];
		foreach ( $transcludedList as $transcludedDbKey ) {
			$transcludedTitle = $this->titleFactory->newFromDBkey( $transcludedDbKey );

			$transclusions[] = $transcludedTitle;
		}

		return [ $files, $transclusions ];
	}

	/**
	 * @param Title $fileTitle
	 * @param AuthenticatedRequestHandler $requestHandler
	 *
	 * @return bool
	 */
	private function fileExists( Title $fileTitle, AuthenticatedRequestHandler $requestHandler ): bool {
		$props = $requestHandler->getPageProps( 'File:' . $fileTitle->getDBkey() );

		return isset( $props['pageid'] ) && !isset( $props['missing'] );
	}

	/**
	 * @param string $draftNamespace
	 * @param string $filename
	 *
	 * @return string
	 */
	private function makeResourceTitleFilename( string $draftNamespace, string $filename ): string {
		$bits = explode( ':', $filename );
		$bits[count( $bits ) - 1] = $draftNamespace . '_' . end( $bits );

		return implode( ':', $bits );
	}

	/**
	 * @param string $targetTitleKey
	 * @param Title $sourceTitle
	 * @param string $targetLang
	 * @param string $sourceLang
	 *
	 * @return void
	 */
	private function updateTranslation(
		string $targetTitleKey,
		Title $sourceTitle,
		string $targetLang,
		string $sourceLang
	): void {
		$targetPrefixedDbKey = str_replace( ' ', '_', $targetTitleKey );
		$revision = $this->revisionStore->getRevisionByTitle( $sourceTitle );
		$sourceLastChangeTimestamp = $revision->getTimestamp();
		$this->translationsDao->updateTranslation(
			$sourceLang,
			$sourceTitle->getPrefixedDBkey(),
			$targetLang,
			$targetPrefixedDbKey,
			$sourceLastChangeTimestamp
		);
	}

	/**
	 * Checks if specified page has "__BS_NO_AUTOMATIC_DOCUMENT_TRANSLATION__" tag.
	 * If it has - file transfer during translation should be blocked for this page.
	 *
	 * @param Title $sourceTitle
	 * @return bool
	 */
	private function fileTransferBlockedForTitle( Title $sourceTitle ): bool {
		if ( !isset( $this->blockedFileTransferCache[ $sourceTitle->getArticleID() ] ) ) {
			$dbr = $this->loadBalancer->getConnection( DB_REPLICA );

			$noAutomaticDocumentTranslation = (bool)$dbr->selectField(
				'page_props',
				'pp_propname',
				[
					'pp_page' => $sourceTitle->getArticleID(),
					'pp_propname' => 'bs_nodocumenttranslation'
				],
				__METHOD__
			);

			$this->blockedFileTransferCache[ $sourceTitle->getArticleID() ] = $noAutomaticDocumentTranslation;
		} else {
			$noAutomaticDocumentTranslation = $this->blockedFileTransferCache[ $sourceTitle->getArticleID() ];
		}

		return $noAutomaticDocumentTranslation;
	}
}
