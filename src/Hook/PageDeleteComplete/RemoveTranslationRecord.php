<?php

namespace BlueSpice\TranslationTransfer\Hook\PageDeleteComplete;

use BlueSpice\TranslationTransfer\Util\ContentTransfer\TargetRecognizer;
use BlueSpice\TranslationTransfer\Util\TranslationsDao;
use ManualLogEntry;
use MediaWiki\Page\Hook\PageDeleteCompleteHook;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Permissions\Authority;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Title\NamespaceInfo;
use Wikimedia\Rdbms\ILoadBalancer;

class RemoveTranslationRecord implements PageDeleteCompleteHook {

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
	public function onPageDeleteComplete(
		ProperPageIdentity $page,
		Authority $deleter,
		string $reason,
		int $pageID,
		RevisionRecord $deletedRev,
		ManualLogEntry $logEntry,
		int $archivedRevisionCount
	) {
		$currentLang = $this->targetRecognizer->recognizeCurrentTarget()['lang'];
		if ( !$currentLang ) {
			// Probably instance is just not used in translations configuration. Skip processing then
			return true;
		}

		$titleDbKey = $page->getDBkey();

		if ( $page->getNamespace() !== 0 ) {
			$nsName = $this->nsInfo->getCanonicalName( $page->getNamespace() );
			$titlePrefixedDbKey = $nsName . ':' . $titleDbKey;
		} else {
			$titlePrefixedDbKey = $titleDbKey;
		}

		$dao = new TranslationsDao( $this->lb );
		if ( $dao->isTarget( $currentLang, $titlePrefixedDbKey ) ) {
			// Translation target removed, so remove translation DB record
			$dao->removeTranslation( $titlePrefixedDbKey, $currentLang );
		} elseif ( $dao->isSource( $currentLang, $titlePrefixedDbKey ) ) {
			// Translation source removed - remove all connected with it translations
			$dao->removeAllSourceTranslations( $titlePrefixedDbKey, $currentLang );
		}
	}
}
