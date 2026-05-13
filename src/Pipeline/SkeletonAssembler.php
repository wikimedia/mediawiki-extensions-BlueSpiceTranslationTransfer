<?php

namespace BlueSpice\TranslationTransfer\Pipeline;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Replaces PUA markers in the skeleton with translated segment text.
 *
 * This is the inverse of WikitextSegmenter: given a skeleton (with PUA markers)
 * and a list of segments (with translated text), produces the final translated wikitext.
 *
 * Safety checks:
 * - Falls back to source text if a segment has no translation set
 * - Detects and rejects translated text that itself contains PUA characters
 *   (which would corrupt the skeleton structure)
 * - Logs errors if a marker is missing from the skeleton (should never happen
 *   unless the skeleton was corrupted)
 */
class SkeletonAssembler implements LoggerAwareInterface {

	/** @var LoggerInterface */
	private $logger;

	public function __construct() {
		$this->logger = new NullLogger();
	}

	/**
	 * @param LoggerInterface $logger
	 */
	public function setLogger( LoggerInterface $logger ): void {
		$this->logger = $logger;
	}

	/**
	 * Assemble the final translated wikitext by replacing markers with translated text.
	 *
	 * @param string $skeleton
	 * @param Segment[] $segments
	 * @return string
	 */
	public function assemble( string $skeleton, array $segments ): string {
		$result = $skeleton;

		foreach ( $segments as $segment ) {
			$marker = $segment->getMarker();
			$translatedText = $segment->getTranslatedText();

			if ( $translatedText === null ) {
				// Not translated — use source text as fallback
				$translatedText = $segment->getSourceText();
			}

			// Defensive: check for PUA characters in translated text
			if ( $this->containsPuaChars( $translatedText ) ) {
				$this->logger->error(
					'Translated text for segment {id} contains PUA characters, falling back to source',
					[ 'id' => $segment->getId() ]
				);
				$translatedText = $segment->getSourceText();
			}

			if ( strpos( $result, $marker ) === false ) {
				$this->logger->error(
					'Marker for segment {id} not found in skeleton',
					[ 'id' => $segment->getId(), 'marker' => $marker ]
				);
				continue;
			}

			$result = str_replace( $marker, $translatedText, $result );
		}

		return $result;
	}

	/**
	 * Check if text contains Private Use Area characters.
	 *
	 * @param string $text
	 * @return bool
	 */
	private function containsPuaChars( string $text ): bool {
		return preg_match( '/[\x{E000}-\x{F8FF}]/u', $text ) === 1;
	}
}
