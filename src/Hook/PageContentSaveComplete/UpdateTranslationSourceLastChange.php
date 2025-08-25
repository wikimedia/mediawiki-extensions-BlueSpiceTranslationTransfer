<?php

namespace BlueSpice\TranslationTransfer\Hook\PageContentSaveComplete;

use BlueSpice\TranslationTransfer\Util\ContentTransfer\TargetRecognizer;
use BlueSpice\TranslationTransfer\Util\TranslationsDao;
use MediaWiki\Storage\Hook\PageSaveCompleteHook;
use Wikimedia\Rdbms\ILoadBalancer;

class UpdateTranslationSourceLastChange implements PageSaveCompleteHook {

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

		if ( $revisionRecord->isMinor() ) {
			// Minor revisions are not taken in account
			// It is done to not notify about "outdated translation" in case
			// when for example just semantic properties were changed
			return true;
		}

		$prefixedDbKey = $wikiPage->getTitle()->getPrefixedDBkey();

		$newTimestamp = $revisionRecord->getTimestamp();

		$dao = new TranslationsDao( $this->lb );
		if ( $dao->isSource( $currentLang, $prefixedDbKey ) ) {
			// Update "source last change date" for all target pages
			$dao->updateTranslationSourceLastChange( $prefixedDbKey, $currentLang, $newTimestamp );
		}

		return true;
	}
}
