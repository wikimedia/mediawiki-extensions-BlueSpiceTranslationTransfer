<?php

namespace BlueSpice\TranslationTransfer\Data\Dictionary;

use BlueSpice\TranslationTransfer\Util\ContentTransfer\TargetRecognizer;
use MediaWiki\Config\ConfigFactory;
use MediaWiki\Language\Language;
use MediaWiki\Languages\LanguageFactory;
use MediaWiki\Title\TitleFactory;
use MWStake\MediaWiki\Component\DataStore\ISecondaryDataProvider;
use MWStake\MediaWiki\Component\DataStore\ResultSet;
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
		parent::__construct();

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
	public function read( $params ) {
		$primaryDataProvider = $this->makePrimaryDataProvider( $params );
		$dataSets = $primaryDataProvider->makeData( $params );

		// Here we need to just to not do regular filtering
		// We need that because we already did necessary filtering with OR condition
		// $filterer = $this->makeFilterer( $params );
		// $dataSets = $filterer->filter( $dataSets );
		$total = count( $dataSets );

		$sorter = $this->makeSorter( $params );
		$dataSets = $sorter->sort(
			$dataSets,
			$this->getSchema()->getUnsortableFields()
		);

		$trimmer = $this->makeTrimmer( $params );
		$dataSets = $trimmer->trim( $dataSets );

		$secondaryDataProvider = $this->makeSecondaryDataProvider();
		if ( $secondaryDataProvider instanceof ISecondaryDataProvider ) {
			$dataSets = $secondaryDataProvider->extend( $dataSets );
		}

		$resultSet = new ResultSet( $dataSets, $total );
		return $resultSet;
	}

	/**
	 * @inheritDoc
	 */
	public function getSchema() {
		return new DictionaryEntrySchema();
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
		return new SecondaryDataProvider(
			$this->selectedLanguage,
			$this->languageFactory,
			$this->contentLanguage,
			$this->configFactory,
			$this->titleFactory,
			$this->targetRecognizer,
		);
	}
}
