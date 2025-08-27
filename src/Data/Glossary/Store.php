<?php

namespace BlueSpice\TranslationTransfer\Data\Glossary;

use MWStake\MediaWiki\Component\DataStore\IStore;
use Wikimedia\Rdbms\ILoadBalancer;

class Store implements IStore {

	/**
	 * @var ILoadBalancer
	 */
	private $lb;

	/**
	 * @var string
	 */
	private $selectedLanguage;

	/**
	 * @param ILoadBalancer $lb
	 * @param string $selectedLanguage
	 */
	public function __construct( ILoadBalancer $lb, string $selectedLanguage ) {
		$this->lb = $lb;
		$this->selectedLanguage = $selectedLanguage;
	}

	/**
	 * @inheritDoc
	 */
	public function getWriter() {
		return null;
	}

	/**
	 * @inheritDoc
	 */
	public function getReader() {
		return new Reader( $this->lb, $this->selectedLanguage );
	}
}
