<?php

namespace BlueSpice\TranslationTransfer\Rest\Dictionary;

use BlueSpice\TranslationTransfer\Dictionary\TitleDictionary;
use BlueSpice\TranslationTransfer\IDictionary;
use Exception;
use MediaWiki\Rest\Handler;
use Wikimedia\ParamValidator\ParamValidator;

class DeleteDictionaryEntry extends Handler {

	/**
	 * @var IDictionary
	 */
	private $titleDictionary;

	public function __construct() {
		$this->titleDictionary = TitleDictionary::factory();
	}

	/**
	 * @inheritDoc
	 */
	public function execute() {
		$targetLang = $this->getValidatedParams()['targetLang'];

		$postData = $this->getValidatedBody()['data'];

		$sourcePrefixedDbKey = $postData['sourcePrefixedDbKey'];

		try {
			$this->titleDictionary->remove( $sourcePrefixedDbKey, $targetLang );
		} catch ( Exception $e ) {
			return $this->getResponseFactory()->createJson( [
				'success' => false,
				'error' => $e->getMessage()
			] );
		}

		return $this->getResponseFactory()->createJson( [
			'success' => true
		] );
	}

	/**
	 * @inheritDoc
	 */
	public function getBodyParamSettings(): array {
		return [
			'data' => [
				self::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_REQUIRED => true,
				ParamValidator::PARAM_TYPE => 'array'
			]
		];
	}

	/**
	 * @inheritDoc
	 */
	public function getParamSettings() {
		return [
			'targetLang' => [
				self::PARAM_SOURCE => 'path',
				ParamValidator::PARAM_REQUIRED => true,
				ParamValidator::PARAM_TYPE => 'string'
			]
		];
	}
}
