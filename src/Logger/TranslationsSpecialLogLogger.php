<?php

namespace BlueSpice\TranslationTransfer\Logger;

use Exception;
use ManualLogEntry;
use MediaWiki\Title\Title;
use MediaWiki\User\User;

class TranslationsSpecialLogLogger {

	/**
	 * Add a "Special:Log" log entry about the translation of specific wiki page
	 *
	 * @param Title $target
	 * @param User $actor
	 * @param string $targetLang
	 *
	 * @throws Exception
	 */
	public function addEntry( Title $target, User $actor, string $targetLang ) {
		$logEntry = new ManualLogEntry( 'bs-translation-transfer', 'translate' );

		$logEntry->setPerformer( $actor );
		$logEntry->setTarget( $target );

		$params = [
			'4::lang' => strtoupper( $targetLang )
		];

		$logEntry->setParameters( $params );

		$logId = $logEntry->insert();

		$logEntry->publish( $logId );
	}
}
