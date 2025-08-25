<?php

namespace BlueSpice\TranslationTransfer\Rest\Dictionary;

use BlueSpice\TranslationTransfer\Util\ContentTransfer\TargetRecognizer;
use ContentTransfer\AuthenticatedRequestHandlerFactory;
use ContentTransfer\Target;
use ContentTransfer\TargetManager;
use MediaWiki\Rest\Handler;
use MediaWiki\Title\TitleFactory;
use Wikimedia\ParamValidator\ParamValidator;

class GetAffectedPages extends Handler {

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
	}

	/**
	 * @inheritDoc
	 */
	public function execute() {
		$targetLang = $this->getValidatedParams()['targetLang'];
		$targetPrefixedDbKey = $this->getValidatedParams()['targetPrefixedDbKey'];

		$affectedPagesCount = 0;

		// If translation in the dictionary was successfully updated - move corresponding target page.
		// As a side effect - after target page move translation in translations table will be updated as well.
		$target = $this->getTargetFromLang( $targetLang );
		if ( $target ) {
			$requestHandler = $this->requestHandlerFactory->newFromTarget( $target );

			$args = [
				'action' => 'query',
				'titles' => $targetPrefixedDbKey,
				'prop' => 'linkshere',
				'format' => 'json',
				'formatversion' => 2
			];

			// Move target page
			$status = $requestHandler->runAuthenticatedRequest( $args );

			if ( $status->isOK() ) {
				// Count affected pages
				$pageProps = $status->getValue()['query']['pages'][0];
				if ( isset( $pageProps['linkshere'] ) ) {
					$affectedPagesCount = count( $pageProps['linkshere'] );
				}
			}

			// Also check pages which transclude target page
			$args = [
				'action' => 'query',
				'titles' => $targetPrefixedDbKey,
				'prop' => 'transcludedin',
				'format' => 'json',
				'formatversion' => 2
			];

			// Move target page
			$status = $requestHandler->runAuthenticatedRequest( $args );

			if ( $status->isOK() ) {
				// Count affected pages
				$pageProps = $status->getValue()['query']['pages'][0];
				if ( isset( $pageProps['transcludedin'] ) ) {
					$affectedPagesCount += count( $pageProps['transcludedin'] );
				}
			}
		}

		return $this->getResponseFactory()->createJson( [
			'success' => true,
			'affectedPagesCount' => $affectedPagesCount
		] );
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
	public function getParamSettings() {
		return [
			'targetLang' => [
				static::PARAM_SOURCE => 'path',
				ParamValidator::PARAM_REQUIRED => true,
				ParamValidator::PARAM_TYPE => 'string'
			],
			'targetPrefixedDbKey' => [
				static::PARAM_SOURCE => 'query',
				ParamValidator::PARAM_REQUIRED => true,
				ParamValidator::PARAM_TYPE => 'string'
			]
		];
	}
}
