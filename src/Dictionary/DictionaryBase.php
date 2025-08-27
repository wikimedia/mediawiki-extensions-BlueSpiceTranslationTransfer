<?php

namespace BlueSpice\TranslationTransfer\Dictionary;

use BlueSpice\TranslationTransfer\IDictionary;
use MediaWiki\MediaWikiServices;
use Wikimedia\Rdbms\ILoadBalancer;

abstract class DictionaryBase implements IDictionary {

	/**
	 * @var ILoadBalancer
	 */
	protected $lb;

	/**
	 * Returns name of the table, associated with specific translated entity.
	 *
	 * @return string
	 */
	abstract protected function getTableName(): string;

	/**
	 * @return IDictionary
	 */
	public static function factory(): IDictionary {
		return new static(
			MediaWikiServices::getInstance()->getDBLoadBalancer()
		);
	}

	/**
	 * @param ILoadBalancer $lb
	 */
	public function __construct( ILoadBalancer $lb ) {
		$this->lb = $lb;
	}
}
