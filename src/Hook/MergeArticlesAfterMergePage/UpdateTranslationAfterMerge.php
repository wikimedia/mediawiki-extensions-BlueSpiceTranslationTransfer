<?php

namespace BlueSpice\TranslationTransfer\Hook\MergeArticlesAfterMergePage;

use BlueSpice\TranslationTransfer\Util\ContentTransfer\TargetRecognizer;
use BlueSpice\TranslationTransfer\Util\TranslationsDao;
use MediaWiki\Title\Title;
use MergeArticles\Hooks\MergeArticlesAfterMergePageHook;
use Wikimedia\Rdbms\ILoadBalancer;

class UpdateTranslationAfterMerge implements MergeArticlesAfterMergePageHook {

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
	public function __construct( ILoadBalancer $lb, TargetRecognizer $targetRecognizer ) {
		$this->lb = $lb;
		$this->targetRecognizer = $targetRecognizer;
	}

	/**
	 * @inheritDoc
	 */
	public function onMergeArticlesAfterMergePage( Title $target, Title $origin ): void {
		$currentLang = $this->targetRecognizer->recognizeCurrentTarget()['lang'];
		if ( !$currentLang ) {
			// Probably instance is just not used in translations configuration. Skip processing then
			return;
		}

		$oldPrefixedDbKey = $origin->getPrefixedDBkey();
		$newPrefixedDbKey = $target->getPrefixedDBkey();

		$dao = new TranslationsDao( $this->lb );
		if ( $dao->isTarget( $currentLang, $newPrefixedDbKey ) ) {
			// That's a case when we are merging "Draft:Some Title" to "Some Title",
			// but "Some Title" is already exists and is a result of existing translation.
			// So we are actually updating translation of "Some Title".
			// In that case we need to remove old translation at first

			// If old translation won't be removed - there will be a primary key conflict,
			// because primary key is "target title + target lang"
			$dao->removeTranslation( $newPrefixedDbKey, $currentLang );
		}

		// MergeArticles should merge only "target" articles - result of translation.
		// So no need to check if we're processing translation target here.
		// Even if for some reason "MergeArticles" will be used for translation source,
		// nothing will happen
		$dao->updateTranslationTarget( $oldPrefixedDbKey, $currentLang, $newPrefixedDbKey );
	}
}
