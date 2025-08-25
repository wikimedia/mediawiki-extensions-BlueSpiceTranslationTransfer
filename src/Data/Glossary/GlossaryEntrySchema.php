<?php

namespace BlueSpice\TranslationTransfer\Data\Glossary;

use MWStake\MediaWiki\Component\DataStore\FieldType;
use MWStake\MediaWiki\Component\DataStore\Schema;

class GlossaryEntrySchema extends Schema {
	public function __construct() {
		parent::__construct( [
			GlossaryEntryRecord::SOURCE_NORMALIZED => [
				self::FILTERABLE => true,
				self::SORTABLE => true,
				self::TYPE => FieldType::STRING
			],
			GlossaryEntryRecord::TRANSLATION_NORMALIZED => [
				self::FILTERABLE => true,
				self::SORTABLE => true,
				self::TYPE => FieldType::STRING
			],
		] );
	}
}
