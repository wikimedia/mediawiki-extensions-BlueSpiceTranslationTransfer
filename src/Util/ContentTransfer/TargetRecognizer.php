<?php

namespace BlueSpice\TranslationTransfer\Util\ContentTransfer;

use ContentTransfer\TargetManager;
use MediaWiki\Config\Config;
use MediaWiki\Config\ConfigFactory;

/**
 * Helps to recognize one of the "ContentTransfer" targets by its URL.
 * See "$wgContentTransferTargets" global variable.
 * Also gives information about target's language. See "$bsgTranslateTransferTargets"
 */
class TargetRecognizer {

	/**
	 * @var Config
	 */
	private $mainConfig;

	/**
	 * @var TargetManager
	 */
	private $targetManager;

	/** @var ConfigFactory */
	private ConfigFactory $configFactory;

	/**
	 * See "$bsgTranslateTransferTargets"
	 *
	 * @var array|null
	 */
	private $langTargetDataMap = null;

	/**
	 * Key is language code, value is "target key" from ContentTransfer.
	 *
	 * @var array
	 */
	private $langTargetKeyMap = [];

	/**
	 * Information about current target.
	 * It is cached after first recognizing.
	 *
	 * Array with such structure:
	 * [
	 * 		'key' => <targetKey>,
	 * 		'lang' => <targetLang>
	 * ]
	 *
	 * @var array
	 */
	private $currentTarget = null;

	/**
	 * @param ConfigFactory $configFactory
	 * @param Config $mainConfig
	 * @param TargetManager $targetManager
	 */
	public function __construct(
		ConfigFactory $configFactory, Config $mainConfig, TargetManager $targetManager
	) {
		$this->configFactory = $configFactory;
		$this->mainConfig = $mainConfig;
		$this->targetManager = $targetManager;
	}

	/**
	 * Returns translation transfer target associated with specified URL, and target's language.
	 *
	 * @param string $url
	 * @return array Array with such structure:
	 * [
	 * 		'key' => <targetKey>,
	 * 		'lang' => <targetLang>
	 * ]
	 */
	public function recognizeTarget( string $url ): array {
		$targetKey = null;

		$this->assertTargetsLoaded();
		// Go through all configured languages and check "client URL" of each of them
		foreach ( $this->langTargetDataMap as $lang => $targetData ) {
			if ( isset( $targetData['url'] ) ) {
				$targetClientUrl = $targetData['url'];

				if ( strpos( $url, $targetClientUrl ) !== false ) {
					$targetKey = $targetData['key'];
					break;
				}
			}
		}

		if ( $targetKey === null ) {
			// As the last fallback - check URLs of targets in ContentTransfer configuration
			$targetKey = $this->checkContentTransferTargets( $url );
		}

		return [
			'key' => $targetKey,
			'lang' => array_search( $targetKey, $this->langTargetKeyMap )
		];
	}

	/**
	 * @param string $url
	 * @return string|null
	 */
	private function checkContentTransferTargets( string $url ): ?string {
		$targetKey = null;

		$targets = $this->targetManager->getTargets();
		foreach ( $targets as $key => $target ) {
			$targetApiUrl = $target->getUrl();

			// Strip "api.php"
			$urlApiPos = strpos( $targetApiUrl, '/api.php' );
			$targetBaseUrl = substr( $targetApiUrl, 0, $urlApiPos );

			if ( strpos( $url, $targetBaseUrl ) !== false ) {
				$targetKey = $key;
				break;
			}
		}

		return $targetKey;
	}

	/**
	 * Recognizes current target and returns data about it.
	 *
	 * @return array
	 * @see TargetRecognizer::recognizeTarget()
	 */
	public function recognizeCurrentTarget(): array {
		if ( $this->currentTarget === null ) {
			$this->assertTargetsLoaded();
			// At first check if there is any ContentTransfer target instance,
			// associated with content language of current instance.
			// That will already cover most of the cases.
			$contentLangCode = $this->mainConfig->get( 'LanguageCode' );

			// Normalize language code, strip any additional things like in "de-ch" or "de-formal"
			$contentLangCode = explode( '-', $contentLangCode )[0];

			$normalizedLang = $this->normalizeLang( $contentLangCode );
			if ( isset( $this->langTargetDataMap[$normalizedLang] ) ) {
				$this->currentTarget = [
					'key' => $this->langTargetDataMap[$normalizedLang]['key'],
					'lang' => $normalizedLang
				];
			} else {
				// Otherwise compare "$wgServer . $wgArticlePath" with existing in configuration "client URLs"
				$server = $this->mainConfig->get( 'Server' );
				$articlePath = $this->mainConfig->get( 'ArticlePath' );

				$this->currentTarget = $this->recognizeTarget( $server . $articlePath );
			}
		}

		return $this->currentTarget;
	}

	/**
	 * @param string $lang
	 * @return string
	 */
	private function normalizeLang( string $lang ): string {
		return strtolower( $lang );
	}

	/**
	 * @param string $language
	 * @param string $titleDbKey
	 * @return string
	 */
	public function composeTargetTitleLink( string $language, string $titleDbKey ): string {
		$normalizedLang = $this->normalizeLang( $language );
		$targetClientUrl = $this->getTargetClientUrl( $normalizedLang );

		// If there is no slash in the end of the URL - add it
		if ( strrpos( $targetClientUrl, '/' ) !== strlen( $targetClientUrl ) - 1 ) {
			$targetClientUrl = $targetClientUrl . '/';
		}

		return $targetClientUrl . wfUrlencode( $titleDbKey );
	}

	/**
	 * @param string $language
	 * @return string
	 */
	public function getTargetClientUrl( string $language ): string {
		$this->assertTargetsLoaded();
		$normalizedLang = $this->normalizeLang( $language );

		// Could happen if, for example, some page was translated from DE to EN,
		// and then EN instance/lang was disabled.
		// We should prevent fatal in that case (so, for example, "TranslationOverview" won't break),
		// but it would still make sense to indicate that issue somehow, instead of silently breaking link
		if ( empty( $this->langTargetDataMap[$normalizedLang]['url'] ) ) {
			// TODO: Add logging for that case?
		}

		return $this->langTargetDataMap[$normalizedLang]['url'] ?? '';
	}

	/**
	 * @return array
	 */
	public function getLangToTargetKeyMap(): array {
		$this->assertTargetsLoaded();

		return $this->langTargetKeyMap;
	}

	/**
	 * @param string $targetUrl
	 * @return string|null
	 */
	public function getTargetKeyFromTargetUrl( string $targetUrl ): ?string {
		$targetKey = null;

		$targets = $this->targetManager->getTargets();
		foreach ( $targets as $key => $target ) {
			$targetApiUrl = $target->getUrl();

			if ( $targetUrl === $targetApiUrl ) {
				$targetKey = $key;
				break;
			}
		}

		return $targetKey;
	}

	/**
	 * @param string $language
	 * @return bool
	 */
	public function isTarget( string $language ): bool {
		$this->assertTargetsLoaded();
		$normalizedLang = $this->normalizeLang( $language );
		return isset( $this->langTargetDataMap[$normalizedLang] );
	}

	/**
	 * @return void
	 */
	private function assertTargetsLoaded() {
		if ( $this->langTargetDataMap === null ) {
			$this->langTargetDataMap = [];
			$bsgConfig = $this->configFactory->makeConfig( 'bsg' );
			$langTargetDataMap = $bsgConfig->get( 'TranslateTransferTargets' );
			// Configuration contains more data than we need,
			// so extract only target "key" column from there.
			// There are some use cases for specifically that map
			foreach ( $langTargetDataMap as $lang => $targetData ) {
				$normalizedLang = strtolower( $lang );
				$this->langTargetKeyMap[$normalizedLang] = $targetData['key'];
				$this->langTargetDataMap[$normalizedLang] = $targetData;
			}
		}
	}

	/**
	 * @param string $langCode
	 * @return string|null
	 */
	public function getDraftNamespace( string $langCode ): ?string {
		$normalizedLangCode = $this->normalizeLang( $langCode );

		return $this->langTargetDataMap[$normalizedLangCode]['draftNamespace'] ?? null;
	}

	/**
	 * @param string $langCode
	 * @return string|null
	 */
	public function getPushToDraftConfig( string $langCode ): ?string {
		$normalizedLangCode = $this->normalizeLang( $langCode );

		return $this->langTargetDataMap[$normalizedLangCode]['pushToDraft'] ?? null;
	}
}
