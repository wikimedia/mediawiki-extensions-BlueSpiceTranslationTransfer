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
use MediaWiki\Json\FormatJson;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Revision\RevisionStore;
use MediaWiki\Status\Status;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use Message;
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
	 * Timestamp of previous translation push.
	 *
	 * It is filled before current push and is used later for logic deciding if some specific
	 * file/template/transcluded page should be pushed as well.
	 *
	 * @var string|null
	 *
	 * @see TranslationPusher::shouldTransferRelatedTitle()
	 */
	private $previousPushTimestamp = null;

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

		// Save timestamp of previous translation push,
		// will need that further for deciding if some specific file/template should be transferred.
		if ( $this->translationsDao->getTranslation( $targetTitlePrefixedKey, $targetLang ) ) {
			$this->previousPushTimestamp = $this->translationsDao->getReleaseTimestamp(
				$targetTitlePrefixedKey, $targetLang
			);
		}

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
	private function transferRelatedResources(
		Title $sourceTitle, string $targetLang,
		AuthenticatedRequestHandler $requestHandler
	): Status {
		$this->logger->debug( "Pushing related files..." );

		// Get all included files and templates/transcluded pages
		[ $relatedFiles, $transcludedTitles ] = $this->getRelatedResources( $sourceTitle );

		$transferredFiles = [];

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

					$transferredFiles['fail'][] = [
						'title' => $fileTitle->getPrefixedText(),
						'reason' => Message::newFromKey( 'bs-translation-transfer-related-file-push-fail-does-not-exist' )->text()
					];

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

					$transferredFiles['fail'][] = [
						'title' => $fileTitle->getPrefixedText(),
						'reason' => Message::newFromKey( 'bs-translation-transfer-related-file-push-fail-local-path' )->text()
					];

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
					$transferredFiles['fail'][] = [
						'title' => $fileTitle->getPrefixedText(),
						'reason' => Message::newFromKey( 'bs-translation-transfer-related-file-push-fail-integrations' )->text()
					];

					continue;
				}

				// Some more logic to decide if we should really push that related file
				[ $shouldTransfer, $reason ] = $this->shouldTransferRelatedTitle(
					$file->getTitle(), $requestHandler, $targetLang
				);

				if ( !$shouldTransfer ) {
					$transferredFiles['fail'][] = [
						'title' => $fileTitle->getPrefixedText(),
						'reason' => $reason
					];

					continue;
				}

				// Actually transfer that specific file.
				// Also, whole content of file description page will be translated, as a regular title.
				$status = $this->transferFile(
					$requestHandler, $sourceTitle, $file,
					$contentProvider->getContent(), $filename,
					$targetLang
				);

				if ( !$status->isOK() ) {
					$this->logger->error( "Error while pushing file: {$file->getName()}. Status: $status" );

					$transferredFiles['fail'][] = [
						'title' => $fileTitle->getPrefixedText(),
						'reason' => Message::newFromKey( 'bs-translation-transfer-related-title-push-fail-generic-error' )->text()
					];
				} else {
					// In order to create correct link to that resource on the target wiki,
					// we need to translate NS to the target language at first.
					$targetNs = $this->wtConverter->getNsText( NS_FILE, $targetLang );

					$resourceTargetPrefixedDbKey = $targetNs . ':' . $fileTitle->getDBkey();

					$targetHref = $this->targetRecognizer->composeTargetTitleLink(
						$targetLang, $resourceTargetPrefixedDbKey
					);

					$transferredFiles['success'][] = [
						'title' => $fileTitle->getPrefixedText(),
						'href' => $targetHref
					];
				}
			} else {
				$this->logger->warning( "Skipping, because not a file..." );
			}
		}

		$this->logger->debug( "Pushing transcluded titles..." );

		$transferredTransclusions = [];

		// Push templates or transcluded pages from other (not NS_TEMPLATE) namespaces
		/** @var Title $transcludedTitle */
		foreach ( $transcludedTitles as $transcludedTitle ) {
			$this->logger->debug( "Processing transcluded title '$transcludedTitle'..." );

			if ( $transcludedTitle->getPrefixedDBkey() === $sourceTitle->getPrefixedDBkey() ) {
				// For some reason title which we are translating - may sometimes be included among transcluded ones.
				// We already translated and pushed it, so no need to transfer it once more.
				$this->logger->debug( "Source title! Skipping..." );

				// No need to indicate for user that there was a problem with that page,
				// as soon as it anyway should not be transferred as related title.
				continue;
			}

			// Some more logic to decide if we should really push that transcluded title
			[ $shouldTransfer, $reason ] = $this->shouldTransferRelatedTitle(
				$transcludedTitle, $requestHandler, $targetLang
			);

			if ( !$shouldTransfer ) {
				$transferredFiles['fail'][] = [
					'title' => $transcludedTitle->getPrefixedText(),
					'reason' => $reason
				];

				continue;
			}

			$status = $this->transferTranscludedTitle( $requestHandler, $sourceTitle, $transcludedTitle, $targetLang );

			if ( !$status->isOK() ) {
				$this->logger->error( "Error while pushing transcluded title: $transcludedTitle. Status: $status" );

				$transferredTransclusions['fail'][] = [
					'title' => $transcludedTitle->getPrefixedText(),
					'reason' => Message::newFromKey( 'bs-translation-transfer-related-title-push-fail-generic-error' )->text()
				];
			} else {
				// In order to create correct link to that resource on the target wiki,
				// we need to translate NS to the target language at first.
				if ( $transcludedTitle->getNamespace() !== NS_MAIN ) {
					$targetNs = $this->wtConverter->getNsText( $transcludedTitle->getNamespace(), $targetLang );

					$resourceTargetPrefixedDbKey = $targetNs . ':' . $transcludedTitle->getDBkey();
				} else {
					$resourceTargetPrefixedDbKey = $transcludedTitle->getDBkey();
				}

				$targetHref = $this->targetRecognizer->composeTargetTitleLink(
					$targetLang, $resourceTargetPrefixedDbKey
				);

				$transferredTransclusions['success'][] = [
					'title' => $transcludedTitle->getPrefixedText(),
					'href' => $targetHref
				];
			}
		}

		$transferredResources = [];

		if ( !empty( $transferredTransclusions ) || !empty( $transferredFiles ) ) {
			// We do not separate related files and transclusions for now
			$transferredResources = array_merge_recursive( $transferredFiles, $transferredTransclusions );
		}

		return Status::newGood( $transferredResources );
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
	 * @param Title $sourceTitle
	 * @param File $file
	 * @param string $content
	 * @param string $filename
	 * @param string $targetLang
	 * @return Status
	 */
	private function transferFile(
		AuthenticatedRequestHandler $requestHandler, Title $sourceTitle,
		File $file, string $content, string $filename, string $targetLang
	): Status {
		// Translate content of file description page.
		if ( !empty( $content ) ) {
			try {
				$translatedWikitext = $this->translator->translateWikitext( $content, $targetLang );
			} catch ( Exception $e ) {
				return Status::newFatal(
					$e->getMessage()
				);
			}
		} else {
			$translatedWikitext = '';
		}

		$upload = $requestHandler->uploadFile(
			$file,
			$translatedWikitext,
			$filename
		);
		if ( !$upload ) {
			$this->logger->error( "Error while pushing file: {$file->getName()}" );

			return Status::newFatal( 'Failed to push the file' );
		}

		// As soon as MediaWiki "upload" API uses "text" parameter only as initial text for new files,
		// that does not update text for description page if file already exists on the target wiki.
		// So, to make sure, we need to push changes for file description page separately.
		$status = $requestHandler->runAuthenticatedRequest( [
			'action' => 'edit',
			'token' => $requestHandler->getCSRFToken(),
			'summary' => 'Origin ' . $sourceTitle->getCanonicalURL(),
			'text' => $translatedWikitext,
			'title' => 'File:' . $file->getTitle()->getDBkey(),
			'format' => 'json'
		] );

		if ( !$status->isOK() ) {
			return $status;
		}

		// Get ID of the title after push if needed
		$response = (object)$status->getValue();

		if ( !property_exists( $response, 'edit' ) ) {
			$this->logger->error( 'Error when pushing file description page. Response: ' . print_r( $response, true ) );

			if ( property_exists( $response, 'error' ) ) {
				return Status::newFatal( 'Error when pushing file description page: ' . $response->error['info'] );
			} else {
				return Status::newFatal( 'Error when pushing file description page. More details in the logs.' );
			}
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
		$wikiPage = $this->wikiPageFactory->newFromTitle( $transcludedTitle );
		$content = $wikiPage->getContent();
		if ( !$content instanceof TextContent ) {
			$this->logger->error( 'Cannot translate non-text content' );

			return Status::newFatal( 'Cannot translate non-text content' );
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

	/**
	 * Decide if specified related title (file/template/transcluded page) should be transferred.
	 *
	 * It is designed TO NOT OVERWRITE template/file/transcluded page on the target wiki if:
	 *              * It already exists on the target and:
	 *              *       *       either that page was already modified by user,
	 *              *       *       or there that page was not changed on the source wiki after previous push.
	 *
	 * In all other cases related title is transferred.
	 *
	 * @param Title $transcludedTitle
	 * @param AuthenticatedRequestHandler $requestHandler
	 * @param string $targetLang
	 * @return array Array containing both decision if we should transfer that specific related title (bool)
	 * 					and reason why we decided to not transfer it (string), as a list.
	 * 					So like that: [ <shouldTransfer> (bool), <reason> (string) ].
	 * 					Reason is translate-able message, later may be displayed to user.
	 * 					For cases when we decide to transfer title - there is no need for reason string.
	 * 					Also, probably reason also can be empty in cases when related title was not transferred,
	 * 					but reason will probably make no sense for user.
	 * 					For such cases more info should be written in the logs for developer.
	 */
	private function shouldTransferRelatedTitle(
		Title $transcludedTitle, AuthenticatedRequestHandler $requestHandler,
		string $targetLang
	): array {
		if ( $transcludedTitle->getNamespace() !== NS_MAIN ) {
			$targetNsText = $this->wtConverter->getNsText( $transcludedTitle->getNamespace(), $targetLang );

			$relatedTitleTargetPrefixedDbKey = $targetNsText . ':' . $transcludedTitle->getDBkey();
		} else {
			$relatedTitleTargetPrefixedDbKey = $transcludedTitle->getDBkey();
		}

		// Reset "page props", because they are cached inside
		$requestHandler->setPageProps( null );

		// Check if related title already exists on the target wiki
		$props = $requestHandler->getPageProps( $relatedTitleTargetPrefixedDbKey );

		if ( !isset( $props['pageid'] ) || isset( $props['missing'] ) ) {
			// If it does not exist - just transfer
			$this->logger->debug( "Does not exist on target." );

			return [ true, '' ];
		}

		// If it exists - check if that title was changed on the source wiki after previous push
		$revision = $this->revisionStore->getRevisionByTitle( $transcludedTitle );
		if ( !$revision ) {
			return [
				false,
				Message::newFromKey( 'bs-translation-transfer-related-title-push-fail-no-revision' )->text()
			];
		}

		$relatedTitleSourceLastChangeTimestamp = $revision->getTimestamp();

		if (
			$this->previousPushTimestamp &&
			( $this->previousPushTimestamp < $relatedTitleSourceLastChangeTimestamp )
		) {
			// If transcluded title was changed after the last push -
			// - then we probably need to update it on the target wiki.
			$this->logger->debug( "Was changed after previous push. Should probably be updated." );

			// But at first check if it was not changed by user on the target wiki.
			// If yes - we'll not automatically overwrite such modifications.
			// For that, get SHA1 of the latest revision on the target wiki.
			// Later we'll compare it with SHA1 of the latest transferred revision from the source wiki.
			$requestData = [
				'action' => 'query',
				'prop' => 'revisions',
				'rvprop' => 'sha1',
				'format' => 'json',
				'titles' => $relatedTitleTargetPrefixedDbKey
			];

			$request = $requestHandler->getRequest( $requestData );
			$status = $request->execute();

			if ( !$status->isOK() ) {
				$this->logger->error(
					"Failed to get revision sha1 from target wiki! Bad status: $status"
				);

				return [
					false,
					Message::newFromKey( 'bs-translation-transfer-related-title-push-fail-sha1-bad-status' )->text()
				];
			}

			$response = FormatJson::decode( $request->getContent(), true );

			if ( !$response || count( $response['query']['pages'] ) === 0 ) {
				$this->logger->error(
					"Failed to get revision sha1 from target wiki! Bad response: " . print_r( $response, true )
				);

				return [
					false,
					Message::newFromKey( 'bs-translation-transfer-related-title-push-fail-sha1-bad-response' )->text()
				];
			}

			// We do not know ID of related title on the target wiki, so need a little hack here.
			// In "pages" array there should be only one entry - with data about specified title.
			foreach ( $response['query']['pages'] as $page ) {
				// We already checked if that title exists on the target wiki -
				// - so no need in futher checks here.
				$targetSha1 = $page['revisions'][0]['sha1'];

				break;
			}

			// Now when we got SHA1 of the latest revision on the target wiki -
			// - look for the revision which was pushed from the source latest time, and compare their SHA1.
			while ( true ) {
				// To find revision which we most likely pushed during the latest push -
				// look for the first revision with timestamp earlier than the latest push
				$prevRevision = $this->revisionStore->getPreviousRevision( $revision );
				if ( !$prevRevision ) {
					// Current push is the first one, but that related title already exists on the target wiki.
					// Probably title was created by user?
					// So do not transfer and display user info about that.
					return [
						false,
						Message::newFromKey( 'bs-translation-transfer-related-title-push-fail-target-exists' )->text(),
					];
				}

				$prevRevisionTimestamp = $prevRevision->getTimestamp();

				if ( $prevRevisionTimestamp < $this->previousPushTimestamp ) {
					$sourceSha1 = $prevRevision->getSha1();

					break;
				}
			}

			// IDE may complain, but both variables should logically be correctly set at this point.
			if ( $sourceSha1 !== $targetSha1 ) {
				// If specified title was changed on the target wiki after previous push -
				// - we should not overwrite it.
				$this->logger->debug( "Changed on the target wiki after previous push. Do not overwrite." );

				return [
					false,
					Message::newFromKey( 'bs-translation-transfer-related-title-push-fail-user-changes' )->text()
				];
			} else {
				// Otherwise overwrite.
				return [ true, '' ];
			}
		} else {
			// If that related title was not changed after previous push - no need to transfer it again.
			$this->logger->debug( "Was not changed after previous push." );

			return [
				false,
				Message::newFromKey( 'bs-translation-transfer-related-title-push-fail-no-change' )->text()
			];
		}
	}
}
