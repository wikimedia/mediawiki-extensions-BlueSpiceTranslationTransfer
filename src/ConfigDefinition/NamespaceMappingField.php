<?php

namespace BlueSpice\TranslationTransfer\ConfigDefinition;

class NamespaceMappingField extends \HTMLTextField {
	/**
	 *
	 * @param string $value
	 * @return NamespaceMappingWidget
	 */
	public function getInputOOUI( $value ) {
		return new NamespaceMappingWidget( $this->mParams );
	}

}
