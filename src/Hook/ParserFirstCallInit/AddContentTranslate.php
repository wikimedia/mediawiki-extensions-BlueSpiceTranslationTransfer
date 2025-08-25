<?php

namespace BlueSpice\TranslationTransfer\Hook\ParserFirstCallInit;

use BlueSpice\Hook\ParserFirstCallInit;
use MediaWiki\Config\ConfigException;
use MediaWiki\Context\RequestContext;
use MediaWiki\Html\Html;
use MediaWiki\Json\FormatJson;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\Parser;

class AddContentTranslate extends ParserFirstCallInit {

	protected function doProcess() {
		$this->parser->setFunctionHook( 'contentTranslate', [ self::class, 'addTranslateUI' ] );
	}

	/**
	 * @param Parser $parser
	 * @param string $langs
	 * @return string
	 * @throws ConfigException
	 */
	public static function addTranslateUI( Parser $parser, $langs = '' ) {
		$parser->getOutput()->addModules( [ 'ext.translate.local' ] );

		$allowed = explode( ',', $langs );
		$allowed = array_map( static function ( $item ) {
			return trim( strtolower( $item ) );
		}, $allowed );
		$available = static::makeAvailableLanguages();
		$available = array_filter( $available, static function ( $item ) use ( $allowed ) {
			return in_array( $item, $allowed );
		}, ARRAY_FILTER_USE_KEY );

		return Html::element( 'div', [
			'id' => 'bs-translation-transfer-local',
			'style' => 'height: 50px',
			'data-languages' => FormatJson::encode( $available ),
		] );
	}

	/**
	 * Make list of available languages to translate to
	 *
	 * @return array
	 * @throws ConfigException
	 */
	private static function makeAvailableLanguages() {
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'bsg' );
		$langLinks = $config->get( 'TranslateTransferTargets' );
		$langKeys = array_keys( $langLinks );

		$res = [];
		foreach ( $langKeys as $key ) {
			$res[$key] = MediaWikiServices::getInstance()->getLanguageNameUtils()
				->getLanguageName( $key, RequestContext::getMain()->getLanguage()->getCode() );
		}

		return $res;
	}

}
