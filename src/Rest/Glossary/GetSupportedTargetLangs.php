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
 * Uses the v3 API: GET /v3/languages?resource=glossary
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
			$languages = $this->getGlossaryLanguages();
		} catch ( Exception $e ) {
			return $this->getResponseFactory()->createJson( [
				'success' => false,
				'error' => $e->getMessage()
			] );
		}

		$supportedTargetLangs = [];

		$sourceLang = $this->extractSourceLanguage();
		foreach ( $languages as $language ) {
			$langCode = strtolower( $language['lang'] ?? '' );
			$isTarget = $language['usable_as_target'] ?? false;

			// Include languages that can be glossary targets, excluding the source language
			if ( $isTarget && $langCode !== $sourceLang ) {
				$supportedTargetLangs[] = $langCode;
			}
		}

		return $this->getResponseFactory()->createJson( [
			'success' => true,
			'supported_target_langs' => $supportedTargetLangs
		] );
	}

	/**
	 * Fetch glossary-capable languages from DeepL v3 API.
	 *
	 * @return array
	 * @throws Exception
	 */
	private function getGlossaryLanguages(): array {
		$data = array_merge(
			$this->makeOptions(),
			[
				'method' => 'get'
			]
		);

		$url = $this->makeBaseUrl() . '/v3/languages?resource=glossary';

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
			throw new Exception( 'Failed to get glossary languages: bad status' );
		}

		$responseRaw = $req->getContent();
		if ( $responseRaw ) {
			$response = json_decode( $responseRaw, true );

			if ( is_array( $response ) ) {
				return $response;
			}
		}

		throw new Exception( 'Failed to get glossary languages: bad response' );
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
	 * Get the DeepL API base URL (without version path).
	 *
	 * @return string
	 */
	private function makeBaseUrl(): string {
		$url = $this->config->get( 'DeeplTranslateServiceUrl' );
		$url = rtrim( $url, '/' );
		// B/C: strip trailing version path if present (e.g. /v2)
		$url = preg_replace( '#/v\d+$#', '', $url );

		return $url;
	}

	/**
	 * @return string
	 */
	private function extractSourceLanguage(): string {
		return explode( '-', $this->config->get( 'LanguageCode' ) )[0];
	}
}
