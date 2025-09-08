<?php

namespace BlueSpice\TranslationTransfer\Tag;

use MediaWiki\Html\Html;
use MediaWiki\Parser\Parser;
use MediaWiki\Parser\PPFrame;
use MWStake\MediaWiki\Component\GenericTagHandler\ITagHandler;

class DeeplIgnoreHandler implements ITagHandler {
	/**
	 * @inheritDoc
	 */
	public function getRenderedContent( string $input, array $params, Parser $parser, PPFrame $frame ): string {
		return Html::rawElement( 'span', [], $input );
	}
}
