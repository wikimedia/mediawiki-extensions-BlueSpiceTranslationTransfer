<?php

namespace BlueSpice\TranslationTransfer\Rest\Glossary;

use Exception;
use GlobalVarConfig;
use MediaWiki\Config\Config;
use MediaWiki\Config\ConfigFactory;
use MediaWiki\Config\MultiConfig;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Rest\Handler;

/**
 * Gets list of supported by DeepL glossary language pairs.
 *
 * https://developers.deepl.com/docs/api-reference/glossaries#list-supported-glossary-language-pairs
 */
class GetSupportedTargetLangs extends Handler {

	/**
	 * @var Config
	 */
	private $config;

	/**
	 * @var HttpRequestFactory
	 */
	private $requestFactory;

	/**
	 * @param ConfigFactory $configFactory
	 * @param HttpRequestFactory $requestFactory
	 */
	public function __construct(
		ConfigFactory $configFactory,
		HttpRequestFactory $requestFactory
	) {
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
		try {
			$languagePairs = $this->getLanguagePairs();
		} catch ( Exception $e ) {
			return $this->getResponseFactory()->createJson( [
				'success' => false,
				'error' => $e->getMessage()
			] );
		}

		$supportedTargetLangs = [];

		$sourceLang = $this->extractSourceLanguage();
		foreach ( $languagePairs as $languagePair ) {
			if ( $languagePair['source_lang'] === $sourceLang ) {
				$supportedTargetLangs[] = $languagePair['target_lang'];
			}
		}

		return $this->getResponseFactory()->createJson( [
			'success' => true,
			'supported_target_langs' => $supportedTargetLangs
		] );
	}

	/**
	 * @return array
	 * @throws Exception
	 */
	private function getLanguagePairs(): array {
		// Glossary for that language exists in DeepL, so remove it
		$data = array_merge(
			$this->makeOptions(),
			[
				'method' => 'get'
			]
		);

		$url = $this->makeRootUrl() . '/glossary-language-pairs';

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
			throw new Exception( 'Failed to get glossary language pairs: bad status' );
		}

		$responseRaw = $req->getContent();
		if ( $responseRaw ) {
			$response = json_decode( $responseRaw, true );

			return $response['supported_languages'];
		} else {
			throw new Exception( 'Failed to get glossary language pairs: bad response' );
		}
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
