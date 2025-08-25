<?php

namespace BlueSpice\TranslationTransfer\Rest;

use BlueSpice\TranslationTransfer\Util\ContentTransfer\TargetRecognizer;
use BlueSpice\TranslationTransfer\Util\TranslationsDao;
use MediaWiki\Context\RequestContext;
use MediaWiki\Rest\Handler;
use MediaWiki\Title\Title;
use MediaWiki\User\UserGroupManager;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\Rdbms\ILoadBalancer;

/**
 * Marks some specific translation (identified by "target" prefixed title and "target" language)
 * as "acknowledged".
 * That means that "outdated translation" banner will not show for that specific translation.
 */
class AckTranslation extends Handler {

	/**
	 * @var ILoadBalancer
	 */
	private $lb;

	/**
	 * @var UserGroupManager
	 */
	private $userGroupManager;

	/**
	 * @var TargetRecognizer
	 */
	private $targetRecognizer;

	/**
	 * @param ILoadBalancer $lb
	 * @param UserGroupManager $userGroupManager
	 * @param TargetRecognizer $targetRecognizer
	 */
	public function __construct(
		ILoadBalancer $lb,
		UserGroupManager $userGroupManager,
		TargetRecognizer $targetRecognizer
	) {
		$this->lb = $lb;
		$this->userGroupManager = $userGroupManager;
		$this->targetRecognizer = $targetRecognizer;
	}

	/**
	 * @inheritDoc
	 */
	public function execute() {
		$error = '';

		$currentLang = $this->targetRecognizer->recognizeCurrentTarget()['lang'];
		if ( !$currentLang ) {
			$error = 'No context language available';
		}

		// This code is always executed from the frontend
		// So we can get information about user from session
		$user = RequestContext::getMain()->getUser();
		if ( !$user ) {
			$error = 'No context user available';
		}

		$groups = $this->userGroupManager->getUserGroups( $user );

		// This action is allowed only for "sysop" users
		if ( !in_array( 'sysop', $groups ) ) {
			$error = 'User is not "sysop"';
		}

		if ( $error ) {
			return $this->getResponseFactory()->createJson( [
				'success' => false,
				'error' => $error
			] );
		}

		$targetTitleId = $this->getValidatedParams()['targetTitleId'];
		$targetTitle = Title::newFromID( $targetTitleId );

		$dao = new TranslationsDao( $this->lb );
		$dao->ackTranslation( $targetTitle->getPrefixedDBkey(), $currentLang );

		return $this->getResponseFactory()->createJson( [
			'success' => true
		] );
	}

	/**
	 * @inheritDoc
	 */
	public function getParamSettings() {
		return [
			'targetTitleId' => [
				static::PARAM_SOURCE => 'path',
				ParamValidator::PARAM_REQUIRED => true,
				ParamValidator::PARAM_TYPE => 'string'
			]
		];
	}
}
