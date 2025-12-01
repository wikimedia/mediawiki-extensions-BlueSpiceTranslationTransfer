<?php

namespace BlueSpice\TranslationTransfer\ConfigDefinition;

use BlueSpice\ConfigDefinition\ArraySetting;
use BlueSpice\TranslationTransfer\Util\ContentTransfer\TargetRecognizer;
use MediaWiki\Config\Config;
use MediaWiki\Context\IContextSource;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\NamespaceInfo;

class TranslateTransferNamespaces extends ArraySetting {

	/**
	 * @var NamespaceInfo
	 */
	private $namespaceInfo;

	/**
	 * @var TargetRecognizer
	 */
	private $targetRecognizer;

	/**
	 * @param IContextSource $context
	 * @param Config $config
	 * @param string $name
	 *
	 * @return static
	 */
	public static function getInstance( $context, $config, $name ) {
		$services = MediaWikiServices::getInstance();
		$namespaceInfo = $services->getNamespaceInfo();
		$targetRecognizer = $services->getService( 'TranslationTransferTargetRecognizer' );

		return new static( $context, $config, $name, $namespaceInfo, $targetRecognizer );
	}

	/**
	 * @param IContextSource $context
	 * @param Config $config
	 * @param string $name
	 * @param NamespaceInfo $namespaceInfo
	 * @param TargetRecognizer $targetRecognizer
	 */
	public function __construct(
		$context, $config, $name,
		NamespaceInfo $namespaceInfo, TargetRecognizer $targetRecognizer
	) {
		parent::__construct( $context, $config, $name );
		$this->namespaceInfo = $namespaceInfo;
		$this->targetRecognizer = $targetRecognizer;
	}

	/**
	 * @inheritDoc
	 */
	public function getPaths() {
		return [
			static::MAIN_PATH_FEATURE . '/' . static::FEATURE_CONTENT_STRUCTURING . '/BlueSpiceTranslationTransfer',
			static::MAIN_PATH_EXTENSION . '/BlueSpiceTranslationTransfer' . '/' . static::FEATURE_CONTENT_STRUCTURING,
			static::MAIN_PATH_PACKAGE . '/' . static::PACKAGE_PRO . '/BlueSpiceTranslationTransfer',
		];
	}

	/**
	 * @inheritDoc
	 */
	public function getLabelMessageKey() {
		return 'bs-translation-transfer-config-namespaces-enabled';
	}

	/**
	 * @inheritDoc
	 */
	public function getHelpMessageKey() {
		return 'bs-translation-transfer-config-namespaces-enabled-help-label';
	}

	/**
	 * @inheritDoc
	 */
	public function getVariableName() {
		return 'bsg' . $this->getName();
	}

	/**
	 * @inheritDoc
	 */
	public function getHtmlFormField() {
		return new EnabledNamespacesFormField( $this->makeFormFieldParams() );
	}

	/**
	 * @inheritDoc
	 */
	protected function makeFormFieldParams() {
		return array_merge( parent::makeFormFieldParams(), [
			'data' => [
				'allValues' => $this->getValue(),
				'currentLang' => $this->targetRecognizer->recognizeCurrentTarget()['lang']
			]
		] );
	}

	/**
	 * Hide configuration for enabling namespaces for translation if user cannot translate pages from current instance.
	 * It could be if:
	 * * That's main instance, it does not take part in translations workflow.
	 * * There is specific "leading language" configured, and current instance has another content language.
	 *
	 * @return bool
	 */
	public function isHidden() {
		// Hide that config for root wiki instance, as soon as it never takes place in
		// translations workflow.
		if ( defined( 'FARMER_IS_ROOT_WIKI_CALL' ) && FARMER_IS_ROOT_WIKI_CALL ) {
			return true;
		}

		// Leading lang is already stored normalized
		$leadingLang = $this->config->get( 'TranslateTransferLeadingLanguage' );

		if ( $leadingLang ) {
			$instanceLang = $this->config->get( 'LanguageCode' );
			$instanceLangNormalized = explode( '-', $instanceLang )[0];

			if ( $leadingLang === $instanceLangNormalized ) {
				return false;
			} else {
				return true;
			}
		} else {
			// If leading language is not configured - do not hide configuration
			return false;
		}
	}

	/**
	 * @return array
	 */
	private function getNamespaces(): array {
		$namespaces = [];

		foreach ( $this->namespaceInfo->getContentNamespaces() as $id ) {
			if ( $id === NS_MAIN ) {
				// Make NS_MAIN namespace more "descriptive" for user, instead of empty string
				$namespaces[$id] = "(Main)";
			} else {
				$namespaces[$id] = $this->namespaceInfo->getCanonicalName( $id );
			}
		}

		// Sort namespaces by increasing ID, that's probably more comfortable for user
		ksort( $namespaces );

		return $namespaces;
	}

	/**
	 * @inheritDoc
	 */
	protected function getOptions() {
		return $this->getNamespaces();
	}
}
