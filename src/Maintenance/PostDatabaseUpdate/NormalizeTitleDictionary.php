<?php

namespace BlueSpice\TranslationTransfer\Maintenance\PostDatabaseUpdate;

use MediaWiki\Maintenance\LoggedUpdateMaintenance;
use MediaWiki\MediaWikiServices;
use Wikimedia\Rdbms\IDatabase;

require_once dirname( __DIR__, 5 ) . "/maintenance/Maintenance.php";

/**
 * Look for any existing records in "bs_tt_dictionary_title" table.
 * If there are - get "source" and "translate" text, normalize them and fill corresponding columns.
 *
 * That script do not delete any data and is safe for accidental (or not) "re-run".
 */
class NormalizeTitleDictionary extends LoggedUpdateMaintenance {

	/**
	 * @var IDatabase
	 */
	private $db;

	/**
	 * @inheritDoc
	 */
	protected function doDBUpdates() {
		$this->db = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_PRIMARY );

		// We can identify row for update by such complex key:
		// "<source_ns_id>_<source_text>"
		// Because each "source -> translation" pair must be unique for each NS
		$rowsToUpdate = [];

		$res = $this->db->select(
			'bs_tt_dictionary_title',
			[
				'tt_dt_source_ns_id',
				'tt_dt_source_text',
				'tt_dt_translation'
			],
			'',
			__METHOD__
		);
		foreach ( $res as $row ) {
			$searchKey = $row->tt_dt_source_ns_id . '_' . $row->tt_dt_source_text;

			$rowsToUpdate[$searchKey] = [
				'tt_dt_source_normalized_text' => strtolower( $row->tt_dt_source_text ),
				'tt_dt_normalized_translation' => strtolower( $row->tt_dt_translation )
			];
		}

		foreach ( $rowsToUpdate as $searchKey => $normalizedData ) {
			$searchKeyParts = explode( '_', $searchKey );

			$nsId = array_shift( $searchKeyParts );
			$sourceText = implode( '_', $searchKeyParts );

			$this->db->update(
				'bs_tt_dictionary_title',
				[
					'tt_dt_source_normalized_text' => $normalizedData['tt_dt_source_normalized_text'],
					'tt_dt_normalized_translation' => $normalizedData['tt_dt_normalized_translation']
				],
				[
					'tt_dt_source_ns_id' => $nsId,
					'tt_dt_source_text' => $sourceText
				],
				__METHOD__
			);
		}

		$this->output( "Title dictionary normalization done!\n" );

		return true;
	}

	/**
	 * @inheritDoc
	 */
	protected function getUpdateKey() {
		return 'bs_translationtransfer_normalize_title_dictionary';
	}
}

$maintClass = NormalizeTitleDictionary::class;
require_once RUN_MAINTENANCE_IF_MAIN;
