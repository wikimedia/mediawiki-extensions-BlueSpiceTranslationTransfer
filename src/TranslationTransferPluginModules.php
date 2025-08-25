<?php

namespace BlueSpice\TranslationTransfer;

use MWStake\MediaWiki\Component\ManifestRegistry\ManifestAttributeBasedRegistry;

class TranslationTransferPluginModules {

	/**
	 * @return array
	 */
	public static function getPluginModules(): array {
		$registry = new ManifestAttributeBasedRegistry(
			'BlueSpiceTranslationTransferPluginModules'
		);

		$pluginModules = [];
		foreach ( $registry->getAllKeys() as $key ) {
			$moduleName = $registry->getValue( $key );
			$pluginModules[] = $moduleName;
		}

		return $pluginModules;
	}
}
