<?php

namespace BlueSpice\TranslationTransfer\Hook;

use BlueSpice\TranslationTransfer\Tag\DeeplIgnore;
use MWStake\MediaWiki\Component\GenericTagHandler\Hook\MWStakeGenericTagHandlerInitTagsHook;

class RegisterTags implements MWStakeGenericTagHandlerInitTagsHook {

	/**
	 * @inheritDoc
	 */
	public function onMWStakeGenericTagHandlerInitTags( array &$tags ) {
		$tags[] = new DeeplIgnore();
	}
}
