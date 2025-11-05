<?php

namespace BlueSpice\TranslationTransfer\Util;

use BlueSpice\TranslationTransfer\TranslationWikitextConverter;
use BlueSpice\TranslationTransfer\Translator;
use BlueSpice\TranslationTransfer\Util\ContentTransfer\TargetRecognizer;
use ContentTransfer\AuthenticatedRequestHandler;
use ContentTransfer\AuthenticatedRequestHandlerFactory;
use ContentTransfer\PageContentProviderFactory;
use ContentTransfer\Target;
use Exception;
use File;
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
	 * @var Translator
	 */
	private $translator;

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
		TranslationWikitextConverter $wtConverter,
		Translator $translator
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
		$this->translator = $translator;

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

		// Update/insert new translation.
		// We need to do that before pushing the page because after page creation on the target wiki
		// hook handler for "PageContentSaveComplete" hook will update target's last change timestamp.
		// And translation should already exist, to update target's last change timestamp.
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

		$status = $this->transferRelatedResources( $sourceTitle, $targetLang, $requestHandler );

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
	 * @param string $targetLang
	 * @param AuthenticatedRequestHandler $requestHandler
	 * @return Status
	 * @throws Exception
	 */
	public function transferRelatedResources(
		Title $sourceTitle, string $targetLang,
		AuthenticatedRequestHandler $requestHandler
	): Status {
		$this->logger->debug( "Pushing related files..." );

		// Get all included files and templates/transcluded pages
		[ $relatedFiles, $transcludedTitles ] = $this->getRelatedResources( $sourceTitle );

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

				// Actually transfer that specific file.
				// Also whole content of file description page will be translated, as a regular title.
				$status = $this->transferFile(
					$requestHandler, $file,
					$contentProvider->getContent(), $filename,
					$targetLang
				);

				if ( !$status->isOK() ) {
					$this->logger->error( "Error while pushing file: {$file->getName()}. Status: $status" );

					// TODO: Should we break if one of related resources failed to push?
					break;
				}
			} else {
				$this->logger->warning( "Skipping, because not a file..." );
			}
		}

		$this->logger->debug( "Pushing transcluded titles..." );

		// Push templates or transcluded pages from other (not NS_TEMPLATE) namespaces
		foreach ( $transcludedTitles as $transcludedTitle ) {
			$status = $this->transferTranscludedTitle( $requestHandler, $sourceTitle, $transcludedTitle, $targetLang );

			if ( !$status->isOK() ) {
				$this->logger->error( "Error while pushing transcluded title: $transcludedTitle. Status: $status" );

				// TODO: Should we break if one of related resources failed to push?
				break;
			}
		}

		return Status::newGood();
	}

	/**
	 * @param Title $sourceTitle
	 *
	 * @return Title[]
	 */
	private function getRelatedResources( Title $sourceTitle ): array {
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
	 * Transfers one of related to source title files.
	 * As part of this process, whole content of file description page will also be translated,
	 * as a content of regular wiki page. But file title itself will not be translated.
	 *
	 * @param AuthenticatedRequestHandler $requestHandler
	 * @param File $file
	 * @param string $content
	 * @param string $filename
	 * @param string $targetLang
	 * @return Status
	 */
	private function transferFile(
		AuthenticatedRequestHandler $requestHandler,
		File $file, string $content, string $filename, string $targetLang
	): Status {
		// Translate content of file description page.
		try {
			$content = $this->translator->translateWikitext( $content, $targetLang );
		} catch ( Exception $e ) {
			return Status::newFatal(
				$e->getMessage()
			);
		}

		$upload = $requestHandler->uploadFile(
			$file,
			$content,
			$filename
		);
		if ( !$upload ) {
			$this->logger->error( "Error while pushing file: {$file->getName()}" );
		}

		return Status::newGood();
	}

	/**
	 * Transfer some related title which is transcluded in the source page.
	 * It may be either template (the most common transclusion case) or transcluded regular wiki title.
	 * Both cases are considered, and in both of them we also translate categories in the wikitext, because,
	 * as part of this software design, we always translate categories in case of any wiki title translation.
	 *
	 * @param AuthenticatedRequestHandler $requestHandler
	 * @param Title $sourceTitle
	 * @param Title $transcludedTitle
	 * @param string $targetLang
	 * @return Status
	 * @throws Exception
	 */
	private function transferTranscludedTitle(
		AuthenticatedRequestHandler $requestHandler,
		Title $sourceTitle, Title $transcludedTitle,
		string $targetLang
	): Status {
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

		// Translate categories in the wikitext before pushing
		$this->wtConverter->translateCategoriesWithNs( $wikitext, $targetLang );

		// Push transcluded title
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

		return Status::newGood();
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
