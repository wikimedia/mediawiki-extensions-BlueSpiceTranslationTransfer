<?php

namespace BlueSpice\TranslationTransfer\Tests\Util\ContentTransfer;

use BlueSpice\TranslationTransfer\Util\ContentTransfer\TargetRecognizer;
use ContentTransfer\TargetManager;
use MediaWiki\Config\Config;
use MediaWiki\Config\ConfigFactory;
use MediaWiki\Config\HashConfig;
use PHPUnit\Framework\TestCase;

/**
 * @covers \BlueSpice\TranslationTransfer\Util\ContentTransfer\TargetRecognizer
 */
class TargetRecognizerTest extends TestCase {

	/** @var TargetManager */
	private $targetManager;

	/** @var Config */
	private $configMock;

	/** @var ConfigFactory */
	private $configFactoryMock;

	/** @var Config */
	private $mainConfigMock;

	/**
	 * @var array[]
	 */
	private $targets = [
		'target_de' => [
			"url" => "https://w/De-Wiki/api.php",
			"access_token" => "dummy"
		],
		'target_en' => [
			"url" => "https://w/En-Wiki/api.php",
			"access_token" => "dummy"
		],
		'target_no_lang' => [
			"url" => "https://w/Some-Wiki/api.php",
			"access_token" => "dummy"
		],
		'target_it__with_pretty_article_path' => [
			"url" => "https://w/It-Wiki/api.php",
			"access_token" => "dummy"
		]
	];

	/**
	 * @var string[]
	 */
	private $langTargetMap = [
		'en' => [
			'key' => 'target_en',
			'url' => 'https://w/En-Wiki/index.php'
		],
		'de' => [
			'key' => 'target_de',
			'url' => 'https://w/De-Wiki/index.php'
		],
		'it' => [
			'key' => 'target_it__with_pretty_article_path',
			'url' => 'https://w/It-Wiki/Pretty_Article_Path'
		]
	];

	private function init() {
		$this->targetManager = new TargetManager(
			new HashConfig( [ 'ContentTransferTargets' => $this->targets ] )
		);

		$this->configMock = $this->createMock( Config::class );
		$this->configMock->method( 'get' )->willReturn( $this->langTargetMap );

		$this->configFactoryMock = $this->createMock( ConfigFactory::class );
		$this->configFactoryMock->method( 'makeConfig' )->willReturn( $this->configMock );

		$this->mainConfigMock = $this->createMock( Config::class );
	}

	/**
	 * @return array[]
	 */
	public function provideRecognizeTargetData() {
		return [
			'Target recognized' => [
				'https://w/De-Wiki/Demo:Some_Title',
				[
					'key' => 'target_de',
					'lang' => 'de'
				]
			],
			'Target not recognized' => [
				'https://w/unknown_target/Demo:Some_Title',
				[
					'key' => null,
					'lang' => false
				]
			],
			'Target recognized, but language not configured' => [
				'https://w/Some-Wiki/Some_Title',
				[
					'key' => 'target_no_lang',
					'lang' => false
				]
			],
			'Target with pretty article path' => [
				'https://w/It-Wiki/Pretty_Article_Path/Some_Title',
				[
					'key' => 'target_it__with_pretty_article_path',
					'lang' => 'it'
				]
			]
		];
	}

	/**
	 * @covers \BlueSpice\TranslationTransfer\Util\ContentTransfer\TargetRecognizer::recognizeTarget
	 * @dataProvider provideRecognizeTargetData
	 */
	public function testRecognizeTarget( $urlToRecognize, $expectedResult ) {
		$this->init();

		$targetRecognizer = new TargetRecognizer( $this->configFactoryMock, $this->mainConfigMock, $this->targetManager );

		$actualResult = $targetRecognizer->recognizeTarget( $urlToRecognize );

		$this->assertEquals( $expectedResult, $actualResult );
	}

	/**
	 * @return array[]
	 */
	public function provideComposeTargetLinkData() {
		return [
			'Case 1' => [
				'en',
				'My_Test_Title',
				'https://w/En-Wiki/index.php/My_Test_Title'
			],
			'Case 2' => [
				'de',
				'Demo:My_Test_Title',
				'https://w/De-Wiki/index.php/Demo:My_Test_Title'
			]
		];
	}

	/**
	 * @covers \BlueSpice\TranslationTransfer\Util\ContentTransfer\TargetRecognizer::composeTargetTitleLink
	 * @dataProvider provideComposeTargetLinkData
	 */
	public function testComposeTargetLink( $targetLang, $titleDbKey, $expectedRes ) {
		$this->init();

		$targetRecognizer = new TargetRecognizer( $this->configFactoryMock, $this->mainConfigMock, $this->targetManager );

		$actualRes = $targetRecognizer->composeTargetTitleLink( $targetLang, $titleDbKey );

		$this->assertEquals( $expectedRes, $actualRes );
	}
}
