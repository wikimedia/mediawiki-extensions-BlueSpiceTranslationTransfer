<?php

namespace BlueSpice\TranslationTransfer\Hook\PageMoveComplete;

use BlueSpice\TranslationTransfer\Util\ContentTransfer\TargetRecognizer;
use BlueSpice\TranslationTransfer\Util\TranslationsDao;
use BlueSpice\TranslationTransfer\Util\TranslationUpdater;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Hook\PageMoveCompleteHook;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\NamespaceInfo;
use Wikimedia\Rdbms\ILoadBalancer;

class UpdateTranslationAfterMove implements PageMoveCompleteHook {

	/**
	 * @var ILoadBalancer
	 */
	private $lb;

	/**
	 * @var TargetRecognizer
	 */
	private $targetRecognizer;

	/**
	 * @var NamespaceInfo
	 */
	private $nsInfo;

	/**
	 * @param ILoadBalancer $lb
	 * @param TargetRecognizer $targetRecognizer
	 * @param NamespaceInfo $nsInfo
	 */
	public function __construct(
		ILoadBalancer $lb,
		TargetRecognizer $targetRecognizer,
		NamespaceInfo $nsInfo
	) {
		$this->lb = $lb;
		$this->targetRecognizer = $targetRecognizer;
		$this->nsInfo = $nsInfo;
	}

	/**
	 * @inheritDoc
	 */
	public function onPageMoveComplete( $old, $new, $user, $pageid, $redirid, $reason, $revision ) {
		$currentLang = $this->targetRecognizer->recognizeCurrentTarget()['lang'];
		if ( !$currentLang ) {
			// Probably instance is just not used in translations configuration. Skip processing then
			return true;
		}

		$oldPrefixedDbKey = $old->getDBkey();
		$newPrefixedDbKey = $new->getDBkey();

		if ( $old->getNamespace() !== 0 ) {
			$oldNsName = $this->nsInfo->getCanonicalName( $old->getNamespace() );
			$oldPrefixedDbKey = $oldNsName . ':' . $oldPrefixedDbKey;
		}

		if ( $new->getNamespace() !== 0 ) {
			$newNsName = $this->nsInfo->getCanonicalName( $new->getNamespace() );
			$newPrefixedDbKey = $newNsName . ':' . $newPrefixedDbKey;
		}

		$dao = new TranslationsDao( $this->lb );
		if ( $dao->isTarget( $currentLang, $oldPrefixedDbKey ) ) {
			// Translation target moved, so update translation DB entry

			// Check if new page name is a translation
			if ( $dao->isTarget( $currentLang, $newPrefixedDbKey ) ) {
				// That's a case when we are moving "SomeNS:Some Title" to "Some New Title",
				// but "Some New Title" is already a result of existing translation.
				// So we are actually updating translation of "Some New Title".
				// In that case we need to remove old translation at first

				// If old translation won't be removed - there will be a primary key conflict,
				// because primary key is "target title + target lang"

				// TODO: Should we just silently override existing translation or probably show user some warning?
				$dao->removeTranslation( $newPrefixedDbKey, $currentLang );
			}

			$dao->updateTranslationTarget( $oldPrefixedDbKey, $currentLang, $newPrefixedDbKey );
		} elseif ( $dao->isSource( $currentLang, $oldPrefixedDbKey ) ) {
			// When translation source is moved - all targets will be linked to the new one
			$dao->updateTranslationSource( $oldPrefixedDbKey, $currentLang, $newPrefixedDbKey );

			// Check if we should leave redirect after renaming target pages or not
			$leaveRedirect = (bool)$redirid;

			// If translation source changed - we should trigger translation of all translation targets
			// Assuming new source title
			DeferredUpdates::addCallableUpdate( static function () use (
				$newPrefixedDbKey, $currentLang, $leaveRedirect
			) {
				$services = MediaWikiServices::getInstance();

				/** @var TranslationUpdater $translationUpdater */
				$translationUpdater = $services->getService( 'TranslationTransferTranslationUpdater' );
				$translationUpdater->translateTargetsAfterSourceMove( $newPrefixedDbKey, $currentLang, $leaveRedirect );
			} );
		}
	}
}
