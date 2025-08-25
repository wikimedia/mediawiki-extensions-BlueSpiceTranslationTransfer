<?php

namespace BlueSpice\TranslationTransfer\Hook\LoadExtensionSchemaUpdates;

use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;

class AddTranslationsTable implements LoadExtensionSchemaUpdatesHook {

	/**
	 * @inheritDoc
	 */
	public function onLoadExtensionSchemaUpdates( $updater ) {
		if ( $this->skipProcessing() ) {
			return true;
		}

		$base = dirname( dirname( dirname( __DIR__ ) ) );
		$updater->addExtensionTable(
			'bs_translationtransfer_translations',
			"$base/maintenance/db/bs_translationtransfer_translations.sql"
		);
		$updater->addExtensionField(
			'bs_translationtransfer_translations',
			'tt_translations_translation_acked',
			"$base/maintenance/db/bs_translationtransfer_translations.translation_acked.sql"
		);
		$updater->addExtensionField(
			'bs_translationtransfer_translations',
			'tt_translations_target_last_change_date',
			"$base/maintenance/db/bs_translationtransfer_translations.target_last_change_date.sql"
		);

		return true;
	}

	/**
	 * @return bool
	 */
	private function skipProcessing(): bool {
		if ( defined( 'FARMER_IS_ROOT_WIKI_CALL' ) && !FARMER_IS_ROOT_WIKI_CALL ) {
			return true;
		}

		return false;
	}
}
