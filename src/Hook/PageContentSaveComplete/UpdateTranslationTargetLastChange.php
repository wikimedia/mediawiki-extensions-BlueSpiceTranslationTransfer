<?php

namespace BlueSpice\TranslationTransfer\Hook\PageContentSaveComplete;

use BlueSpice\TranslationTransfer\Util\ContentTransfer\TargetRecognizer;
use BlueSpice\TranslationTransfer\Util\TranslationsDao;
use BlueSpice\TranslationTransfer\Util\TranslationUpdater;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\MediaWikiServices;
use MediaWiki\Storage\Hook\PageSaveCompleteHook;
use Wikimedia\Rdbms\ILoadBalancer;

class UpdateTranslationTargetLastChange implements PageSaveCompleteHook {

	/**
	 * @var ILoadBalancer
	 */
	private $lb;

	/**
	 * @var TargetRecognizer
	 */
	private $targetRecognizer;

	/**
	 * @param ILoadBalancer $lb
	 * @param TargetRecognizer $targetRecognizer
	 */
	public function __construct(
		ILoadBalancer $lb,
		TargetRecognizer $targetRecognizer
	) {
		$this->lb = $lb;
		$this->targetRecognizer = $targetRecognizer;
	}

	/**
	 * @inheritDoc
	 */
	public function onPageSaveComplete(
		$wikiPage, $user, $summary, $flags, $revisionRecord, $editResult
	) {
		$currentLang = $this->targetRecognizer->recognizeCurrentTarget()['lang'];
		if ( !$currentLang ) {
			// Probably instance is just not used in translations configuration. Skip processing then
			return true;
		}

		$prefixedDbKey = $wikiPage->getTitle()->getPrefixedDBkey();

		$newTimestamp = $revisionRecord->getTimestamp();

		$dao = new TranslationsDao( $this->lb );
		if ( $dao->isTarget( $currentLang, $prefixedDbKey ) ) {
			// Update "target last change date"

			// There were problems with updating this date directly -
			// - just timeout, most likely DB lock.
			// So use job for that.
			DeferredUpdates::addCallableUpdate( static function () use (
				$prefixedDbKey, $currentLang, $newTimestamp
			) {
				$services = MediaWikiServices::getInstance();

				/** @var TranslationUpdater $translationUpdater */
				$translationUpdater = $services->getService( 'TranslationTransferTranslationUpdater' );
				$translationUpdater->updateTranslationTargetLastChangeTimestamp(
					$prefixedDbKey, $currentLang, $newTimestamp
				);
			} );
		}

		return true;
	}
}
