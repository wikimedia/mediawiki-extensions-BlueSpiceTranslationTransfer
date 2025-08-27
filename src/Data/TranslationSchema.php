<?php

namespace BlueSpice\TranslationTransfer\Data;

use MWStake\MediaWiki\Component\DataStore\FieldType;
use MWStake\MediaWiki\Component\DataStore\Schema;

class TranslationSchema extends Schema {

	public function __construct() {
		parent::__construct( [
			TranslationRecord::SOURCE_LANGUAGE => [
				self::FILTERABLE => true,
				self::SORTABLE => true,
				self::TYPE => FieldType::STRING
			],
			TranslationRecord::SOURCE_PAGE_NORMALIZED_TEXT => [
				self::FILTERABLE => true,
				self::SORTABLE => true,
				self::TYPE => FieldType::STRING
			],
			TranslationRecord::SOURCE_PAGE_LINK => [
				self::FILTERABLE => false,
				self::SORTABLE => false,
				self::TYPE => FieldType::STRING
			],
			TranslationRecord::TARGET_LANGUAGE => [
				self::FILTERABLE => true,
				self::SORTABLE => true,
				self::TYPE => FieldType::STRING
			],
			TranslationRecord::TARGET_PAGE_NORMALIZED_TEXT => [
				self::FILTERABLE => true,
				self::SORTABLE => true,
				self::TYPE => FieldType::STRING
			],
			TranslationRecord::TARGET_PAGE_LINK => [
				self::FILTERABLE => false,
				self::SORTABLE => false,
				self::TYPE => FieldType::STRING
			],
			TranslationRecord::RELEASE_TIMESTAMP => [
				self::FILTERABLE => false,
				self::SORTABLE => true,
				self::TYPE => FieldType::STRING
			],
			TranslationRecord::SOURCE_LAST_CHANGE_TIMESTAMP => [
				self::FILTERABLE => false,
				self::SORTABLE => true,
				self::TYPE => FieldType::STRING
			],
			TranslationRecord::TARGET_LAST_CHANGE_TIMESTAMP => [
				self::FILTERABLE => false,
				self::SORTABLE => true,
				self::TYPE => FieldType::STRING
			],
			TranslationRecord::RELEASE_TIMESTAMP_FORMATTED => [
				self::FILTERABLE => false,
				self::SORTABLE => false,
				self::TYPE => FieldType::STRING
			],
			TranslationRecord::SOURCE_LAST_CHANGE_TIMESTAMP_FORMATTED => [
				self::FILTERABLE => false,
				self::SORTABLE => false,
				self::TYPE => FieldType::STRING
			],
			TranslationRecord::TARGET_LAST_CHANGE_TIMESTAMP_FORMATTED => [
				self::FILTERABLE => false,
				self::SORTABLE => false,
				self::TYPE => FieldType::STRING
			],
		] );
	}

}
