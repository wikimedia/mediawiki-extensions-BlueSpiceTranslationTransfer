<?php

namespace BlueSpice\TranslationTransfer\Rest;

use BlueSpice\TranslationTransfer\Data\Store;
use BlueSpice\TranslationTransfer\Util\ContentTransfer\TargetRecognizer;
use MediaWiki\HookContainer\HookContainer;
use MWStake\MediaWiki\Component\CommonWebAPIs\Rest\QueryStore;
use MWStake\MediaWiki\Component\DataStore\IStore;
use Wikimedia\Rdbms\ILoadBalancer;

class ListTranslations extends QueryStore {

	/**
	 * @var ILoadBalancer
	 */
	private $lb;

	/**
	 * @var TargetRecognizer
	 */
	private $targetRecognizer;

	/**
	 * @param HookContainer $hookContainer
	 * @param ILoadBalancer $lb
	 * @param TargetRecognizer $targetRecognizer
	 */
	public function __construct(
		HookContainer $hookContainer,
		ILoadBalancer $lb,
		TargetRecognizer $targetRecognizer
	) {
		parent::__construct( $hookContainer );
		$this->lb = $lb;
		$this->targetRecognizer = $targetRecognizer;
	}

	/**
	 * @inheritDoc
	 */
	protected function getStore(): IStore {
		return new Store( $this->lb, $this->targetRecognizer );
	}
}
