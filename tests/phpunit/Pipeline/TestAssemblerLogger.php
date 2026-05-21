<?php

namespace BlueSpice\TranslationTransfer\Tests\Pipeline;

use Psr\Log\AbstractLogger;

/**
 * Simple in-memory logger for testing.
 */
class TestAssemblerLogger extends AbstractLogger {

	/** @var array */
	public $records = [];

	/** @inheritDoc */
	public function log( $level, $message, array $context = [] ): void {
		$this->records[] = [ 'level' => $level, 'message' => $message, 'context' => $context ];
	}

	/**
	 * @return bool
	 */
	public function hasErrorRecords(): bool {
		foreach ( $this->records as $record ) {
			if ( $record['level'] === 'error' ) {
				return true;
			}
		}
		return false;
	}
}
