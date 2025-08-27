<?php

namespace BlueSpice\TranslationTransfer\Api;

use MediaWiki\Extension\VisualEditor\ApiParsoidTrait;
use MediaWiki\Extension\VisualEditor\ApiVisualEditor;
use MediaWiki\Title\Title;
use Wikimedia\ParamValidator\ParamValidator;

class ParsoidConversion extends ApiVisualEditor {
	use ApiParsoidTrait;

	/**
	 *
	 */
	public function execute() {
		$content = $this->getParameter( 'content' );
		$to = $this->getParameter( 'to' );
		if ( $to === 'html' ) {
			$converted = $this->transformWikitext(
				Title::newMainPage(),
				$content,
				true
			);
		} else {
			$converted = $this->transformHTML(
				Title::newMainPage(),
				$content
			);
		}

		$result = $this->getResult();
		$result->addValue( null, 'content', $converted );
	}

	/**
	 *
	 * @return array
	 */
	public function getAllowedParams() {
		return [
			'content' => [
				ParamValidator::PARAM_REQUIRED => true,
			],
			'to' => [
				ParamValidator::PARAM_REQUIRED => false,
				ParamValidator::PARAM_DEFAULT => 'html'
			]
		];
	}

	/**
	 *
	 * @return bool
	 */
	public function mustBePosted() {
		return true;
	}

}
