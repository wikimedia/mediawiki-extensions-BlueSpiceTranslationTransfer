<?php

namespace BlueSpice\TranslationTransfer\Data\Dictionary;

use MWStake\MediaWiki\Component\DataStore\Filter;
use MWStake\MediaWiki\Component\DataStore\FilterFinder;
use MWStake\MediaWiki\Component\DataStore\PrimaryDatabaseDataProvider;
use MWStake\MediaWiki\Component\DataStore\ReaderParams;
use MWStake\MediaWiki\Component\DataStore\Schema;
use Wikimedia\Rdbms\IDatabase;

class PrimaryDataProvider extends PrimaryDatabaseDataProvider {

	/**
	 * @var string
	 */
	private $selectedLanguage;

	/**
	 * @var array
	 */
	private $fields = [
		DictionaryEntryRecord::NS_ID => 'tt_dt_source_ns_id',
		DictionaryEntryRecord::SOURCE_PREFIXED_TEXT => 'tt_dt_source_text',
		DictionaryEntryRecord::SOURCE_NORMALIZED => 'tt_dt_source_normalized_text',
		DictionaryEntryRecord::TRANSLATION_PREFIXED_TEXT => 'tt_dt_translation',
		DictionaryEntryRecord::TRANSLATION_NORMALIZED => 'tt_dt_normalized_translation',
	];

	/**
	 * List of fields which are stored in DB in normalized format.
	 * Such fields' values should always be lower-cased before comparison.
	 *
	 * @var string[]
	 */
	private $normalizedFields = [
		DictionaryEntryRecord::SOURCE_NORMALIZED,
		DictionaryEntryRecord::TRANSLATION_NORMALIZED
	];

	/**
	 * @param IDatabase $db
	 * @param Schema $schema
	 * @param string $selectedLanguage
	 */
	public function __construct( IDatabase $db, Schema $schema, string $selectedLanguage ) {
		parent::__construct( $db, $schema );

		$this->selectedLanguage = $selectedLanguage;
	}

	/**
	 * @inheritDoc
	 */
	protected function getTableNames() {
		return [ 'bs_tt_dictionary_title' ];
	}

	/**
	 * @return array
	 */
	protected function getDefaultConds() {
		return [
			'tt_dt_lang' => $this->selectedLanguage
		];
	}

	/**
	 * @inheritDoc
	 */
	protected function makePreFilterConds( ReaderParams $params ) {
		$defaultConds = $this->getDefaultConds();

		$conds = [];
		$fields = array_values( $this->schema->getFilterableFields() );
		$filterFinder = new FilterFinder( $params->getFilter() );
		foreach ( $fields as $fieldName ) {
			$filters = $filterFinder->findAllFiltersByField( $fieldName );
			foreach ( $filters as $filter ) {
				if ( !$filter instanceof Filter ) {
					continue;
				}
				if ( $this->skipPreFilter( $filter ) ) {
					continue;
				}

				$this->appendPreFilterCond( $conds, $filter );
			}
		}

		// As soon as we search for specified string in BOTH source and translation,
		// we need final condition like that:
		//
		// tt_dt_lang="<target_lang>" AND
		// (
		//		tt_dt_source_normalized_text LIKE "%<search_string>%" OR
		//		tt_dt_normalized_translation LIKE "%<search_string>%"
		// )
		if ( !empty( $conds ) ) {
			$conds = array_merge(
				[
					$this->db->makeList( $conds, IDatabase::LIST_OR )
				],
				$defaultConds
			);
		} else {
			$conds = $defaultConds;
		}

		return $conds;
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
			$sortField = $this->fields[ $sort->getProperty() ];
			$conds['ORDER BY'] .= "$sortField {$sort->getDirection()}";
		}
		return $conds;
	}

	/**
	 * @inheritDoc
	 */
	protected function appendRowToData( \stdClass $row ) {
		$recordData = [];
		foreach ( $this->fields as $recordField => $dbField ) {
			$recordData[$recordField] = $row->{$dbField};
		}

		$this->data[] = new DictionaryEntryRecord( (object)$recordData );
	}
}
