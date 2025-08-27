<?php

namespace BlueSpice\TranslationTransfer\Special;

use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\Message\Message;
use MediaWiki\SpecialPage\SpecialPage;

class TranslationsOverview extends SpecialPage {

	/**
	 * @var \MediaWiki\Config\ConfigFactory
	 */
	private $configFactory;

	public function __construct() {
		parent::__construct( 'TranslationsOverview', 'edit' );

		$this->configFactory = MediaWikiServices::getInstance()->getConfigFactory();
	}

	/**
	 * @inheritDoc
	 */
	public function execute( $action ) {
		parent::execute( $action );

		// If there are no translation targets configured - output warning
		// that there is some BlueSpiceTranslationTransfer configuration missing
		$bsgConfig = $this->configFactory->makeConfig( 'bsg' );
		$langTargetDataMap = $bsgConfig->get( 'TranslateTransferTargets' );
		if ( empty( $langTargetDataMap ) ) {
			$msg = Message::newFromKey( 'bs-translation-transfer-special-page-not-configured' )->text();
			$warningMessageBox = Html::warningBox( $msg );

			$this->getOutput()->addHTML( $warningMessageBox );

			return;
		}

		$this->getOutput()->enableOOUI();
		$this->getOutput()->addModules( 'ext.translate.transfer.overview' );

		$this->getOutput()->addHTML(
			Html::element( 'div', [ 'id' => 'translate-transfer-overview' ] )
		);
	}
}
