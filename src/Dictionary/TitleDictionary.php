<?php

namespace BlueSpice\TranslationTransfer\Dictionary;

use BlueSpice\TranslationTransfer\IDictionary;
use BlueSpice\TranslationTransfer\Tests\Dictionary\TitleDictionaryTest;
use Exception;
use MediaWiki\MediaWikiServices;
use MediaWiki\Message\Message;
use MediaWiki\Title\TitleFactory;
use Wikimedia\Rdbms\ILoadBalancer;

/**
 * @see TitleDictionaryTest
 */
class TitleDictionary extends DictionaryBase {

	/**
	 * @var TitleFactory
	 */
	private $titleFactory;

	/**
	 * @inheritDoc
	 */
	protected function getTableName(): string {
		return 'bs_tt_dictionary_title';
	}

	/**
	 * @return IDictionary
	 */
	public static function factory(): IDictionary {
		$services = MediaWikiServices::getInstance();

		return new static(
			$services->getDBLoadBalancer(),
			$services->getTitleFactory()
		);
	}

	/**
	 * @param ILoadBalancer $lb
	 * @param TitleFactory $titleFactory
	 */
	public function __construct( ILoadBalancer $lb, TitleFactory $titleFactory ) {
		parent::__construct( $lb );

		$this->titleFactory = $titleFactory;
	}

	/**
	 * @inheritDoc
	 */
	public function get( string $source, string $targetLang ): ?string {
		$dbr = $this->lb->getConnection( DB_REPLICA );

		// Title dictionary expects prefixed title text, different namespaces should be translated separately
		$title = $this->titleFactory->newFromText( $source );

		$translation = $dbr->selectField(
			$this->getTableName(),
			[ 'tt_dt_translation' ],
			[
				'tt_dt_source_ns_id' => $title->getNamespace(),
				'tt_dt_source_text' => $title->getText(),
				'tt_dt_lang' => $targetLang,
			],
			__METHOD__
		);

		if ( !$translation ) {
			return null;
		}

		return $translation;
	}

	/**
	 * @inheritDoc
	 */
	public function insert( string $source, string $targetLang, string $translation ): bool {
		$dbw = $this->lb->getConnection( DB_PRIMARY );

		// Title dictionary expects prefixed title text, different namespaces should be translated separately
		$title = $this->titleFactory->newFromText( $source );

		// Check if there already is the same translation
		// In the same namespace and for the same target language
		$translationExists = (bool)$dbw->selectField(
			$this->getTableName(),
			[ 'tt_dt_source_text' ],
			[
				'tt_dt_source_ns_id' => $title->getNamespace(),
				'tt_dt_lang' => $targetLang,
				'tt_dt_translation' => $translation,
			],
			__METHOD__
		);
		if ( $translationExists ) {
			// Such cases must not be
			// Pretty rare case, should be handled by user - rename/delete target page,
			// also rename/delete dictionary entry (for next translations)
			// Also existing internal links should be considered
			$errorText = Message::newFromKey( 'bs-translation-transfer-dictionary-translation-exists' )
				->params( $title->getPrefixedDBkey() )->text();

			throw new Exception( $errorText );
		}

		// Otherwise just insert new translation
		$row = [
			'tt_dt_source_ns_id' => $title->getNamespace(),
			'tt_dt_source_text' => $title->getText(),
			'tt_dt_source_normalized_text' => strtolower( $title->getText() ),
			'tt_dt_lang' => $targetLang,
			'tt_dt_translation' => $translation,
			'tt_dt_normalized_translation' => strtolower( $translation ),
		];

		return $dbw->insert(
			$this->getTableName(),
			$row,
			__METHOD__
		);
	}

	/**
	 * @inheritDoc
	 */
	public function update( string $source, string $targetLang, string $translation ): bool {
		$dbw = $this->lb->getConnection( DB_PRIMARY );

		// Title dictionary expects prefixed title text, different namespaces should be translated separately
		$title = $this->titleFactory->newFromText( $source );

		// Check if there already is the same translation for some other source
		// In the same namespace and for the same target language
		$translationExists = (bool)$dbw->selectField(
			$this->getTableName(),
			[ 'tt_dt_source_text' ],
			[
				'tt_dt_source_ns_id' => $title->getNamespace(),
				'tt_dt_lang' => $targetLang,
				'tt_dt_translation' => $translation,
			],
			__METHOD__
		);
		if ( $translationExists ) {
			throw new Exception(
				Message::newFromKey( 'bs-translation-transfer-dictionary-translation-exists' )->text()
			);
		}

		return $dbw->update(
			$this->getTableName(),
			[
				'tt_dt_translation' => $translation,
				'tt_dt_normalized_translation' => strtolower( $translation ),
			],
			[
				'tt_dt_source_ns_id' => $title->getNamespace(),
				'tt_dt_source_text' => $title->getText(),
				'tt_dt_lang' => $targetLang,
			],
			__METHOD__
		);
	}

	/**
	 * @inheritDoc
	 */
	public function remove( string $source, string $targetLang ): void {
		$dbw = $this->lb->getConnection( DB_PRIMARY );

		// Title dictionary expects prefixed title text, different namespaces should be translated separately
		$title = $this->titleFactory->newFromText( $source );

		$dbw->delete(
			$this->getTableName(),
			[
				'tt_dt_source_ns_id' => $title->getNamespace(),
				'tt_dt_source_text' => $title->getText(),
				'tt_dt_lang' => $targetLang,
			],
			__METHOD__
		);
	}
}
