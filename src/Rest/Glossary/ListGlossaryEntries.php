<?php

namespace BlueSpice\TranslationTransfer\Rest\Glossary;

use BlueSpice\TranslationTransfer\Data\Glossary\Store;
use MediaWiki\HookContainer\HookContainer;
use MWStake\MediaWiki\Component\CommonWebAPIs\Rest\QueryStore;
use MWStake\MediaWiki\Component\DataStore\IStore;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\Rdbms\ILoadBalancer;

class ListGlossaryEntries extends QueryStore {

	/**
	 * @var ILoadBalancer
	 */
	private $lb;

	/**
	 * @var string
	 */
	private $selectedLanguage = '';

	/**
	 * @param HookContainer $hookContainer
	 * @param ILoadBalancer $lb
	 */
	public function __construct(
		HookContainer $hookContainer,
		ILoadBalancer $lb
	) {
		parent::__construct( $hookContainer );

		$this->lb = $lb;
	}

	/**
	 * @inheritDoc
	 */
	public function execute() {
		$this->selectedLanguage = $this->getValidatedParams()['targetLang'];

		return parent::execute();
	}

	/**
	 * @inheritDoc
	 */
	protected function getStore(): IStore {
		return new Store(
			$this->lb,
			$this->selectedLanguage,
		);
	}

	/**
	 * @inheritDoc
	 */
	protected function getStoreSpecificParams(): array {
		return [
			'targetLang' => [
				static::PARAM_SOURCE => 'path',
				ParamValidator::PARAM_REQUIRED => true,
				ParamValidator::PARAM_TYPE => 'string'
			]
		];
	}
}
