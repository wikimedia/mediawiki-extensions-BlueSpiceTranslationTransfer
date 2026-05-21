<?php

namespace BlueSpice\TranslationTransfer\Tests\Pipeline;

use BlueSpice\TranslationTransfer\Pipeline\InlineConverter;
use BlueSpice\TranslationTransfer\Pipeline\Segment;
use BlueSpice\TranslationTransfer\Pipeline\SegmentTranslator;
use MediaWiki\Config\HashConfig;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Json\FormatJson;
use MWHttpRequest;
use PHPUnit\Framework\TestCase;
use StatusValue;

/**
 * @covers \BlueSpice\TranslationTransfer\Pipeline\SegmentTranslator
 */
class SegmentTranslatorTest extends TestCase {

	/**
	 * @return void
	 */
	public function testTranslateBatchCallsDeepLWithCorrectFormat(): void {
		$segments = [
			new Segment( 0, Segment::TYPE_PARAGRAPH, 'Hello world.' ),
			new Segment( 1, Segment::TYPE_HEADING, 'Introduction', 2 ),
		];

		$translator = $this->makeTranslator(
			[ 'Hallo Welt.', 'Einleitung' ]
		);

		$translator->translateBatch( $segments, 'en', 'de' );

		$this->assertSame( 'Hallo Welt.', $segments[0]->getTranslatedText() );
		$this->assertSame( 'Einleitung', $segments[1]->getTranslatedText() );
	}

	/**
	 * @return void
	 */
	public function testEmptySegmentsSkipped(): void {
		$segment = new Segment( 0, Segment::TYPE_PARAGRAPH, '   ' );
		$translator = $this->makeTranslator( [] );

		$translator->translateBatch( [ $segment ], 'en', 'de' );

		// Whitespace-only segment should get source text as translation (not translatable)
		$this->assertSame( '   ', $segment->getTranslatedText() );
	}

	/**
	 * @return void
	 */
	public function testDeepLFailureFallsBackToSourceText(): void {
		$segment = new Segment( 0, Segment::TYPE_PARAGRAPH, 'Original text.' );

		$translator = $this->makeTranslator( null, true );

		$translator->translateBatch( [ $segment ], 'en', 'de' );

		$this->assertSame( 'Original text.', $segment->getTranslatedText() );
	}

	/**
	 * @return void
	 */
	public function testMissingTranslationInResponseFallsBack(): void {
		// DeepL returns fewer translations than expected
		$segment0 = new Segment( 0, Segment::TYPE_PARAGRAPH, 'First.' );
		$segment1 = new Segment( 1, Segment::TYPE_PARAGRAPH, 'Second.' );

		$translator = $this->makeTranslator( [ 'Erste.' ] );

		$translator->translateBatch( [ $segment0, $segment1 ], 'en', 'de' );

		$this->assertSame( 'Erste.', $segment0->getTranslatedText() );
		$this->assertSame( 'Second.', $segment1->getTranslatedText() );
	}

	/**
	 * @return void
	 */
	public function testGlossaryIdIncludedInRequest(): void {
		$segment = new Segment( 0, Segment::TYPE_PARAGRAPH, 'Term.' );

		$capturedBody = null;
		$translator = $this->makeTranslator(
			[ 'Begriff.' ],
			false,
			static function ( string $body ) use ( &$capturedBody ) {
				$capturedBody = $body;
			}
		);
		$translator->setGlossaryId( 'glossary-abc-123' );

		$translator->translateBatch( [ $segment ], 'en', 'de' );

		$this->assertNotNull( $capturedBody );
		$decoded = FormatJson::decode( $capturedBody, true );
		$this->assertSame( 'glossary-abc-123', $decoded['glossary_id'] );
	}

	/**
	 * @return void
	 */
	public function testRequestBodyIsJsonEncoded(): void {
		$segment = new Segment( 0, Segment::TYPE_PARAGRAPH, 'Hello.' );

		$capturedBody = null;
		$translator = $this->makeTranslator(
			[ 'Hallo.' ],
			false,
			static function ( string $body ) use ( &$capturedBody ) {
				$capturedBody = $body;
			}
		);

		$translator->translateBatch( [ $segment ], 'en', 'de' );

		$decoded = FormatJson::decode( $capturedBody, true );
		$this->assertIsArray( $decoded );
		$this->assertSame( 'html', $decoded['tag_handling'] );
		$this->assertSame( 'EN', $decoded['source_lang'] );
		$this->assertSame( 'DE', $decoded['target_lang'] );
		$this->assertIsArray( $decoded['text'] );
	}

	/**
	 * @return void
	 */
	public function testBatchSplitByCount(): void {
		$segments = [];
		for ( $i = 0; $i < 55; $i++ ) {
			$segments[] = new Segment( $i, Segment::TYPE_PARAGRAPH, "Text $i." );
		}

		$callCount = 0;
		$translator = $this->makeTranslator(
			null,
			false,
			null,
			static function ( $texts ) use ( &$callCount ) {
				$callCount++;
				return array_map( static function ( $t ) {
					return strtoupper( $t );
				}, $texts );
			}
		);

		$translator->translateBatch( $segments, 'en', 'de' );

		// 55 segments with MAX_BATCH_SIZE=50 should produce 2 batches
		$this->assertSame( 2, $callCount );
		$this->assertSame( 'TEXT 0.', $segments[0]->getTranslatedText() );
		$this->assertSame( 'TEXT 54.', $segments[54]->getTranslatedText() );
	}

	/**
	 * @return void
	 */
	public function testOpaqueOnlySegmentFallsBackToSource(): void {
		// A segment that is entirely a template — after opaque extraction,
		// strip_tags(html) is empty, so it should fall back to source.
		$segment = new Segment( 0, Segment::TYPE_PARAGRAPH, '{{TemplateOnly}}' );

		$translator = $this->makeTranslator( [] );

		$translator->translateBatch( [ $segment ], 'en', 'de' );

		$this->assertSame( '{{TemplateOnly}}', $segment->getTranslatedText() );
	}

	/**
	 * Create a SegmentTranslator with mocked HTTP.
	 *
	 * @param string[]|null $responses If array, DeepL returns these translations.
	 *        If null and $dynamicResponder is set, that callback is used instead.
	 * @param bool $shouldFail If true, HTTP request throws an exception
	 * @param callable|null $bodyCaptor If set, called with the request body string
	 * @param callable|null $dynamicResponder If set, called with $texts array and returns translations
	 * @return SegmentTranslator
	 */
	private function makeTranslator(
		?array $responses = [],
		bool $shouldFail = false,
		?callable $bodyCaptor = null,
		?callable $dynamicResponder = null
	): SegmentTranslator {
		$config = new HashConfig( [
			'DeeplTranslateServiceUrl' => 'https://api.deepl.com/v2',
			'DeeplTranslateServiceAuth' => 'test-auth-key',
		] );

		$requestFactory = $this->createMock( HttpRequestFactory::class );

		$requestFactory->method( 'create' )->willReturnCallback(
			function ( $url, $options ) use ( $responses, $shouldFail, $bodyCaptor, $dynamicResponder ) {
				$req = $this->createMock( MWHttpRequest::class );

				$postData = $options['postData'] ?? '';
				if ( $bodyCaptor !== null && is_string( $postData ) ) {
					$bodyCaptor( $postData );
				}

				$req->method( 'setHeader' )->willReturn( null );

				if ( $shouldFail ) {
					$req->method( 'execute' )->willReturn( StatusValue::newFatal( 'API error' ) );
					$req->method( 'getStatus' )->willReturn( 500 );
					$req->method( 'getContent' )->willReturn( 'Internal Server Error' );
				} else {
					$req->method( 'execute' )->willReturn( StatusValue::newGood() );

					$actualResponses = $responses;
					if ( $dynamicResponder !== null && is_string( $postData ) ) {
						$decoded = FormatJson::decode( $postData, true );
						$actualResponses = $dynamicResponder( $decoded['text'] ?? [] );
					}

					$translationItems = array_map( static function ( $text ) {
						return [ 'text' => $text ];
					}, $actualResponses ?? [] );

					$req->method( 'getContent' )->willReturn(
						FormatJson::encode( [ 'translations' => $translationItems ] )
					);
				}

				return $req;
			}
		);

		$inlineConverter = new InlineConverter();
		return new SegmentTranslator( $config, $requestFactory, $inlineConverter );
	}
}
