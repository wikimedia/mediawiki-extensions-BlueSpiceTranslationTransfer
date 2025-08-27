<?php

namespace BlueSpice\TranslationTransfer\Hook\GetDoubleUnderscoreIDs;

use MediaWiki\Hook\GetDoubleUnderscoreIDsHook;

class AddNoAutomaticDocumentTranslate implements GetDoubleUnderscoreIDsHook {

	/**
	 * @inheritDoc
	 */
	public function onGetDoubleUnderscoreIDs( &$doubleUnderscoreIDs ) {
		$doubleUnderscoreIDs[] = 'bs_nodocumenttranslation';
	}
}
