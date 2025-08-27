<?php

namespace BlueSpice\TranslationTransfer\Job;

use BlueSpice\TranslationTransfer\Util\TranslationsDao;
use Job;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use Mediawiki\Title\Title;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;

class UpdateTranslationTargetTimestamp extends Job implements LoggerAwareInterface {

	public const COMMAND = 'UpdateTranslationTargetTimestamp';

	public const PARAM_TARGET_PREFIXED_DB_KEY = 'target_prefixed_db_key';

	public const PARAM_TARGET_LANG = 'target_lang';

	public const PARAM_CHANGE_TIMESTAMP = 'change_timestamp';

	/**
	 * @var LoggerInterface
	 */
	private $logger;

	/**
	 * @var TranslationsDao
	 */
	private $translationsDao;

	/**
	 * @param Title $title We do not actually need title context here,
	 * 		but it is always passed as first parameter to the job which is executed by "runJobs.php"
	 * @param array $params
	 */
	public function __construct( $title, $params ) {
		parent::__construct( static::COMMAND, $params );

		$services = MediaWikiServices::getInstance();
		$lb = $services->getDBLoadBalancer();

		$this->translationsDao = new TranslationsDao( $lb );

		$this->logger = LoggerFactory::getInstance( 'BlueSpiceTranslationTransfer' );
	}

	/**
	 * @inheritDoc
	 */
	public function setLogger( LoggerInterface $logger ) {
		$this->logger = $logger;
	}

	/**
	 * @inheritDoc
	 */
	public function run() {
		$targetPrefixedDbKey = $this->params[ static::PARAM_TARGET_PREFIXED_DB_KEY ];
		$targetLang = $this->params[ static::PARAM_TARGET_LANG ];

		$timestamp = wfTimestamp( TS_MW );
		if ( !empty( $this->params[ static::PARAM_CHANGE_TIMESTAMP ] ) ) {
			$timestamp = $this->params[ static::PARAM_CHANGE_TIMESTAMP ];
		}

		$this->logger->debug(
			"Updating of target last change timestamp for title '{$targetPrefixedDbKey}'..."
		);

		$this->translationsDao->updateTranslationTargetLastChange( $targetPrefixedDbKey, $targetLang, $timestamp );

		$this->logger->debug( "Target last change timestamp updated!" );
	}
}
