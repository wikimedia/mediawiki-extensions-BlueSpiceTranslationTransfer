<?php

namespace BlueSpice\TranslationTransfer\Rest\Dictionary;

use BlueSpice\TranslationTransfer\Dictionary\TitleDictionary;
use BlueSpice\TranslationTransfer\IDictionary;
use BlueSpice\TranslationTransfer\Util\ContentTransfer\TargetRecognizer;
use ContentTransfer\AuthenticatedRequestHandlerFactory;
use ContentTransfer\Target;
use ContentTransfer\TargetManager;
use Exception;
use MediaWiki\Rest\Handler;
use MediaWiki\Title\TitleFactory;
use Wikimedia\ParamValidator\ParamValidator;

class ChangeDictionaryEntry extends Handler {

	/**
	 * @var TitleFactory
	 */
	private $titleFactory;

	/**
	 * @var AuthenticatedRequestHandlerFactory
	 */
	private $requestHandlerFactory;

	/**
	 * @var TargetManager
	 */
	private $targetManager;

	/**
	 * @var TargetRecognizer
	 */
	private $targetRecognizer;

	/**
	 * @var IDictionary
	 */
	private $titleDictionary;

	/**
	 * @param TitleFactory $titleFactory
	 * @param AuthenticatedRequestHandlerFactory $authenticatedRequestHandlerFactory
	 * @param TargetManager $targetManager
	 * @param TargetRecognizer $targetRecognizer
	 */
	public function __construct(
		TitleFactory $titleFactory,
		AuthenticatedRequestHandlerFactory $authenticatedRequestHandlerFactory,
		TargetManager $targetManager,
		TargetRecognizer $targetRecognizer
	) {
		$this->titleFactory = $titleFactory;
		$this->requestHandlerFactory = $authenticatedRequestHandlerFactory;
		$this->targetManager = $targetManager;
		$this->targetRecognizer = $targetRecognizer;

		$this->titleDictionary = TitleDictionary::factory();
	}

	/**
	 * @inheritDoc
	 */
	public function execute() {
		$targetLang = $this->getValidatedParams()['targetLang'];

		$postData = $this->getValidatedBody()['data'];

		$sourcePrefixedDbKey = $postData['sourcePrefixedDbKey'];
		$targetPrefixedDbKey = $postData['targetPrefixedDbKey'];
		$newTranslationText = $postData['newTranslationText'];

		try {
			$this->titleDictionary->update( $sourcePrefixedDbKey, $targetLang, $newTranslationText );
		} catch ( Exception $e ) {
			return $this->getResponseFactory()->createJson( [
				'success' => false,
				'error' => $e->getMessage()
			] );
		}

		$newTargetPrefixedDbKey = $this->composeNewTargetPrefixedKey( $targetPrefixedDbKey, $newTranslationText );

		// If translation in the dictionary was successfully updated - move corresponding target page.
		// As a side effect - after target page move translation in translations table will be updated as well.
		$target = $this->getTargetFromLang( $targetLang );
		if ( $target ) {
			$requestHandler = $this->requestHandlerFactory->newFromTarget( $target );

			$args = [
				'action' => 'move',
				'from' => $targetPrefixedDbKey,
				'to' => $newTargetPrefixedDbKey,
				'token' => $requestHandler->getCSRFToken(),
				'reason' => "Translation entry in the dictionary was updated",
				'format' => 'json'
			];

			// Move target page
			$requestHandler->runAuthenticatedRequest( $args );
		}

		return $this->getResponseFactory()->createJson( [
			'success' => true
		] );
	}

	/**
	 * @param string $targetPrefixedDbKey
	 * @param string $newTranslationText
	 * @return string
	 */
	private function composeNewTargetPrefixedKey( string $targetPrefixedDbKey, string $newTranslationText ): string {
		$newTargetDbKey = $this->titleFactory->newFromText( $newTranslationText )->getDBkey();

		if ( strpos( $targetPrefixedDbKey, ':' ) !== false ) {
			$bits = explode( ':', $targetPrefixedDbKey, 2 );
			$bits[1] = $newTargetDbKey;

			$newTargetPrefixedDbKey = implode( ':', $bits );
		} else {
			$newTargetPrefixedDbKey = $newTargetDbKey;
		}

		return $newTargetPrefixedDbKey;
	}

	/**
	 * @param string $lang
	 * @return Target|null
	 */
	private function getTargetFromLang( string $lang ): ?Target {
		$langToTargetMap = $this->targetRecognizer->getLangToTargetKeyMap();

		return $this->targetManager->getTarget( $langToTargetMap[$lang] );
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
