<?php

namespace BlueSpice\TranslationTransfer\Data\Dictionary;

use MWStake\MediaWiki\Component\DataStore\FieldType;
use MWStake\MediaWiki\Component\DataStore\Schema;

class DictionaryEntrySchema extends Schema {

	public function __construct() {
		parent::__construct( [
			DictionaryEntryRecord::SOURCE_NORMALIZED => [
				self::FILTERABLE => true,
				self::SORTABLE => true,
				self::TYPE => FieldType::STRING
			],
			DictionaryEntryRecord::TRANSLATION_NORMALIZED => [
				self::FILTERABLE => true,
				self::SORTABLE => true,
				self::TYPE => FieldType::STRING
			],
		] );
	}
}
