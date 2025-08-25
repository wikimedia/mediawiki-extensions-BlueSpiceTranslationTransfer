<?php

namespace BlueSpice\TranslationTransfer\Job;

use BlueSpice\TranslationTransfer\Util\TranslationsDao;
use Job;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;

/**
 * Job which updates "release timestamp" for specific translations in shared "translation table".
 *
 * No title context here.
 */
class UpdateTranslationsReleaseTimestamp extends Job implements LoggerAwareInterface {

	public const COMMAND = 'UpdateTranslationsReleaseTimestamp';

	public const PARAM_TARGETS = 'targets_to_update';

	public const PARAM_TARGET_DB_KEY = 'db_key';

	public const PARAM_TARGET_LANG = 'target_lang';

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
		$this->logger->debug(
			'Start updating release timestamps for translation targets after source moving...'
		);

		$targetsToUpdate = $this->params[ static::PARAM_TARGETS ];
		foreach ( $targetsToUpdate as $target ) {
			$targetPrefixedDbKey = $target[ static::PARAM_TARGET_DB_KEY ];
			$targetLang = $target[ static::PARAM_TARGET_LANG ];

			$this->logger->debug( "Updated for title '$targetPrefixedDbKey', lang - '$targetLang'" );

			$this->translationsDao->updateTranslationReleaseTimestamp( $targetPrefixedDbKey, $targetLang );
		}
	}
}
