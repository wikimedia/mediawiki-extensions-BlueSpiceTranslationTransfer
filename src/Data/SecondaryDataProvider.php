<?php

namespace BlueSpice\TranslationTransfer\Data;

use BlueSpice\TranslationTransfer\Util\ContentTransfer\TargetRecognizer;
use MediaWiki\Context\RequestContext;
use MWStake\MediaWiki\Component\DataStore\ISecondaryDataProvider;

class SecondaryDataProvider implements ISecondaryDataProvider {

	/**
	 * @var RequestContext
	 */
	private $context;

	/**
	 * @var TargetRecognizer
	 */
	private $targetRecognizer;

	/**
	 * @param TargetRecognizer $targetRecognizer
	 */
	public function __construct( TargetRecognizer $targetRecognizer ) {
		$this->context = RequestContext::getMain();
		$this->targetRecognizer = $targetRecognizer;
	}

	/**
	 * @inheritDoc
	 */
	public function extend( $dataSets ) {
		foreach ( $dataSets as $dataSet ) {
			$sourceLang = strtolower( $dataSet->get( TranslationRecord::SOURCE_LANGUAGE ) );
			$targetLang = strtolower( $dataSet->get( TranslationRecord::TARGET_LANGUAGE ) );

			$sourcePrefixedDbKey = $dataSet->get( TranslationRecord::SOURCE_PAGE_PREFIXED_TITLE_KEY );
			$targetPrefixedDbKey = $dataSet->get( TranslationRecord::TARGET_PAGE_PREFIXED_TITLE_KEY );

			$dataSet->set(
				TranslationRecord::SOURCE_PAGE_LINK,
				$this->targetRecognizer->composeTargetTitleLink( $sourceLang, $sourcePrefixedDbKey )
			);
			$dataSet->set(
				TranslationRecord::TARGET_PAGE_LINK,
				$this->targetRecognizer->composeTargetTitleLink( $targetLang, $targetPrefixedDbKey )
			);

			$releaseTs = $dataSet->get( TranslationRecord::RELEASE_TIMESTAMP );
			if ( $releaseTs ) {
				$dataSet->set(
					TranslationRecord::RELEASE_TIMESTAMP_FORMATTED,
					$this->context->getLanguage()->userDate( $releaseTs, $this->context->getUser() )
				);
			}

			$sourceLastChangeTs = $dataSet->get( TranslationRecord::SOURCE_LAST_CHANGE_TIMESTAMP );
			if ( $sourceLastChangeTs ) {
				$dataSet->set(
					TranslationRecord::SOURCE_LAST_CHANGE_TIMESTAMP_FORMATTED,
					$this->context->getLanguage()->userDate( $sourceLastChangeTs, $this->context->getUser() )
				);
			}

			$targetLastChangeTs = $dataSet->get( TranslationRecord::TARGET_LAST_CHANGE_TIMESTAMP );
			if ( $targetLastChangeTs ) {
				$dataSet->set(
					TranslationRecord::TARGET_LAST_CHANGE_TIMESTAMP_FORMATTED,
					$this->context->getLanguage()->userDate( $targetLastChangeTs, $this->context->getUser() )
				);
			} else {
				$dataSet->set( TranslationRecord::TARGET_LAST_CHANGE_TIMESTAMP_FORMATTED, '' );
			}
		}

		return $dataSets;
	}
}
