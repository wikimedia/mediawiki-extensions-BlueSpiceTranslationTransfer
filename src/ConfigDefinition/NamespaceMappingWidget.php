<?php

namespace BlueSpice\TranslationTransfer\ConfigDefinition;

use OOUI\Widget;

class NamespaceMappingWidget extends Widget {

	public function __construct( array $config = [] ) {
		parent::__construct( $config );
		$this->setInfusable( true );
	}

	/**
	 * @return array
	 */
	public function getClasses(): array {
		return [ 'bs-translation-transfer-namespace-mapping' ];
	}

	/**
	 * @return string
	 */
	protected function getJavaScriptClassName() {
		return 'translationTransfer.ui.NamespaceMapWidget';
	}
}
