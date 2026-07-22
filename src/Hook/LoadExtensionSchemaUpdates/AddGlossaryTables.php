<?php

namespace BlueSpice\TranslationTransfer\Hook\LoadExtensionSchemaUpdates;

use BlueSpice\TranslationTransfer\Maintenance\PostDatabaseUpdate\MigrateGlossaryToV3;
use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;

class AddGlossaryTables implements LoadExtensionSchemaUpdatesHook {

	/**
	 * @inheritDoc
	 */
	public function onLoadExtensionSchemaUpdates( $updater ) {
		$base = dirname( dirname( dirname( __DIR__ ) ) );

		$updater->addExtensionTable(
			'bs_tt_glossary',
			"$base/maintenance/db/bs_tt_glossary.sql"
		);

		$updater->addExtensionTable(
			'bs_tt_glossary_entries',
			"$base/maintenance/db/bs_tt_glossary_entries.sql"
		);

		// Migrate existing v2 per-language glossaries to a single v3 multilingual glossary
		$updater->addPostDatabaseUpdateMaintenance(
			MigrateGlossaryToV3::class
		);

		return true;
	}
}
