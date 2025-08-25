<?php

namespace BlueSpice\TranslationTransfer\Rest\Dictionary;

use BlueSpice\TranslationTransfer\Data\Dictionary\Store;
use BlueSpice\TranslationTransfer\Util\ContentTransfer\TargetRecognizer;
use MediaWiki\Config\ConfigFactory;
use MediaWiki\HookContainer\HookContainer;
use MediaWiki\Language\Language;
use MediaWiki\Languages\LanguageFactory;
use MediaWiki\Title\TitleFactory;
use MWStake\MediaWiki\Component\CommonWebAPIs\Rest\QueryStore;
use MWStake\MediaWiki\Component\DataStore\IStore;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\Rdbms\ILoadBalancer;

class ListDictionaryEntries extends QueryStore {

	/**
	 * @var ILoadBalancer
	 */
	private $lb;

	/**
	 * @var string
	 */
	private $selectedLanguage = '';

	/**
	 * @var LanguageFactory
	 */
	private $languageFactory;

	/**
	 * @var Language
	 */
	private $contentLanguage;

	/**
	 * @var ConfigFactory
	 */
	private $configFactory;

	/**
	 * @var TitleFactory
	 */
	private $titleFactory;

	/**
	 * @var TargetRecognizer
	 */
	private $targetRecognizer;

	/**
	 * @param HookContainer $hookContainer
	 * @param ILoadBalancer $lb
	 * @param LanguageFactory $languageFactory
	 * @param Language $contentLanguage
	 * @param ConfigFactory $configFactory
	 * @param TitleFactory $titleFactory
	 * @param TargetRecognizer $targetRecognizer
	 */
	public function __construct(
		HookContainer $hookContainer,
		ILoadBalancer $lb,
		LanguageFactory $languageFactory,
		Language $contentLanguage,
		ConfigFactory $configFactory,
		TitleFactory $titleFactory,
		TargetRecognizer $targetRecognizer
	) {
		parent::__construct( $hookContainer );

		$this->lb = $lb;
		$this->languageFactory = $languageFactory;
		$this->contentLanguage = $contentLanguage;
		$this->configFactory = $configFactory;
		$this->titleFactory = $titleFactory;
		$this->targetRecognizer = $targetRecognizer;
	}

	/**
	 * @inheritDoc
	 */
	public function execute() {
		$this->selectedLanguage = $this->getValidatedParams()['langCodeTo'];

		return parent::execute();
	}

	/**
	 * @inheritDoc
	 */
	protected function getStore(): IStore {
		return new Store(
			$this->lb,
			$this->selectedLanguage,
			$this->languageFactory,
			$this->contentLanguage,
			$this->configFactory,
			$this->titleFactory,
			$this->targetRecognizer
		);
	}

	/**
	 * @inheritDoc
	 */
	protected function getStoreSpecificParams(): array {
		return [
			'langCodeTo' => [
				static::PARAM_SOURCE => 'path',
				ParamValidator::PARAM_REQUIRED => true,
				ParamValidator::PARAM_TYPE => 'string',
			]
		];
	}
}
