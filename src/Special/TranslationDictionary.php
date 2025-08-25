<?php

namespace BlueSpice\TranslationTransfer\Special;

use MediaWiki\Html\Html;
use MediaWiki\Message\Message;
use MediaWiki\SpecialPage\SpecialPage;

class TranslationDictionary extends SpecialPage {

	public function __construct() {
		parent::__construct( 'TranslationDictionary', 'edit' );
	}

	/**
	 * @inheritDoc
	 */
	public function execute( $action ) {
		parent::execute( $action );

		$this->getOutput()->enableOOUI();
		$this->getOutput()->addModules( 'ext.translate.transfer.dictionary' );

		$this->getOutput()->addHTML(
			Html::element( 'p',
				[
					'class' => 'translate-transfer-dictionary-desc'
				],
				Message::newFromKey( 'bs-translation-transfer-dictionary-desc' )->text()
			),
		);

		$this->getOutput()->addHTML(
			$this->getDictionaryFilterHtml()
		);

		$this->getOutput()->addHTML(
			$this->getLanguageSwitchHtml()
		);

		$this->getOutput()->addHTML(
			Html::element( 'div', [ 'id' => 'translate-transfer-dictionary-grid' ] )
		);
	}

	/**
	 * @return string
	 */
	private function getDictionaryFilterHtml(): string {
		$dictionaryFilterHtml = Html::openElement( 'div', [
			'class' => 'translate-transfer-dictionary-filter'
		] );

		$dictionaryFilterHtml .= Html::element( 'span',
			[],
			Message::newFromKey( 'bs-translation-transfer-dictionary-filter-label' )->text()
		);

		$dictionaryFilterHtml .= Html::element( 'input', [
			'id' => 'translate-transfer-dictionary-filter-input',
			'type' => 'text',
			'placeholder' => Message::newFromKey( 'bs-translation-transfer-dictionary-filter-placeholder' )->text()
		] );

		$dictionaryFilterHtml .= Html::closeElement( 'div' );

		return $dictionaryFilterHtml;
	}

	/**
	 * @return string
	 */
	private function getLanguageSwitchHtml(): string {
		$languageSwitchHtml = Html::openElement( 'div', [
			'class' => 'translate-transfer-dictionary-language-switch-wrapper'
		] );

		$languageSwitchHtml .= Html::element( 'p',
			[],
			Message::newFromKey( 'bs-translation-transfer-dictionary-switch-main-language' )->text()
		);

		$languageSwitchHtml .= Html::openElement( 'div', [
			'class' => 'translate-transfer-dictionary-language-switch'
		] );

		$languageSwitchHtml .= Html::element( 'p',
			[],
			Message::newFromKey( 'bs-translation-transfer-dictionary-switch-select-language' )->text()
		);

		// .translate-transfer-dictionary-language-switch
		$languageSwitchHtml .= Html::closeElement( 'div' );

		// .translate-transfer-dictionary-language-switch-wrapper
		$languageSwitchHtml .= Html::closeElement( 'div' );

		return $languageSwitchHtml;
	}
}
