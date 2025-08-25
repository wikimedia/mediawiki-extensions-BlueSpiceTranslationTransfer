<?php

namespace BlueSpice\TranslationTransfer\Tag;

use BlueSpice\Tag\Tag;
use MediaWiki\Parser\Parser;
use MediaWiki\Parser\PPFrame;

class DeeplIgnore extends Tag {
	/**
	 * @return string[]|void
	 */
	public function getTagNames() {
		return [ 'deepl:ignore', 'translation:ignore' ];
	}

	/**
	 *
	 * @return string
	 */
	public function getContainerElementName() {
		return 'span';
	}

	/**
	 *
	 * @param string $processedInput
	 * @param array $processedArgs
	 * @param Parser $parser
	 * @param PPFrame $frame
	 * @return DeeplIgnoreHandler
	 */
	public function getHandler( $processedInput, array $processedArgs, Parser $parser,
		PPFrame $frame ) {
		return new DeeplIgnoreHandler( $processedInput, $processedArgs, $parser, $frame );
	}
}
