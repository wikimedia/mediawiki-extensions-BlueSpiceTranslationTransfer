<?php

namespace BlueSpice\TranslationTransfer\Rest\Glossary;

use BlueSpice\TranslationTransfer\Util\GlossaryDao;
use Exception;
use GlobalVarConfig;
use MediaWiki\Config\Config;
use MediaWiki\Config\ConfigFactory;
use MediaWiki\Config\MultiConfig;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Rest\Handler;
use Message;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\Rdbms\ILoadBalancer;

/**
 * Algorithm:
 * * Get all entries of glossary for specific language from DB.
 * * Send request to DeepL to delete glossary for that language.
 * * Send request to DeepL to create new glossary for that language.
 * 		Request contains glossary entries from DB, as CSV string.
 */
class SyncRemoteGlossary extends Handler {

	/**
	 * @var GlossaryDao
	 */
	private $glossaryDao;

	/**
	 * @var Config
	 */
	private $config;

	/**
	 * @var HttpRequestFactory
	 */
	private $requestFactory;

	/**
	 * @param ILoadBalancer $lb
	 * @param ConfigFactory $configFactory
	 * @param HttpRequestFactory $requestFactory
	 */
	public function __construct(
		ILoadBalancer $lb,
		ConfigFactory $configFactory,
		HttpRequestFactory $requestFactory
	) {
		$this->glossaryDao = new GlossaryDao( $lb );
		$this->config = new MultiConfig( [
			new GlobalVarConfig( 'mwsg' ),
			$configFactory->makeConfig( 'bsg' )
		] );

		$this->requestFactory = $requestFactory;
	}

	/**
	 * @inheritDoc
	 */
	public function execute() {
		$targetLang = $this->getValidatedParams()['targetLang'];

		try {
			$this->syncGlossary( $targetLang );
		} catch ( Exception $e ) {
			$errorUserText = Message::newFromKey( 'bs-translation-transfer-glossary-sync-error' )->text();

			return $this->getResponseFactory()->createJson( [
				'success' => false,
				'error' => $errorUserText,
				'error_info' => $e->getMessage()
			] );
		}

		return $this->getResponseFactory()->createJson( [
			'success' => true
		] );
	}

	/**
	 * @param string $targetLang
	 * @return void
	 *
	 * @throws Exception
	 */
	private function syncGlossary( string $targetLang ): void {
		$glossaryId = $this->glossaryDao->getGlossaryId( $targetLang );
		if ( $glossaryId !== null ) {
			// Glossary for that language exists in DeepL, so remove it
			$data = array_merge(
				$this->makeOptions(),
				[
					'method' => 'delete'
				]
			);

			$url = $this->makeRootUrl() . '/glossaries/' . $glossaryId;

			$req = $this->requestFactory->create(
				$url,
				$data
			);
			$req->setHeader(
				'Authorization',
				'DeepL-Auth-Key ' . $this->config->get( 'DeeplTranslateServiceAuth' )
			);

			$status = $req->execute();
			if ( !$status->isOK() ) {
				$response = $req->getContent();
				throw new Exception( 'Failed to delete DeepL glossary. Response from DeepL: ' . $response );
			}
		}

		$entriesString = '';

		$entries = $this->glossaryDao->getGlossaryEntries( $targetLang );
		if ( empty( $entries ) ) {
			// No need to create remote glossary if there are no entries.
			// Even if we'll try - it will cause error from DeepL side.
			return;
		}

		foreach ( $entries as $source => $translation ) {
			$entriesString .= "$source,$translation\n";
		}

		$sourceLang = $this->extractSourceLanguage();

		// Create new fresh DeepL glossary
		$data = array_merge(
			$this->makeOptions(),
			[
				'method' => 'post',
				'postData' => [
					'name' => "$sourceLang-$targetLang Glossary",
					'source_lang' => $sourceLang,
					'target_lang' => $targetLang,
					'entries' => $entriesString,
					'entries_format' => 'csv'
				]
			]
		);

		$url = $this->makeRootUrl() . '/glossaries';

		$req = $this->requestFactory->create(
			$url,
			$data
		);
		$req->setHeader(
			'Authorization',
			'DeepL-Auth-Key ' . $this->config->get( 'DeeplTranslateServiceAuth' )
		);

		$status = $req->execute();
		if ( !$status->isOK() ) {
			$response = $req->getContent();
			throw new Exception( 'Failed to create DeepL glossary. Response from DeepL: ' . $response );
		}

		$responseRaw = $req->getContent();
		if ( $responseRaw ) {
			$response = json_decode( $responseRaw, true );

			if ( $response && isset( $response['glossary_id'] ) ) {
				$glossaryId = $response['glossary_id'];

				$this->glossaryDao->persistGlossaryId( $targetLang, $glossaryId );
			}
		}
	}

	/**
	 * @inheritDoc
	 */
	public function getParamSettings() {
		return [
			'targetLang' => [
				static::PARAM_SOURCE => 'path',
				ParamValidator::PARAM_REQUIRED => true,
				ParamValidator::PARAM_TYPE => 'string'
			]
		];
	}

	/**
	 * @return array
	 */
	private function makeOptions() {
		return [
			'timeout' => 120,
			'sslVerifyHost' => 0,
			'followRedirects' => true,
			'sslVerifyCert' => false,
		];
	}

	/**
	 * @return string
	 */
	private function makeRootUrl() {
		return $this->config->get( 'DeeplTranslateServiceUrl' );
	}

	/**
	 * @return string|false
	 */
	private function extractSourceLanguage() {
		return explode( '-', $this->config->get( 'LanguageCode' ) )[0];
	}
}
