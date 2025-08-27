<?php

namespace BlueSpice\TranslationTransfer\Hook\LoadExtensionSchemaUpdates;

use BlueSpice\TranslationTransfer\Maintenance\PostDatabaseUpdate\NormalizeTitleDictionary;
use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;

class AddDictionaryTable implements LoadExtensionSchemaUpdatesHook {

	/**
	 * @inheritDoc
	 */
	public function onLoadExtensionSchemaUpdates( $updater ) {
		$base = dirname( dirname( dirname( __DIR__ ) ) );

		$updater->addExtensionTable(
			'bs_tt_dictionary_title',
			"$base/maintenance/db/bs_tt_dictionary_title.sql"
		);

		// Can we use that to add 2 columns at once?
		// It should work, but could be wrong in semantic way.
		// Used "extensions/AbuseFilter/db_patches/mysql/patch-split-afl_filter.sql" as a reference
		$updater->addExtensionField(
			'bs_tt_dictionary_title',
			'tt_dt_source_normalized_text',
			"$base/maintenance/db/bs_tt_dictionary_title.normalization.sql"
		);

		// Fill "normalized" columns for already existing records
		$updater->addPostDatabaseUpdateMaintenance(
			NormalizeTitleDictionary::class
		);

		// TODO: Add "LoggedUpdate" maintenance script for migrating "category dictionary" (aka "nv_translation_category") records BELOW

		return true;
	}
}
