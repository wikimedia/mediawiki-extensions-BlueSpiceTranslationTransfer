<?php

namespace BlueSpice\TranslationTransfer\Hook\SkinTemplateNavigationUniversal;

use BlueSpice\TranslationTransfer\Util\ContentTransfer\TargetRecognizer;
use BlueSpice\TranslationTransfer\Util\TranslationsDao;
use ContentTransfer\TargetManager;
use MediaWiki\Config\Config;
use MediaWiki\Config\ConfigException;
use MediaWiki\Config\ConfigFactory;
use MediaWiki\Context\RequestContext;
use MediaWiki\Hook\SkinTemplateNavigation__UniversalHook;
use MediaWiki\Languages\LanguageNameUtils;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Message\Message;
use MediaWiki\Permissions\PermissionManager;
use Psr\Log\LoggerInterface;
use SkinTemplate;
use Wikimedia\Rdbms\ILoadBalancer;

class AddTranslateAction implements SkinTemplateNavigation__UniversalHook {

	/**
	 * @var Config
	 */
	private $bsgConfig;

	/**
	 * @var Config
	 */
	private $mainConfig;

	/**
	 * @var PermissionManager
	 */
	private $permissionManager;

	/**
	 * @var TargetManager
	 */
	private $targetManager;

	/**
	 * @var TargetRecognizer
	 */
	private $targetRecognizer;

	/**
	 * @var LanguageNameUtils
	 */
	private $languageNameUtils;

	/**
	 * @var ILoadBalancer
	 */
	private $lb;

	/**
	 * @var LoggerInterface
	 */
	private $logger;

	/**
	 * @param ConfigFactory $configFactory
	 * @param PermissionManager $permissionManager
	 * @param TargetManager $targetManager
	 * @param TargetRecognizer $targetRecognizer
	 * @param LanguageNameUtils $languageNameUtils
	 * @param ILoadBalancer $lb
	 */
	public function __construct(
		ConfigFactory $configFactory, PermissionManager $permissionManager,
		TargetManager $targetManager, TargetRecognizer $targetRecognizer,
		LanguageNameUtils $languageNameUtils, ILoadBalancer $lb
	) {
		$this->bsgConfig = $configFactory->makeConfig( 'bsg' );
		$this->mainConfig = $configFactory->makeConfig( 'main' );
		$this->permissionManager = $permissionManager;
		$this->targetManager = $targetManager;
		$this->targetRecognizer = $targetRecognizer;
		$this->languageNameUtils = $languageNameUtils;
		$this->lb = $lb;

		$this->logger = LoggerFactory::getInstance( 'BlueSpiceTranslationTransfer_UI' );
	}

	/**
	 * @param SkinTemplate $sktemplate
	 * @return bool
	 */
	private function skipProcessing( $sktemplate ): bool {
		if ( !$sktemplate->getTitle()->getNamespace() === NS_FILE ) {
			// Cannot translate files, logical and technical reasons
			return true;
		}

		// If there is specific "leading language" configured -
		// - then there should be not possible to translate from any other language.
		// So if content language of current wiki is not leading one - bail out.

		// Leading lang is already stored normalized
		$leadingLang = $this->bsgConfig->get( 'TranslateTransferLeadingLanguage' );

		if ( $leadingLang ) {
			$instanceLang = $this->mainConfig->get( 'LanguageCode' );
			$instanceLangNormalized = explode( '-', $instanceLang )[0];

			if ( $leadingLang !== $instanceLangNormalized ) {
				return true;
			}
		}

		// If current title is result of translation - it should not be translated anymore
		if ( $this->isTranslationTarget( $sktemplate->getTitle()->getPrefixedDBkey() ) ) {
			return true;
		}

		if ( !$this->isCurrentInstanceConfigured() ) {
			$this->logger->info( 'Current instance is not configured for translation workflow...' );
			return true;
		}

		if ( !$this->isNamespaceAvailableToTranslate(
			$sktemplate->getTitle()->getNamespace()
		) ) {
			$this->logger->info( 'Current namespace (ID - ' .
				$sktemplate->getTitle()->getNamespace() . ') is not configured for translation workflow...' );
			return true;
		}

		if ( !$sktemplate->getTitle()->exists() ) {
			return true;
		}

		return !$this->permissionManager->userCan(
			'edit', $sktemplate->getUser(), $sktemplate->getTitle()
		);
	}

	/**
	 * @inheritDoc
	 */
	public function onSkinTemplateNavigation__Universal( $sktemplate, &$links ): void {
		$mainLangCode = $this->mainConfig->get( 'LanguageCode' );
		// Normalize language code, strip any additional things like "de-ch" or "de-formal"
		$mainLangCode = explode( '-', $mainLangCode )[0];

		$mainLangLabel = $this->languageNameUtils->getLanguageName( $mainLangCode );

		$sktemplate->getOutput()->addJsConfigVars(
			'wgTranslationTransferMainLanguageLabel',
			$mainLangLabel
		);

		$sktemplate->getOutput()->addJsConfigVars(
			'wgTranslationTransferMainLanguageCode',
			$mainLangCode
		);

		// Unfortunate :(
		$sktemplate->getOutput()->addJsConfigVars(
			'wgTranslationTransferAvailableTranslateLanguages',
			$this->makeAvailableLanguages()
		);

		$sktemplate->getOutput()->addJsConfigVars(
			'wgTranslationTransferMainLanguageLabel',
			$mainLangLabel
		);

		if ( $this->skipProcessing( $sktemplate ) ) {
			return;
		}

		$sktemplate->getOutput()->addModules( 'ext.translate.transfer.flow.bootstrap' );

		$sktemplate->getOutput()->addJsConfigVars(
			'wgTranslationTransferTargets', $this->getPushTargets()
		);

		if ( $this->bsgConfig->has( 'TranslateTransferTargetNamespaceMapping' ) ) {
			$sktemplate->getOutput()->addJsConfigVars(
				'wgTranslateTransferTargetNamespaceMapping', $this->bsgConfig->get( 'TranslateTransferTargetNamespaceMapping' )
			);
		}

		$links['actions']['translate-transfer-action-translate'] = [
			'text' => Message::newFromKey( 'action-translation-transfer' )->text(),
			'href' => '#',
			'class' => false,
			'id' => 'ca-translate-transfer-action-translate'
		];
	}

	/**
	 * Make list of available languages to translate to
	 *
	 * @return array
	 * @throws ConfigException
	 */
	private function makeAvailableLanguages() {
		$langLinks = $this->bsgConfig->get( 'TranslateTransferTargets' );
		$langKeys = array_keys( $langLinks );

		$currentLangKey = $this->targetRecognizer->recognizeCurrentTarget()['lang'];

		$res = [];
		foreach ( $langKeys as $key ) {
			if ( $key === $currentLangKey ) {
				continue;
			}

			// Normalize language code, strip any additional things like "de-ch" or "de-formal"
			$langCode = explode( '-', RequestContext::getMain()->getLanguage()->getCode() )[0];

			$res[$key] = $this->languageNameUtils->getLanguageName( $key, $langCode );
		}

		return $res;
	}

	/**
	 * @return array
	 */
	private function getPushTargets() {
		$langLinks = $this->targetRecognizer->getLangToTargetKeyMap();
		$pushTargets = $this->targetManager->getTargets();
		$finalTargets = [];

		$currentTargetKey = $this->targetRecognizer->recognizeCurrentTarget()['key'];

		foreach ( $pushTargets as $targetKey => $target ) {
			if ( !in_array( $targetKey, array_values( $langLinks ) ) ) {
				continue;
			}
			if ( $targetKey === $currentTargetKey ) {
				continue;
			}
			foreach ( $langLinks as $l => $t ) {
				if ( $t === $targetKey ) {
					$config = json_decode( json_encode( $target ), true );
					if ( isset( $config['users'] ) ) {
						$config['user'] = $config['users'][0];
						unset( $config['users'] );
					}
					$config['id'] = $t;
					$finalTargets[$l] = $config;

					$finalTargets[$l]['pushToDraft'] = $this->targetRecognizer->getPushToDraftConfig( $l );
					$finalTargets[$l]['draftNamespace'] = $this->targetRecognizer->getDraftNamespace( $l );
				}
			}
		}

		return $finalTargets;
	}

	/**
	 * Check if current wiki instance is configured for translation workflow.
	 * If not - "translate" action won't be shown
	 *
	 * @return bool
	 */
	private function isCurrentInstanceConfigured(): bool {
		$currentLang = $this->targetRecognizer->recognizeCurrentTarget()['lang'];
		if ( !$currentLang ) {
			// Probably instance is just not used in translations configuration. Skip processing then
			return false;
		}

		return true;
	}

	/**
	 * Check if title's namespace is available for outgoing translations.
	 * If not - "translate" action won't be shown
	 *
	 * @param int $ns
	 * @return bool
	 */
	private function isNamespaceAvailableToTranslate( int $ns ): bool {
		$langToAvailableNamespacesMap = $this->bsgConfig->get( 'TranslateTransferNamespaces' ) ?? [];
		$currentLang = $this->targetRecognizer->recognizeCurrentTarget()['lang'];
		$availableNamespaces = $langToAvailableNamespacesMap[$currentLang] ?? [];
		return in_array( $ns, $availableNamespaces );
	}

	/**
	 * @param string $titlePrefixedDbKey
	 *
	 * @return bool
	 */
	private function isTranslationTarget( string $titlePrefixedDbKey ): bool {
		$currentLang = $this->targetRecognizer->recognizeCurrentTarget()['lang'];

		$dao = new TranslationsDao( $this->lb );
		return $dao->isTarget( $currentLang, $titlePrefixedDbKey );
	}
}
