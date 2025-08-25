<?php

use MediaWiki\Maintenance\Maintenance;
use MediaWiki\MediaWikiServices;

require_once dirname( dirname( dirname( __DIR__ ) ) ) . '/maintenance/Maintenance.php';

/**
 * Deletes all translations from translations table.
 * Can be used in case with corrupted data (for example, after migration from one wiki to another).
 *
 * Attention!
 * This script will just delete all "translation source => translation target" relations.
 * Wiki pages won't be affected.
 */
class ClearTranslations extends Maintenance {

	/**
	 * @inheritDoc
	 */
	public function execute() {
		$lb = MediaWikiServices::getInstance()->getDBLoadBalancer();
		$dbw = $lb->getConnection( DB_PRIMARY );

		$dbw->delete(
			'bs_translationtransfer_translations',
			\Wikimedia\Rdbms\IDatabase::ALL_ROWS,
			__METHOD__
		);

		$this->output( "Translations table cleared.\n" );
	}
}

$maintClass = ClearTranslations::class;
require_once RUN_MAINTENANCE_IF_MAIN;
