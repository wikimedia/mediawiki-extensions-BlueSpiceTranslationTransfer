<?php

namespace BlueSpice\TranslationTransfer;

use BlueSpice\TranslationTransfer\Util\GlossaryDao;
use Exception;
use MediaWiki\Config\Config;
use MediaWiki\Content\WikitextContent;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Json\FormatJson;
use MediaWiki\MediaWikiServices;
use MediaWiki\Status\Status;
use MediaWiki\Title\Title;
use MWStake\MediaWiki\Component\DeeplTranslator\DeepLTranslator;

class DeepL extends DeepLTranslator {

	protected const PARAM_GLOSSARY_ID = 'glossary_id';

	/**
	 * @var GlossaryDao
	 */
	private $glossaryDao;

	/**
	 *
	 * @param Config $config
	 * @param HttpRequestFactory $requestFactory
	 */
	public function __construct( Config $config, HttpRequestFactory $requestFactory ) {
		parent::__construct( $config, $requestFactory );

		$lb = MediaWikiServices::getInstance()->getDBLoadBalancer();

		$this->glossaryDao = new GlossaryDao( $lb );
	}

	/**
	 *
	 * @param Title|null $title
	 * @param string|null $wikitext
	 * @param string[] $targetLanguages
	 * @return Status
	 */
	public function translate( ?Title $title = null, string $wikitext = '', $targetLanguages = [] ) {
		if ( !$title || !$title->exists() ) {
			// TODO: messages
			return Status::newFatal( 'given title does not exist' );
		}
		$wikiPage = MediaWikiServices::getInstance()->getWikiPageFactory()
			->newFromTitle( $title );
		if ( !$wikiPage || !$wikiPage->getContent() instanceof WikitextContent ) {
			return Status::newFatal( 'invalid content type' );
		}
		$sourceLang = $this->extractSourceLanguage();
		if ( !$sourceLang ) {
			return Status::newFatal( 'invalid source language' );
		}
		if ( empty( $targetLanguages ) ) {
			$targetLanguages = $this->extractPossibleTransations( $title );
		} else {
			$allowed = $this->extractPossibleTransations( $title );
			$targetLanguages = array_filter( $targetLanguages, static function ( $e ) use( $allowed ) {
				return is_scalar( $e ) && in_array( $e, $allowed );
			} );
		}
		if ( empty( $targetLanguages ) ) {
			return Status::newFatal( 'no target languages available' );
		}
		$result = [];
		$status = Status::newGood();
		foreach ( $targetLanguages as $lang ) {
			$text = $title->getText();

			try {
				$req = $this->getRequest( $text, strtoupper( $sourceLang ), strtoupper( $lang ) );
				$status->merge( $req->execute(), true );
			} catch ( Exception $e ) {
				$status->fatal( $e->getMessage() );
			}
			if ( !$status->isOk() ) {
				return $status;
			}
			$res = FormatJson::decode( $req->getContent() );
			if ( empty( $res->translations ) || empty( $res->translations[0]->text ) ) {
				$status->fatal( "invalid translation for title $text" );
				return $status;
			}
			$translateTitle = Title::newFromText( $res->translations[0]->text );
			if ( !$translateTitle ) {
				$status->fatal( "title could not be created from {$res->translations[0]->text}" );
				return $status;
			}

			if ( $wikitext ) {
				try {
					$req = $this->getRequest( $wikitext, strtoupper( $sourceLang ), strtoupper( $lang ) );
					$status->merge( $req->execute(), true );
				} catch ( Exception $e ) {
					$status->fatal( $e->getMessage() );
				}
				if ( !$status->isOk() ) {
					return $status;
				}
				$res = FormatJson::decode( $req->getContent() );
				if ( empty( $res->translations ) || empty( $res->translations[0]->text ) ) {
					$status->fatal( "empty translation for title {$translateTitle->getFullText()}" );
					return $status;
				}
				$result[$lang] = [
					'title' => $translateTitle->getFullText(),
					'text' => $res->translations[0]->text
				];
			} else {
				$result[$lang] = [
					'title' => $translateTitle->getFullText(),
					'text' => ''
				];
			}
		}
		$status->merge( Status::newGood( $result ), true );
		return $status;
	}

	/**
	 * @inheritDoc
	 */
	protected function makePostData( $text, $sourceLang, $targetLang ) {
		$data = parent::makePostData( $text, $sourceLang, $targetLang );

		// Consider DeepL glossary
		$glossaryId = $this->glossaryDao->getGlossaryId(
			// DeepL requires "target_lang" to be upper-case,
			// but in all other places (for example, local glossary)
			// lower-case language is used
			strtolower( $targetLang )
		);
		if ( $glossaryId !== null ) {
			$data[static::PARAM_GLOSSARY_ID] = $glossaryId;
		}

		return $data;
	}

	/**
	 *
	 * @return string|false
	 */
	public function extractSourceLanguage() {
		return explode( '-', $this->config->get( 'LanguageCode' ) )[0];
	}

	/**
	 *
	 * @param Title $title
	 * @return array
	 */
	protected function extractPossibleTransations( Title $title ) {
		$codes = array_keys( $this->config->get( 'TranslateTransferTargets' ) );
		if ( empty( $codes ) ) {
			return $codes;
		}

		return array_diff(
			$codes,
			[ $this->extractSourceLanguage() ]
		);
	}
}
