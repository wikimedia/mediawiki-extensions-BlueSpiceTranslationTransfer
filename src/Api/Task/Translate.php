<?php

namespace BlueSpice\TranslationTransfer\Api\Task;

use BlueSpice\Api\Response\Standard;
use BlueSpice\TranslationTransfer\Translator;
use BSApiTasksBase;
use Exception;
use MediaWiki\Api\ApiMain;
use MediaWiki\Api\ApiResult;
use MediaWiki\Request\DerivativeRequest;
use MediaWiki\Status\Status;
use stdClass;

class Translate extends BSApiTasksBase {

	/**
	 * Methods that can be called by task param
	 * @var array
	 */
	protected $aTasks = [
		'translate',
	];

	/**
	 *
	 * @return array
	 */
	protected function getRequiredTaskPermissions() {
		return [
			'translate' => [ 'edit' ],
		];
	}

	/**
	 *
	 * @param stdClass $taskData
	 * @param array $params
	 * @return Standard
	 */
	public function task_translate( $taskData, $params ) { // phpcs:ignore MediaWiki.NamingConventions.LowerCamelFunctionsName.FunctionName
		$targetLang = empty( $taskData->lang ) ? '' : $taskData->lang;
		$result = $this->makeStandardReturn();
		$this->checkPermissions();

		/** @var Translator $translator */
		$translator = $this->services->getService( 'TranslationsTransferTranslator' );
		$hookContainer = $this->services->getHookContainer();

		$status = Status::newGood();

		try {
			$translationRes = $translator->translateTitle( $this->getContext()->getTitle(), $targetLang, false );
		} catch ( Exception $e ) {
			$result->message = $e->getMessage();
			$result->success = false;

			$status->fatal( $e->getMessage() );
		} finally {
			$hookContainer->run( 'BlueSpiceTranslationTransferTranslationComplete', [
				$this->getContext()->getTitle(),
				$targetLang,
				$status
			] );
		}

		if ( !$status->isOK() ) {
			return $result;
		}

		$result->success = true;

		// Get HTML of final version of wikitext
		// We do not catch exception here because it should work if translation was completed successfully
		// As soon as "wikitext => HTML" transformation is done during translation
		$html = $this->getHTML( $translationRes['wikitext'] );

		$result->payload[$targetLang] = [
			'title' => $translationRes['title'],
			'html' => $html,
			'wikitext' => $translationRes['wikitext'],
			'dictionaryUsed' => $translationRes['dictionaryUsed'],
		];

		return $result;
	}

	/**
	 * @param string $wikitext
	 * @return string|null
	 * @throws Exception
	 */
	private function getHTML( $wikitext ) {
		$result = $this->executeSubAPI( $wikitext, 'html' );
		$data = $result->getResultData();
		return isset( $data['content']['body'] ) ? $data['content']['body'] : null;
	}

	/**
	 * @param string $content
	 * @param string $to
	 * @return ApiResult|null
	 */
	private function executeSubAPI( $content, $to ) {
		$request = new DerivativeRequest( $this->getRequest(), [
			'action' => 'bs-translation-transfer-convert',
			'content' => $content,
			'to' => $to,
			'token' => $this->getUser()->getEditToken(),
		], true );

		try {
			$api = new ApiMain( $request );
			$api->execute();
			return $api->getResult();
		} catch ( Exception $ex ) {
			error_log( $ex->getMessage() );
			return null;
		}
	}

	/**
	 * @return bool
	 */
	public function isWriteMode() {
		return false;
	}
}
