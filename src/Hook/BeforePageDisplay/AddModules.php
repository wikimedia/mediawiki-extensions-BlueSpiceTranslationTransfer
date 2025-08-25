<?php

namespace BlueSpice\TranslationTransfer\Hook\BeforePageDisplay;

use MediaWiki\Output\Hook\BeforePageDisplayHook;

class AddModules implements BeforePageDisplayHook {

	/** @inheritDoc */
	public function onBeforePageDisplay( $out, $skin ): void {
		if ( $out->getTitle() && $out->getTitle()->isSpecial( 'BlueSpiceConfigManager' ) ) {
			$out->addModules( 'ext.translate.transfer.config' );
		}
	}
}
