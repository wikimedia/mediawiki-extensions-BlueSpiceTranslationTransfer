<?php

namespace BlueSpice\TranslationTransfer\Rest\Glossary;

use BlueSpice\TranslationTransfer\Util\GlossaryDao;
use MediaWiki\Rest\Handler;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\Rdbms\ILoadBalancer;

class DeleteGlossaryEntry extends Handler {

	/**
	 * @var GlossaryDao
	 */
	private $glossaryDao;

	/**
	 * @param ILoadBalancer $lb
	 */
	public function __construct( ILoadBalancer $lb ) {
		$this->glossaryDao = new GlossaryDao( $lb );
	}

	/**
	 * @inheritDoc
	 */
	public function execute() {
		$targetLang = $this->getValidatedParams()['targetLang'];

		$postData = $this->getValidatedBody()['data'];
		$sourceText = $postData['sourceText'];

		$this->glossaryDao->removeEntry( $targetLang, $sourceText );

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
