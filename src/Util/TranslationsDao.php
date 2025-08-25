<?php

namespace BlueSpice\TranslationTransfer\Util;

use BlueSpice\TranslationTransfer\Tests\Util\TranslationsDaoTest;
use Wikimedia\Rdbms\ILoadBalancer;

/**
 * @see TranslationsDaoTest
 */
class TranslationsDao {

	/**
	 * @var string
	 */
	private $table = 'bs_translationtransfer_translations';

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
	 * @param string $prefixedTitle
	 * @return bool
	 */
	public function isSource( string $lang, string $prefixedTitle ): bool {
		return (bool)$this->lb->getConnection( DB_REPLICA )->selectField(
			$this->table,
			'tt_translations_source_prefixed_title_key',
			[
				'tt_translations_source_prefixed_title_key' => $prefixedTitle,
				'tt_translations_source_lang' => strtoupper( $lang )
			],
			__METHOD__
		);
	}

	/**
	 * @param string $lang
	 * @param string $prefixedTitle
	 * @return bool
	 */
	public function isTarget( string $lang, string $prefixedTitle ): bool {
		return (bool)$this->lb->getConnection( DB_REPLICA )->selectField(
			$this->table,
			'tt_translations_target_prefixed_title_key',
			[
				'tt_translations_target_prefixed_title_key' => $prefixedTitle,
				'tt_translations_target_lang' => strtoupper( $lang )
			],
			__METHOD__
		);
	}

	/**
	 * Finds translation by "target title" + "target language" and updates information.
	 * If such translation does not exist yet - new one is being created.
	 *
	 * @param string $sourceLang
	 * @param string $sourcePrefixedTitle
	 * @param string $targetLang
	 * @param string $targetPrefixedTitle
	 * @param string $sourceLastChangeTimestamp
	 * @return void
	 */
	public function updateTranslation(
		string $sourceLang, string $sourcePrefixedTitle,
		string $targetLang, string $targetPrefixedTitle,
		string $sourceLastChangeTimestamp
	): void {
		$row = [
			'tt_translations_source_prefixed_title_key' => $sourcePrefixedTitle,
			'tt_translations_source_normalized_title' => $this->normalizeTitle( $sourcePrefixedTitle ),
			'tt_translations_source_lang' => strtoupper( $sourceLang ),
			'tt_translations_target_prefixed_title_key' => $targetPrefixedTitle,
			'tt_translations_target_normalized_title' => $this->normalizeTitle( $targetPrefixedTitle ),
			'tt_translations_target_lang' => strtoupper( $targetLang ),
			'tt_translations_release_date' => wfTimestamp( TS_MW ),
			'tt_translations_source_last_change_date' => $sourceLastChangeTimestamp,
			// Will be filled a bit later.
			// "PageContentSaveComplete" hook on target wiki after creating necessary wiki page
			// with API "edit" request
			'tt_translations_target_last_change_date' => ''
		];

		$dbw = $this->lb->getConnection( DB_PRIMARY );
		$dbw->upsert(
			$this->table,
			$row,
			[
				[
					'tt_translations_target_prefixed_title_key',
					'tt_translations_target_lang'
				],
			],
			$row,
			__METHOD__
		);
	}

	/**
	 * @param string $targetPrefixedDbKey
	 * @param string $targetLang
	 * @param string $newPrefixedDbKey
	 * @return void
	 */
	public function updateTranslationTarget(
		string $targetPrefixedDbKey,
		string $targetLang,
		string $newPrefixedDbKey
	): void {
		$dbw = $this->lb->getConnection( DB_PRIMARY );
		$dbw->update(
			$this->table,
			[
				'tt_translations_target_prefixed_title_key' => $newPrefixedDbKey,
				'tt_translations_target_normalized_title' => $this->normalizeTitle( $newPrefixedDbKey )
			],
			[
				'tt_translations_target_prefixed_title_key' => $targetPrefixedDbKey,
				'tt_translations_target_lang' => strtoupper( $targetLang )
			],
			__METHOD__
		);
	}

	/**
	 * @param string $sourcePrefixedDbKey
	 * @param string $sourceLang
	 * @param string $newPrefixedDbKey
	 * @return void
	 */
	public function updateTranslationSource(
		string $sourcePrefixedDbKey,
		string $sourceLang,
		string $newPrefixedDbKey
	): void {
		$dbw = $this->lb->getConnection( DB_PRIMARY );
		$dbw->update(
			$this->table,
			[
				'tt_translations_source_prefixed_title_key' => $newPrefixedDbKey,
				'tt_translations_source_normalized_title' => $this->normalizeTitle( $newPrefixedDbKey )
			],
			[
				'tt_translations_source_prefixed_title_key' => $sourcePrefixedDbKey,
				'tt_translations_source_lang' => strtoupper( $sourceLang )
			],
			__METHOD__
		);
	}

	/**
	 * @param string $sourcePrefixedDbKey
	 * @param string $sourceLang
	 * @param string $sourceLastChangeTimestamp
	 * @return void
	 */
	public function updateTranslationSourceLastChange(
		string $sourcePrefixedDbKey,
		string $sourceLang,
		string $sourceLastChangeTimestamp
	): void {
		$dbw = $this->lb->getConnection( DB_PRIMARY );
		$dbw->update(
			$this->table,
			[
				'tt_translations_source_last_change_date' => $sourceLastChangeTimestamp,
				'tt_translations_translation_acked' => 0
			],
			[
				'tt_translations_source_prefixed_title_key' => $sourcePrefixedDbKey,
				'tt_translations_source_lang' => strtoupper( $sourceLang )
			],
			__METHOD__
		);
	}

	/**
	 * @param string $targetPrefixedDbKey
	 * @param string $targetLang
	 * @param string $targetLastChangeTimestamp
	 * @return void
	 */
	public function updateTranslationTargetLastChange(
		string $targetPrefixedDbKey,
		string $targetLang,
		string $targetLastChangeTimestamp
	): void {
		$dbw = $this->lb->getConnection( DB_PRIMARY );
		$dbw->update(
			$this->table,
			[
				'tt_translations_target_last_change_date' => $targetLastChangeTimestamp
			],
			[
				'tt_translations_target_prefixed_title_key' => $targetPrefixedDbKey,
				'tt_translations_target_lang' => strtoupper( $targetLang )
			],
			__METHOD__
		);
	}

	/**
	 * @param string $targetPrefixedDbKey
	 * @param string $targetLang
	 * @param string|null $translationReleaseTimestamp
	 * @return void
	 */
	public function updateTranslationReleaseTimestamp(
		string $targetPrefixedDbKey,
		string $targetLang,
		?string $translationReleaseTimestamp = null
	): void {
		// If no timestamp provided - use current timestamp
		if ( !$translationReleaseTimestamp ) {
			$translationReleaseTimestamp = wfTimestamp( TS_MW );
		}

		$dbw = $this->lb->getConnection( DB_PRIMARY );
		$dbw->update(
			$this->table,
			[
				'tt_translations_release_date' => $translationReleaseTimestamp
			],
			[
				'tt_translations_target_prefixed_title_key' => $targetPrefixedDbKey,
				'tt_translations_target_lang' => strtoupper( $targetLang )
			],
			__METHOD__
		);
	}

	/**
	 * Acknowledges specified translation.
	 * That means that "outdated translation" banner will not show for that specific translation.
	 *
	 * @param string $targetPrefixedDbKey
	 * @param string $targetLang
	 *
	 * @return void
	 */
	public function ackTranslation(
		string $targetPrefixedDbKey,
		string $targetLang
	): void {
		$dbw = $this->lb->getConnection( DB_PRIMARY );
		$dbw->update(
			$this->table,
			[
				'tt_translations_translation_acked' => 1
			],
			[
				'tt_translations_target_prefixed_title_key' => $targetPrefixedDbKey,
				'tt_translations_target_lang' => strtoupper( $targetLang )
			],
			__METHOD__
		);
	}

	/**
	 * Checks if specified translation is "acknowledged".
	 *
	 * @param string $targetPrefixedDbKey
	 * @param string $targetLang
	 * @return bool <tt>true</tt> if specified translation is "acknowledged"
	 * 		and <tt>false</tt> otherwise
	 * @see TranslationsDao::ackTranslation()
	 */
	public function isTranslationAcked(
		string $targetPrefixedDbKey,
		string $targetLang
	): bool {
		return (bool)$this->lb->getConnection( DB_REPLICA )->selectField(
			$this->table,
			'tt_translations_translation_acked',
			[
				'tt_translations_target_prefixed_title_key' => $targetPrefixedDbKey,
				'tt_translations_target_lang' => strtoupper( $targetLang )
			],
			__METHOD__
		);
	}

	/**
	 * Removes specified translation, recognized by "target" title
	 *
	 * @param string $targetPrefixedDbKey
	 * @param string $targetLang
	 *
	 * @return void
	 */
	public function removeTranslation(
		string $targetPrefixedDbKey,
		string $targetLang
	): void {
		$dbw = $this->lb->getConnection( DB_PRIMARY );
		$dbw->delete(
			$this->table,
			[
				'tt_translations_target_prefixed_title_key' => $targetPrefixedDbKey,
				'tt_translations_target_lang' => strtoupper( $targetLang )
			],
			__METHOD__
		);
	}

	/**
	 * Removes all translations connected with specified "source" title
	 *
	 * @param string $sourcePrefixedDbKey
	 * @param string $sourceLang
	 *
	 * @return void
	 */
	public function removeAllSourceTranslations(
		string $sourcePrefixedDbKey,
		string $sourceLang
	): void {
		$dbw = $this->lb->getConnection( DB_PRIMARY );
		$dbw->delete(
			$this->table,
			[
				'tt_translations_source_prefixed_title_key' => $sourcePrefixedDbKey,
				'tt_translations_source_lang' => strtoupper( $sourceLang )
			],
			__METHOD__
		);
	}

	/**
	 * @param string $targetPrefixedDbKey
	 * @param string $targetLang
	 *
	 * @return array|null
	 */
	public function getTranslation(
		string $targetPrefixedDbKey,
		string $targetLang
	): ?array {
		$row = $this->lb->getConnection( DB_REPLICA )->selectRow(
			$this->table,
			[
				'tt_translations_source_prefixed_title_key',
				'tt_translations_source_normalized_title',
				'tt_translations_source_lang',
				'tt_translations_target_prefixed_title_key',
				'tt_translations_target_normalized_title',
				'tt_translations_target_lang',
				'tt_translations_release_date',
				'tt_translations_source_last_change_date',
				'tt_translations_target_last_change_date'
			],
			[
				'tt_translations_target_prefixed_title_key' => $targetPrefixedDbKey,
				'tt_translations_target_lang' => strtoupper( $targetLang )
			],
			__METHOD__
		);

		if ( !$row ) {
			return null;
		}

		return (array)$row;
	}

	/**
	 * @param string $targetPrefixedDbKey
	 * @param string $targetLang
	 * @return array|null
	 */
	public function getSourceFromTarget(
		string $targetPrefixedDbKey,
		string $targetLang
	): ?array {
		$row = $this->lb->getConnection( DB_REPLICA )->selectRow(
			$this->table,
			[
				'tt_translations_source_prefixed_title_key',
				'tt_translations_source_lang'
			],
			[
				'tt_translations_target_prefixed_title_key' => $targetPrefixedDbKey,
				'tt_translations_target_lang' => strtoupper( $targetLang )
			],
			__METHOD__
		);

		if ( !$row ) {
			return null;
		}

		return [
			'key' => $row->tt_translations_source_prefixed_title_key,
			'lang' => $row->tt_translations_source_lang
		];
	}

	/**
	 * Gets timestamp of last change of translation source.
	 * Used only for "target" wiki pages (target of translation).
	 *
	 * @param string $targetPrefixedDbKey
	 * @param string $targetLang
	 * @return string
	 */
	public function getSourceLastChangeTimestamp(
		string $targetPrefixedDbKey,
		string $targetLang
	): string {
		return $this->lb->getConnection( DB_REPLICA )->selectField(
			$this->table,
			'tt_translations_source_last_change_date',
			[
				'tt_translations_target_prefixed_title_key' => $targetPrefixedDbKey,
				'tt_translations_target_lang' => strtoupper( $targetLang )
			],
			__METHOD__
		);
	}

	/**
	 * Gets all existing translations of specified source.
	 *
	 * @param string $sourcePrefixedDbKey
	 * @param string $sourceLang
	 * @return array
	 */
	public function getSourceTranslations(
		string $sourcePrefixedDbKey,
		string $sourceLang
	): array {
		$translations = [];

		$res = $this->lb->getConnection( DB_REPLICA )->select(
			$this->table,
			[
				'tt_translations_target_prefixed_title_key',
				'tt_translations_target_lang',
				'tt_translations_release_date',
				'tt_translations_source_last_change_date'
			],
			[
				'tt_translations_source_prefixed_title_key' => $sourcePrefixedDbKey,
				'tt_translations_source_lang' => strtoupper( $sourceLang )
			],
			__METHOD__
		);

		foreach ( $res as $row ) {
			$targetLang = strtolower( $row->tt_translations_target_lang );

			$translations[$targetLang] = [
				'target_prefixed_key' => $row->tt_translations_target_prefixed_title_key,
				'release_date' => $row->tt_translations_release_date,
				'last_change_date' => $row->tt_translations_source_last_change_date
			];
		}

		return $translations;
	}

	/**
	 * @param string $targetPrefixedDbKey
	 * @param string $targetLang
	 * @return string
	 */
	public function getReleaseTimestamp(
		string $targetPrefixedDbKey,
		string $targetLang
	): string {
		return $this->lb->getConnection( DB_REPLICA )->selectField(
			$this->table,
			'tt_translations_release_date',
			[
				'tt_translations_target_prefixed_title_key' => $targetPrefixedDbKey,
				'tt_translations_target_lang' => strtoupper( $targetLang )
			],
			__METHOD__
		);
	}

	/**
	 * @param string $titlePrefixedDbKey
	 * @return string
	 */
	private function normalizeTitle( string $titlePrefixedDbKey ): string {
		return strtolower( str_replace( '_', ' ', $titlePrefixedDbKey ) );
	}
}
