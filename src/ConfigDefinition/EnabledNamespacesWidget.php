<?php

namespace BlueSpice\TranslationTransfer\ConfigDefinition;

use BlueSpice\Html\OOUI\MenuTagMultiselectWidget;

class EnabledNamespacesWidget extends MenuTagMultiselectWidget {

	/**
	 * @inheritDoc
	 */
	public function getJavaScriptClassName() {
		return 'translationTransfer.ui.EnabledNamespacesWidget';
	}
}
