<?php

namespace BlueSpice\TranslationTransfer\Data;

use BlueSpice\TranslationTransfer\Util\ContentTransfer\TargetRecognizer;
use Wikimedia\Rdbms\ILoadBalancer;

class Reader extends \MWStake\MediaWiki\Component\DataStore\Reader {

	/**
	 * @var ILoadBalancer
	 */
	private $lb;

	/**
	 * @var TargetRecognizer
	 */
	private $targetRecognizer;

	/**
	 * @param ILoadBalancer $lb
	 * @param TargetRecognizer $targetRecognizer
	 */
	public function __construct( ILoadBalancer $lb, TargetRecognizer $targetRecognizer ) {
		parent::__construct();
		$this->lb = $lb;
		$this->targetRecognizer = $targetRecognizer;
	}

	/**
	 * @inheritDoc
	 */
	public function getSchema() {
		return new TranslationSchema();
	}

	/**
	 * @inheritDoc
	 */
	protected function makePrimaryDataProvider( $params ) {
		return new PrimaryDataProvider(
			$this->lb->getConnection( DB_REPLICA ),
			$this->getSchema()
		);
	}

	/**
	 * @inheritDoc
	 */
	protected function makeSecondaryDataProvider() {
		return new SecondaryDataProvider( $this->targetRecognizer );
	}
}
