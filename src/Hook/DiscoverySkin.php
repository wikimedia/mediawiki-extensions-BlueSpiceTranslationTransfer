<?php

namespace BlueSpice\TranslationTransfer\Hook;

use BlueSpice\Discovery\Hook\BlueSpiceDiscoveryTemplateDataProviderAfterInit;
use BlueSpice\Discovery\ITemplateDataProvider;

class DiscoverySkin implements BlueSpiceDiscoveryTemplateDataProviderAfterInit {

	/**
	 *
	 * @param ITemplateDataProvider $registry
	 * @return void
	 */
	public function onBlueSpiceDiscoveryTemplateDataProviderAfterInit( $registry ): void {
		$registry->register( 'actions_secondary', 'ca-translate-transfer-action-translate' );
		$registry->unregister( 'toolbox', 'ca-translate-transfer-action-translate' );
	}
}
