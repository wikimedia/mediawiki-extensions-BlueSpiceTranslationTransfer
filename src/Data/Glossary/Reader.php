<?php

namespace BlueSpice\TranslationTransfer\Data\Glossary;

use Wikimedia\Rdbms\ILoadBalancer;

class Reader extends \MWStake\MediaWiki\Component\DataStore\Reader {

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
		parent::__construct();

		$this->lb = $lb;
		$this->selectedLanguage = $selectedLanguage;
	}

	/**
	 * @inheritDoc
	 */
	public function getSchema() {
		return new GlossaryEntrySchema();
	}

	/**
	 * @inheritDoc
	 */
	protected function makePrimaryDataProvider( $params ) {
		return new PrimaryDataProvider(
			$this->lb->getConnection( DB_REPLICA ),
			$this->getSchema(),
			$this->selectedLanguage
		);
	}

	/**
	 * @inheritDoc
	 */
	protected function makeSecondaryDataProvider() {
		return null;
	}

}
