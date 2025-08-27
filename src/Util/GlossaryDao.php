<?php

namespace BlueSpice\TranslationTransfer\Util;

use Wikimedia\Rdbms\ILoadBalancer;

class GlossaryDao {

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

		// TODO: Is it okay to do that in DAO, or should we do that in "client code"?
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

		// TODO: Is it okay to do that in DAO, or should we do that in "client code"?
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
	 * @param string $lang
	 * @return string|null <tt>null</tt> if glossary for that language does not exist in DB yet
	 */
	public function getGlossaryId( string $lang ): ?string {
		$dbr = $this->lb->getConnection( DB_REPLICA );

		$glossaryId = $dbr->selectField(
			'bs_tt_glossary',
			'tt_glossary_id',
			[
				'tt_glossary_lang' => $lang
			],
			__METHOD__
		);

		if ( $glossaryId === false ) {
			return null;
		}

		return $glossaryId;
	}

	/**
	 * @param string $lang
	 * @param string $id
	 * @return void
	 */
	public function persistGlossaryId( string $lang, string $id ): void {
		$dbw = $this->lb->getConnection( DB_PRIMARY );

		// Check if glossary for that language already exists in DB
		$glossaryExists = $dbw->selectField(
			'bs_tt_glossary',
			'1',
			[
				'tt_glossary_lang' => $lang
			],
			__METHOD__
		);

		$row = [
			'tt_glossary_id' => $id,
			'tt_glossary_lang' => $lang,
		];

		// It would be case for "upsert", but it sometimes works weird.
		if ( $glossaryExists ) {
			$dbw->update(
				'bs_tt_glossary',
				$row,
				[
					'tt_glossary_lang' => $lang
				],
				__METHOD__
			);
		} else {
			$dbw->insert(
				'bs_tt_glossary',
				$row,
				__METHOD__
			);
		}
	}
}
