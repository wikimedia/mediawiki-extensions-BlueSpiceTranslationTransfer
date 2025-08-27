<?php

namespace BlueSpice\TranslationTransfer\Util;

use BlueSpice\TranslationTransfer\DeepL;
use BlueSpice\TranslationTransfer\IDictionary;
use BlueSpice\TranslationTransfer\Job\UpdateTranslationsReleaseTimestamp;
use BlueSpice\TranslationTransfer\Job\UpdateTranslationTargetTimestamp;
use BlueSpice\TranslationTransfer\Util\ContentTransfer\TargetRecognizer;
use ContentTransfer\AuthenticatedRequestHandlerFactory;
use ContentTransfer\Target;
use ContentTransfer\TargetManager;
use Exception;
use JobQueueGroup;
use MediaWiki\Status\Status;
use MediaWiki\Title\TitleFactory;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class TranslationUpdater implements LoggerAwareInterface {

	/**
	 * @var DeepL
	 */
	private $deepL;

	/**
	 * @var TranslationsDao
	 */
	private $translationsDao;

	/**
	 * @var TargetManager
	 */
	private $targetManager;

	/**
	 * @var TargetRecognizer
	 */
	private $targetRecognizer;

	/**
	 * @var TitleFactory
	 */
	private $titleFactory;

	/**
	 * @var AuthenticatedRequestHandlerFactory
	 */
	private $requestHandlerFactory;

	/**
	 * @var IDictionary
	 */
	private $titleDictionary;

	/**
	 * @var LoggerInterface
	 */
	private $logger;

	/**
	 * @var JobQueueGroup
	 */
	private $jobQueueGroup;

	/**
	 * @param DeepL $deepL
	 * @param TranslationsDao $translationsDao
	 * @param TargetManager $targetManager
	 * @param TargetRecognizer $targetRecognizer
	 * @param TitleFactory $titleFactory
	 * @param AuthenticatedRequestHandlerFactory $authenticatedRequestHandlerFactory
	 * @param IDictionary $titleDictionary
	 * @param JobQueueGroup $jobQueueGroup
	 */
	public function __construct(
		DeepL $deepL,
		TranslationsDao $translationsDao,
		TargetManager $targetManager,
		TargetRecognizer $targetRecognizer,
		TitleFactory $titleFactory,
		AuthenticatedRequestHandlerFactory $authenticatedRequestHandlerFactory,
		IDictionary $titleDictionary,
		JobQueueGroup $jobQueueGroup
	) {
		$this->deepL = $deepL;
		$this->translationsDao = $translationsDao;
		$this->targetManager = $targetManager;
		$this->targetRecognizer = $targetRecognizer;
		$this->titleFactory = $titleFactory;
		$this->requestHandlerFactory = $authenticatedRequestHandlerFactory;
		$this->titleDictionary = $titleDictionary;
		$this->jobQueueGroup = $jobQueueGroup;

		$this->logger = new NullLogger();
	}

	/**
	 * @inheritDoc
	 */
	public function setLogger( LoggerInterface $logger ) {
		$this->logger = $logger;
	}

	/**
	 * After translation source was moved (renamed), we should:
	 * 		- find translations for each language
	 * 		- translate source and rename targets (with persisting redirects)
	 *
	 * @param string $newSourcePrefixedDbKey We do not need old DB key
	 * 		because source was already updated in translations table
	 * @param string $sourceLang
	 * @param bool $leaveRedirect
	 * @return Status
	 */
	public function translateTargetsAfterSourceMove(
		string $newSourcePrefixedDbKey, string $sourceLang, bool $leaveRedirect
	): Status {
		$this->logger->debug( "Start re-translating targets after source moved to '$newSourcePrefixedDbKey'..." );

		$status = new Status();

		$newSourceTitle = $this->titleFactory->newFromDBkey( $newSourcePrefixedDbKey );

		// Data for running job for updating "release timestamps" of specific translations
		// For performance reasons one job updates all source translations
		$updateReleaseTimestampsJobData = [];

		$translations = $this->translationsDao->getSourceTranslations( $newSourcePrefixedDbKey, $sourceLang );
		foreach ( $translations as $targetLang => $translationData ) {
			// Translate new source
			$translateStatus = $this->deepL->translateText(
				$newSourceTitle->getText(), $sourceLang, $targetLang
			);

			if ( $translateStatus->isOK() ) {
				$newTargetTitleText = $translateStatus->getValue();

				$this->logger->debug( "Translated to '$targetLang' as '$newTargetTitleText'." );

				// As soon as we translate with DeepL only title text (without namespace)
				// Then use namespace of source title, their IDs are the same
				$newTargetTitle = $this->titleFactory->makeTitle(
					$newSourceTitle->getNamespace(), $newTargetTitleText
				);

				// Persist new target translation in the dictionary
				try {
					$this->titleDictionary->insert(
						$newSourceTitle->getPrefixedText(), $targetLang, $newTargetTitleText
					);
				} catch ( Exception $e ) {
					$this->logger->error(
						'Error when inserting translation in the dictionary - ' . $e->getMessage()
					);
					continue;
				}

				$newTargetPrefixedDbKey = $newTargetTitle->getPrefixedDbKey();
			} else {
				$status->merge( $translateStatus );

				continue;
			}

			// Rename translation target title
			$targetPrefixedDbKey = $translationData['target_prefixed_key'];

			$target = $this->getTargetFromLang( $targetLang );
			if ( $target ) {
				$requestHandler = $this->requestHandlerFactory->newFromTarget( $target );

				$args = [
					'action' => 'move',
					'from' => $targetPrefixedDbKey,
					'to' => $newTargetPrefixedDbKey,
					'token' => $requestHandler->getCSRFToken(),
					'reason' => "Translation source moved to '$newSourcePrefixedDbKey'",
					'format' => 'json'
				];

				if ( !$leaveRedirect ) {
					$args['noredirect'] = 1;
				}

				// Push subject page
				$moveStatus = $requestHandler->runAuthenticatedRequest( $args );

				if ( !$moveStatus->isOK() ) {
					$this->logger->error( 'Error when moving the page, move status - ' . print_r( $moveStatus, true ) );
					$status->merge( $moveStatus );
				} else {
					$this->logger->debug( 'Move status is OK' );

					// Save data for running update job
					$updateReleaseTimestampsJobData[] = [
						UpdateTranslationsReleaseTimestamp::PARAM_TARGET_DB_KEY => $newTargetPrefixedDbKey,
						UpdateTranslationsReleaseTimestamp::PARAM_TARGET_LANG => $targetLang
					];
				}
			}
		}

		// Update translation timestamp as soon as we implicitly updated translation target
		// So "translation outdated" banner should not appear
		// As soon as we need to be sure that it will be done after targets moving and "translation table" updating
		// It was decided to move that functionality to a job
		$updateJob = new UpdateTranslationsReleaseTimestamp( $this->titleFactory->newMainPage(), [
			UpdateTranslationsReleaseTimestamp::PARAM_TARGETS => $updateReleaseTimestampsJobData
		] );

		$this->jobQueueGroup->push( $updateJob );

		return $status;
	}

	/**
	 * @param string $prefixedDbKey
	 * @param string $lang
	 * @param string $newTimestamp
	 */
	public function updateTranslationTargetLastChangeTimestamp(
		string $prefixedDbKey, string $lang, string $newTimestamp
	): void {
		$this->logger->debug( "Creating job for updating of target last change timestamp for '$prefixedDbKey'..." );

		$updateJob = new UpdateTranslationTargetTimestamp( $this->titleFactory->newMainPage(), [
			UpdateTranslationTargetTimestamp::PARAM_TARGET_PREFIXED_DB_KEY => $prefixedDbKey,
			UpdateTranslationTargetTimestamp::PARAM_TARGET_LANG => $lang,
			UpdateTranslationTargetTimestamp::PARAM_CHANGE_TIMESTAMP => $newTimestamp
		] );

		$this->jobQueueGroup->push( $updateJob );
	}

	/**
	 * @param string $lang
	 * @return Target|null
	 */
	private function getTargetFromLang( string $lang ): ?Target {
		$langToTargetMap = $this->targetRecognizer->getLangToTargetKeyMap();

		return $this->targetManager->getTarget( $langToTargetMap[$lang] );
	}
}
