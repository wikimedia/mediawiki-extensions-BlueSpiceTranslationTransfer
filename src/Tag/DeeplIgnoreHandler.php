<?php

namespace BlueSpice\TranslationTransfer\Tag;

use BlueSpice\Tag\Handler;
use MediaWiki\Html\Html;

class DeeplIgnoreHandler extends Handler {

	public function handle() {
		return Html::element( 'span', [], $this->processedInput );
	}
}
