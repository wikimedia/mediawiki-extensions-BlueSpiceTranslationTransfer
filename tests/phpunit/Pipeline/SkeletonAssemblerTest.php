<?php

namespace BlueSpice\TranslationTransfer\Tests\Pipeline;

use BlueSpice\TranslationTransfer\Pipeline\Segment;
use BlueSpice\TranslationTransfer\Pipeline\SkeletonAssembler;
use PHPUnit\Framework\TestCase;

/**
 * @covers \BlueSpice\TranslationTransfer\Pipeline\SkeletonAssembler
 */
class SkeletonAssemblerTest extends TestCase {

	/** @var SkeletonAssembler */
	private $assembler;

	/** @var TestAssemblerLogger */
	private $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->assembler = new SkeletonAssembler();
		$this->logger = new TestAssemblerLogger();
		$this->assembler->setLogger( $this->logger );
	}

	public function testBasicAssembly(): void {
		$segment0 = new Segment( 0, Segment::TYPE_HEADING, 'Introduction', 2 );
		$segment0->setTranslatedText( 'Einleitung' );

		$segment1 = new Segment( 1, Segment::TYPE_PARAGRAPH, 'Hello world.' );
		$segment1->setTranslatedText( 'Hallo Welt.' );

		$skeleton = "== {$segment0->getMarker()} ==\n\n{$segment1->getMarker()}";

		$result = $this->assembler->assemble( $skeleton, [ $segment0, $segment1 ] );
		$this->assertSame( "== Einleitung ==\n\nHallo Welt.", $result );
	}

	public function testFallsBackToSourceIfNotTranslated(): void {
		$segment = new Segment( 0, Segment::TYPE_PARAGRAPH, 'Original text.' );
		// translatedText is null

		$skeleton = $segment->getMarker();
		$result = $this->assembler->assemble( $skeleton, [ $segment ] );

		$this->assertSame( 'Original text.', $result );
	}

	public function testPuaCharsInTranslationTriggersFallback(): void {
		$segment = new Segment( 0, Segment::TYPE_PARAGRAPH, 'Original text.' );
		$segment->setTranslatedText( "Translated \u{E000}garbage\u{E001} text." );

		$skeleton = $segment->getMarker();
		$result = $this->assembler->assemble( $skeleton, [ $segment ] );

		$this->assertSame( 'Original text.', $result );
		$this->assertTrue( $this->logger->hasErrorRecords() );
	}

	public function testMissingMarkerLogsError(): void {
		$segment = new Segment( 99, Segment::TYPE_PARAGRAPH, 'Orphan' );
		$segment->setTranslatedText( 'Waise' );

		$skeleton = 'This skeleton has no markers.';
		$result = $this->assembler->assemble( $skeleton, [ $segment ] );

		$this->assertSame( 'This skeleton has no markers.', $result );
		$this->assertTrue( $this->logger->hasErrorRecords() );
	}
}
