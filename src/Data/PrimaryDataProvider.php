<?php

namespace BlueSpice\TranslationTransfer\Data;

use MWStake\MediaWiki\Component\DataStore\Filter;
use MWStake\MediaWiki\Component\DataStore\PrimaryDatabaseDataProvider;
use MWStake\MediaWiki\Component\DataStore\ReaderParams;
use stdClass;

class PrimaryDataProvider extends PrimaryDatabaseDataProvider {

	/**
	 * Record field to DB field mapping.
	 *
	 * @var array
	 */
	private $fields = [
		TranslationRecord::SOURCE_LANGUAGE => 'tt_translations_source_lang',
		TranslationRecord::SOURCE_PAGE_PREFIXED_TITLE_KEY => 'tt_translations_source_prefixed_title_key',
		TranslationRecord::SOURCE_PAGE_NORMALIZED_TEXT => 'tt_translations_source_normalized_title',
		TranslationRecord::TARGET_LANGUAGE => 'tt_translations_target_lang',
		TranslationRecord::TARGET_PAGE_PREFIXED_TITLE_KEY => 'tt_translations_target_prefixed_title_key',
		TranslationRecord::TARGET_PAGE_NORMALIZED_TEXT => 'tt_translations_target_normalized_title',
		TranslationRecord::RELEASE_TIMESTAMP => 'tt_translations_release_date',
		TranslationRecord::SOURCE_LAST_CHANGE_TIMESTAMP => 'tt_translations_source_last_change_date',
		TranslationRecord::TARGET_LAST_CHANGE_TIMESTAMP => 'tt_translations_target_last_change_date'
	];

	/**
	 * List of fields which are stored in DB in normalized format.
	 * Such fields' values should always be lower-cased before comparison.
	 *
	 * @var string[]
	 */
	private $normalizedFields = [
		TranslationRecord::SOURCE_PAGE_NORMALIZED_TEXT,
		TranslationRecord::TARGET_PAGE_NORMALIZED_TEXT
	];

	/**
	 * @inheritDoc
	 */
	protected function getTableNames() {
		return [ 'bs_translationtransfer_translations' ];
	}

	/**
	 * @inheritDoc
	 */
	protected function appendPreFilterCond( &$conds, Filter $filter ) {
		if ( !isset( $this->fields[$filter->getField()] ) ) {
			parent::appendPreFilterCond( $conds, $filter );
			return;
		}
		$filterClass = get_class( $filter );

		$fieldValue = $filter->getValue();
		if ( in_array( $filter->getField(), $this->normalizedFields ) ) {
			$fieldValue = strtolower( $fieldValue );
		}

		$filter = new $filterClass( [
			'field' => $this->fields[$filter->getField()],
			'comparison' => $filter->getComparison(),
			'value' => $fieldValue
		] );
		parent::appendPreFilterCond( $conds, $filter );
	}

	/**
	 * @inheritDoc
	 */
	protected function makePreOptionConds( ReaderParams $params ) {
		$conds = $this->getDefaultOptions();

		$fields = array_values( $this->schema->getSortableFields() );

		foreach ( $params->getSort() as $sort ) {
			if ( !in_array( $sort->getProperty(), $fields ) ) {
				continue;
			}
			if ( !isset( $conds['ORDER BY'] ) ) {
				$conds['ORDER BY'] = "";
			} else {
				$conds['ORDER BY'] .= ",";
			}
			$sortField = $this->fields[$sort->getProperty()];
			$conds['ORDER BY'] .= "$sortField {$sort->getDirection()}";
		}
		return $conds;
	}

	/**
	 * @inheritDoc
	 */
	protected function appendRowToData( stdClass $row ) {
		$recordData = [];
		foreach ( $this->fields as $recordField => $dbField ) {
			$recordData[$recordField] = $row->{$dbField};
		}

		$this->data[] = new TranslationRecord( (object)$recordData );
	}
}
