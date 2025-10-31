<?php

namespace BlueSpice\TranslationTransfer\Api\Task;

use BlueSpice\Api\Response\Standard;
use BlueSpice\TranslationTransfer\Logger\TranslationsSpecialLogLogger;
use BlueSpice\TranslationTransfer\Util\ContentTransfer\TargetRecognizer;
use BlueSpice\TranslationTransfer\Util\TranslationPusher;
use BSApiTasksBase;
use ContentTransfer\AuthenticatedRequestHandlerFactory;
use ContentTransfer\Target;
use ContentTransfer\TargetManager;
use Exception;
use MediaWiki\Json\FormatJson;
use MediaWiki\MediaWikiServices;
use MediaWiki\Message\Message;
use stdClass;

class ForeignPage extends BSApiTasksBase {

	/**
	 * Methods that can be called by task param
	 *
	 * @var array
	 */
	protected $aTasks = [
		'getPageInfo',
		'push'
	];

	/**
	 * @param stdClass $taskData
	 * @param array $params
	 *
	 * @return Standard
	 */
	public function task_getPageInfo( $taskData, $params ) { // phpcs:ignore MediaWiki.NamingConventions.LowerCamelFunctionsName.FunctionName
		$title = $taskData->title;
		$target = FormatJson::decode( $taskData->target, 1 );
		$target = $this->getFullTarget( $target );
		$result = $this->makeStandardReturn();
		if ( !$target ) {
			$result->message = Message::newFromKey( 'push-invalid-target' )->plain();

			return $result;
		}
		/** @var AuthenticatedRequestHandlerFactory $authnReqHandlerFactory */
		$authnReqHandlerFactory = MediaWikiServices::getInstance()->getService(
			'ContentTransferAuthenticatedRequestHandlerFactory'
		);
		$requestHandler = $authnReqHandlerFactory->newFromTarget( $target, $this->isInsecure() );
		$props = $requestHandler->getPageProps( $title );

		$result->success = true;
		$result->payload = (object)$props;

		return $result;
	}

	/**
	 * @param array $pushTarget
	 * @return Target|null
	 */
	private function getFullTarget( $pushTarget ) {
		/** @var TargetManager $targetManager */
		$targetManager = MediaWikiServices::getInstance()->getService(
			'ContentTransferTargetManager'
		);

		return $targetManager->getTarget( $pushTarget['id'] );
	}

	/**
	 * @return bool
	 */
	private function isInsecure() {
		return MediaWikiServices::getInstance()->getMainConfig()->get(
			'ContentTransferIgnoreInsecureSSL'
		);
	}

	/**
	 * @param stdClass $taskData
	 * @param array $params
	 *
	 * @return Standard
	 * @throws Exception
	 */
	public function task_push( $taskData, $params ) { // phpcs:ignore MediaWiki.NamingConventions.LowerCamelFunctionsName.FunctionName
		/** @var TranslationPusher $translationPusher */
		$translationPusher = MediaWikiServices::getInstance()->getService(
			'TranslationTransferTranslationPusher'
		);
		$hookContainer = MediaWikiServices::getInstance()->getHookContainer();
		$titleFactory = MediaWikiServices::getInstance()->getTitleFactory();

		$targetTitlePrefixedText = $taskData->targetTitlePrefixedText;

		// Change "prefixed text" to "prefixed DB key",
		// because further we operate with "prefixed DB key".

		// If source title should be pushed to the "Draft" namespace, then we may get smth like that:
		// "Draft:Some_NS:SourceTitleA"
		// Cover such cases.
		$bits = explode( ':', $targetTitlePrefixedText );
		if ( count( $bits ) === 1 ) {
			$targetNsText = '';
			$targetTitleText = $bits[0];
		} elseif ( count( $bits ) === 2 ) {
			$targetNsText = $bits[0];
			$targetTitleText = $bits[1];
		} else {
			$targetNsText = $bits[0] . ':' . $bits[1];

			$targetTitleText = implode( ':', array_slice( $bits, 2 ) );
		}

		$targetTitleObj = $titleFactory->newFromText( $targetTitleText );

		$targetTitleDbKey = $targetTitleObj->getDBKey();
		if ( $targetNsText !== '' ) {
			$targetTitlePrefixedKey = $targetNsText . ':' . $targetTitleDbKey;
		} else {
			$targetTitlePrefixedKey = $targetTitleDbKey;
		}

		$content = $taskData->content;

		$target = FormatJson::decode( $taskData->target, 1 );

		$target = $this->getFullTarget( $target );
		$result = $this->makeStandardReturn();
		if ( !$target ) {
			$result->message = Message::newFromKey( 'push-invalid-target' )->plain();

			return $result;
		}

		$status = $translationPusher->push(
			$content,
			$targetTitlePrefixedKey,
			$this->getTitle(),
			$target
		);

		if ( $status->isOK() ) {
			$this->addSpecialLogEntry( $target );
		}

		/** @var TargetRecognizer $targetRecognizer */
		$targetRecognizer = MediaWikiServices::getInstance()->getService(
			'TranslationTransferTargetRecognizer'
		);
		$langTargetKeyMap = $targetRecognizer->getLangToTargetKeyMap();
		$targetKey = $targetRecognizer->getTargetKeyFromTargetUrl( $target->getUrl() );

		$targetLang = array_search( $targetKey, $langTargetKeyMap );

		$hookContainer->run( 'BlueSpiceTranslationTransferPagePushComplete', [
			$this->getTitle(),
			$targetTitlePrefixedKey,
			$targetLang,
			$status
		] );

		$result->payload = [
			'targetTitleHref' => $targetRecognizer->composeTargetTitleLink(
				$targetLang, $targetTitlePrefixedKey
			)
		];
		$result->success = $status->isOK();
		$result->errors = $status->isOK() ? [] : $status->getErrors();

		return $result;
	}

	/**
	 * @param Target $target
	 *
	 * @return void
	 * @throws Exception
	 */
	private function addSpecialLogEntry( Target $target ): void {
		/** @var TargetRecognizer $targetRecognizer */
		$targetRecognizer = MediaWikiServices::getInstance()->getService(
			'TranslationTransferTargetRecognizer'
		);

		$langWikiMap = $targetRecognizer->getLangToTargetKeyMap();
		$targetKey = $targetRecognizer->getTargetKeyFromTargetUrl( $target->getUrl() );

		$targetLang = array_search( $targetKey, $langWikiMap );

		/** @var TranslationsSpecialLogLogger $specialLogLogger */
		$specialLogLogger = MediaWikiServices::getInstance()->getService( 'TranslationsTransferSpecialLogLogger' );
		$specialLogLogger->addEntry( $this->getTitle(), $this->getUser(), $targetLang );
	}

	/**
	 *
	 * @return array
	 */
	protected function getRequiredTaskPermissions() {
		return [
			'getPageInfo' => [ 'read' ],
			'push' => [ 'edit' ],
		];
	}
}
