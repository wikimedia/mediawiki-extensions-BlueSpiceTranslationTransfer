<?php

namespace BlueSpice\TranslationTransfer\Data\Glossary;

use MWStake\MediaWiki\Component\DataStore\Filter;
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
	 * Record field to DB field mapping.
	 *
	 * @var array
	 */
	private $fields = [
		GlossaryEntryRecord::SOURCE_TEXT => 'tt_ge_source_text',
		GlossaryEntryRecord::SOURCE_NORMALIZED => 'tt_ge_source_normalized_text',
		GlossaryEntryRecord::TRANSLATION_TEXT => 'tt_ge_translation',
		GlossaryEntryRecord::TRANSLATION_NORMALIZED => 'tt_ge_normalized_translation',
	];

	/**
	 * List of fields which are stored in DB in normalized format.
	 * Such fields' values should always be lower-cased before comparison.
	 *
	 * @var string[]
	 */
	private $normalizedFields = [
		GlossaryEntryRecord::SOURCE_NORMALIZED,
		GlossaryEntryRecord::TRANSLATION_NORMALIZED
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
		return [ 'bs_tt_glossary_entries' ];
	}

	/**
	 * @return array
	 */
	protected function getDefaultConds() {
		return [
			'tt_ge_lang' => $this->selectedLanguage
		];
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
	protected function appendRowToData( \stdClass $row ) {
		$recordData = [];
		foreach ( $this->fields as $recordField => $dbField ) {
			$recordData[$recordField] = $row->{$dbField};
		}

		$this->data[] = new GlossaryEntryRecord( (object)$recordData );
	}
}
