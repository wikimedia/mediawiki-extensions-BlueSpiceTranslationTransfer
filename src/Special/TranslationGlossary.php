<?php

namespace BlueSpice\TranslationTransfer\Special;

use MediaWiki\Html\Html;
use MediaWiki\SpecialPage\SpecialPage;

class TranslationGlossary extends SpecialPage {

	public function __construct() {
		parent::__construct( 'TranslationGlossary', 'edit' );
	}

	/**
	 * @inheritDoc
	 */
	public function execute( $action ) {
		parent::execute( $action );

		$this->getOutput()->enableOOUI();
		$this->getOutput()->addModules( 'ext.translate.transfer.glossary' );

		$this->getOutput()->addHTML(
			Html::element( 'div', [ 'id' => 'translate-transfer-glossary' ] )
		);
	}
}
