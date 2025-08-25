<?php

namespace BlueSpice\TranslationTransfer\Data;

use BlueSpice\TranslationTransfer\Util\ContentTransfer\TargetRecognizer;
use MWStake\MediaWiki\Component\DataStore\IStore;
use Wikimedia\Rdbms\ILoadBalancer;

class Store implements IStore {

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
		$this->lb = $lb;
		$this->targetRecognizer = $targetRecognizer;
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
		return new Reader( $this->lb, $this->targetRecognizer );
	}
}
