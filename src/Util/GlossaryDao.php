<?php

namespace BlueSpice\TranslationTransfer\Util;

use MediaWiki\Json\FormatJson;
use Wikimedia\Rdbms\ILoadBalancer;

class GlossaryDao {

	private const GLOSSARY_ID_CONFIG_NAME = 'TranslateTransferDeeplGlossaryId';

	/**
	 * @var ILoadBalancer
	 */
	private $lb;

	/**
	 * @param ILoadBalancer $lb
	 */
	public function __construct( ILoadBalancer $lb ) {
		$this->lb = $lb;
	}

	/**
	 * @param string $lang
	 * @param string $sourceText
	 * @param string $newTranslation
	 * @return void
	 */
	public function updateEntry( string $lang, string $sourceText, string $newTranslation ): void {
		$dbw = $this->lb->getConnection( DB_PRIMARY );

		// DeepL is pretty strict about glossary format
		// We need to make sure that each entry will not have starting/trailing whitespaces
		$newTranslation = trim( $newTranslation );

		$row = [
			'tt_ge_translation' => $newTranslation,
			'tt_ge_normalized_translation' => strtolower( $newTranslation )
		];

		$dbw->update(
			'bs_tt_glossary_entries',
			$row,
			[
				'tt_ge_source_text' => $sourceText,
				'tt_ge_lang' => $lang
			],
			__METHOD__
		);
	}

	/**
	 * @param string $lang
	 * @param string $sourceText
	 * @param string $translation
	 * @return void
	 */
	public function insertEntry( string $lang, string $sourceText, string $translation ): void {
		$dbw = $this->lb->getConnection( DB_PRIMARY );

		// DeepL is pretty strict about glossary format
		// We need to make sure that each entry will not have starting/trailing whitespaces
		$sourceText = trim( $sourceText );
		$translation = trim( $translation );

		$row = [
			'tt_ge_source_text' => $sourceText,
			'tt_ge_source_normalized_text' => strtolower( $sourceText ),
			'tt_ge_lang' => $lang,
			'tt_ge_translation' => $translation,
			'tt_ge_normalized_translation' => strtolower( $translation )
		];

		$dbw->insert(
			'bs_tt_glossary_entries',
			$row,
			__METHOD__
		);
	}

	/**
	 * @param string $lang
	 * @param string $sourceText
	 * @return void
	 */
	public function removeEntry( string $lang, string $sourceText ): void {
		$dbw = $this->lb->getConnection( DB_PRIMARY );

		$dbw->delete(
			'bs_tt_glossary_entries',
			[
				'tt_ge_source_text' => $sourceText,
				'tt_ge_lang' => $lang
			],
			__METHOD__
		);
	}

	/**
	 * @param string $lang
	 * @return array
	 */
	public function getGlossaryEntries( string $lang ): array {
		$entries = [];

		$dbr = $this->lb->getConnection( DB_REPLICA );

		$res = $dbr->select(
			'bs_tt_glossary_entries',
			[
				'tt_ge_source_text',
				'tt_ge_translation'
			],
			[
				'tt_ge_lang' => $lang
			],
			__METHOD__
		);
		foreach ( $res as $row ) {
			$entries[$row->tt_ge_source_text] = $row->tt_ge_translation;
		}

		return $entries;
	}

	/**
	 * Get the single multilingual glossary ID from config storage.
	 *
	 * @return string|null <tt>null</tt> if glossary does not exist yet
	 */
	public function getGlossaryId(): ?string {
		$dbr = $this->lb->getConnection( DB_REPLICA );

		$value = $dbr->selectField(
			'bs_settings3',
			's_value',
			[
				's_name' => self::GLOSSARY_ID_CONFIG_NAME
			],
			__METHOD__
		);

		if ( $value === false ) {
			return null;
		}

		$decoded = FormatJson::decode( $value, true );
		return is_string( $decoded ) ? $decoded : null;
	}

	/**
	 * Persist the single multilingual glossary ID to config storage.
	 *
	 * @param string $id
	 * @return void
	 */
	public function persistGlossaryId( string $id ): void {
		$dbw = $this->lb->getConnection( DB_PRIMARY );

		$existing = $dbw->selectField(
			'bs_settings3',
			'1',
			[
				's_name' => self::GLOSSARY_ID_CONFIG_NAME
			],
			__METHOD__
		);

		$row = [
			's_name' => self::GLOSSARY_ID_CONFIG_NAME,
			's_value' => FormatJson::encode( $id ),
		];

		if ( $existing ) {
			$dbw->update(
				'bs_settings3',
				$row,
				[
					's_name' => self::GLOSSARY_ID_CONFIG_NAME
				],
				__METHOD__
			);
		} else {
			$dbw->insert(
				'bs_settings3',
				$row,
				__METHOD__
			);
		}
	}

	/**
	 * Clear the glossary ID from config storage.
	 *
	 * @return void
	 */
	public function clearGlossaryId(): void {
		$dbw = $this->lb->getConnection( DB_PRIMARY );

		$dbw->delete(
			'bs_settings3',
			[
				's_name' => self::GLOSSARY_ID_CONFIG_NAME
			],
			__METHOD__
		);
	}
}
