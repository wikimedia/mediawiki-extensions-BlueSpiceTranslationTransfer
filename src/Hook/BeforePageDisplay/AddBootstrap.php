<?php

namespace BlueSpice\TranslationTransfer\Hook\BeforePageDisplay;

use MediaWiki\Output\Hook\BeforePageDisplayHook;

class AddBootstrap implements BeforePageDisplayHook {

	/**
	 * @inheritDoc
	 */
	public function onBeforePageDisplay( $out, $skin ): void {
		$out->addModules( "ext.translate.transfer.bootstrap" );
	}
}
