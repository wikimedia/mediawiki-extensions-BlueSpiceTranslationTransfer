<?php

namespace BlueSpice\TranslationTransfer\Data\Dictionary;

use BlueSpice\TranslationTransfer\Util\ContentTransfer\TargetRecognizer;
use MediaWiki\Config\ConfigFactory;
use MediaWiki\Language\Language;
use MediaWiki\Languages\LanguageFactory;
use MediaWiki\Title\TitleFactory;
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
	 * @param ILoadBalancer $lb
	 * @param string $selectedLanguage
	 * @param LanguageFactory $languageFactory
	 * @param Language $contentLanguage
	 * @param ConfigFactory $configFactory
	 * @param TitleFactory $titleFactory
	 * @param TargetRecognizer $targetRecognizer
	 */
	public function __construct(
		ILoadBalancer $lb,
		string $selectedLanguage,
		LanguageFactory $languageFactory,
		Language $contentLanguage,
		ConfigFactory $configFactory,
		TitleFactory $titleFactory,
		TargetRecognizer $targetRecognizer
	) {
		$this->lb = $lb;
		$this->selectedLanguage = $selectedLanguage;
		$this->languageFactory = $languageFactory;
		$this->contentLanguage = $contentLanguage;
		$this->configFactory = $configFactory;
		$this->titleFactory = $titleFactory;
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
		return new Reader(
			$this->lb,
			$this->selectedLanguage,
			$this->languageFactory,
			$this->contentLanguage,
			$this->configFactory,
			$this->titleFactory,
			$this->targetRecognizer
		);
	}
}
