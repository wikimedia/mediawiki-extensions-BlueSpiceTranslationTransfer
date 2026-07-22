<?php

namespace BlueSpice\TranslationTransfer\Maintenance\PostDatabaseUpdate;

use MediaWiki\Config\Config;
use MediaWiki\Config\GlobalVarConfig;
use MediaWiki\Config\MultiConfig;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Json\FormatJson;
use MediaWiki\Maintenance\LoggedUpdateMaintenance;
use MediaWiki\MediaWikiServices;

require_once dirname( __DIR__, 5 ) . "/maintenance/Maintenance.php";

/**
 * One-time migration: creates a DeepL v3 multilingual glossary from existing
 * glossary entries and stores its ID in bs_settings3.
 *
 * If DeepL API is not configured or there
 * are no glossary entries, the script exits gracefully.
 */
class MigrateGlossaryToV3 extends LoggedUpdateMaintenance {

	private const GLOSSARY_ID_CONFIG_NAME = 'TranslateTransferDeeplGlossaryId';

	/**
	 * @var Config
	 */
	private $config;

	/**
	 * @inheritDoc
	 */
	protected function doDBUpdates() {
		$services = MediaWikiServices::getInstance();
		$db = $services->getDBLoadBalancer()->getConnection( DB_PRIMARY );

		// Check if already migrated
		$existing = $db->selectField(
			'bs_settings3',
			's_value',
			[ 's_name' => self::GLOSSARY_ID_CONFIG_NAME ],
			__METHOD__
		);
		if ( $existing !== false ) {
			$this->output( "DeepL v3 glossary ID already exists in config. Skipping.\n" );
			return true;
		}

		// Load config
		$configFactory = $services->getConfigFactory();
		$this->config = new MultiConfig( [
			new GlobalVarConfig( 'mwsg' ),
			$configFactory->makeConfig( 'bsg' )
		] );

		$serviceUrl = $this->config->get( 'DeeplTranslateServiceUrl' );
		$authKey = $this->config->get( 'DeeplTranslateServiceAuth' );

		if ( !$serviceUrl || !$authKey ) {
			$this->output( "DeepL API not configured. Skipping glossary migration.\n" );
			return true;
		}

		// Get all distinct languages that have entries
		$res = $db->select(
			'bs_tt_glossary_entries',
			[ 'tt_ge_lang' ],
			'',
			__METHOD__,
			[ 'DISTINCT' ]
		);

		$languages = [];
		foreach ( $res as $row ) {
			$languages[] = $row->tt_ge_lang;
		}

		if ( empty( $languages ) ) {
			$this->output( "No glossary entries found. Skipping.\n" );
			return true;
		}

		// Build dictionaries for each language
		$sourceLang = explode( '-', $this->config->get( 'LanguageCode' ) )[0];
		$dictionaries = [];

		foreach ( $languages as $lang ) {
			$entries = [];
			$entryRes = $db->select(
				'bs_tt_glossary_entries',
				[ 'tt_ge_source_text', 'tt_ge_translation' ],
				[ 'tt_ge_lang' => $lang ],
				__METHOD__
			);

			foreach ( $entryRes as $row ) {
				// Properly escape any double quotes inside of values, if there are any,
				// and wrap values themselves into double quotes.
				$source = '"' . str_replace( '"', '""', $row->tt_ge_source_text ) . '"';
				$translation = '"' . str_replace( '"', '""', $row->tt_ge_translation ) . '"';

				$entries[] = "$source,$translation";
			}

			if ( !empty( $entries ) ) {
				$dictionaries[] = [
					'source_lang' => $sourceLang,
					'target_lang' => $lang,
					'entries' => implode( "\n", $entries ) . "\n",
					'entries_format' => 'csv'
				];
			}
		}

		if ( empty( $dictionaries ) ) {
			$this->output( "No non-empty dictionaries to create. Skipping.\n" );
			return true;
		}

		// Create v3 multilingual glossary
		$this->output( "Creating DeepL v3 multilingual glossary with "
			. count( $dictionaries ) . " dictionaries...\n" );

		$requestFactory = $services->getHttpRequestFactory();
		$glossaryId = $this->createV3Glossary( $requestFactory, $dictionaries );

		if ( $glossaryId === null ) {
			$this->error( "Failed to create DeepL v3 glossary. Migration incomplete.\n" );
			return false;
		}

		// Store the glossary ID
		$db->insert(
			'bs_settings3',
			[
				's_name' => self::GLOSSARY_ID_CONFIG_NAME,
				's_value' => FormatJson::encode( $glossaryId ),
			],
			__METHOD__
		);

		$this->output( "DeepL v3 glossary created successfully. ID: $glossaryId\n" );

		return true;
	}

	/**
	 * @param HttpRequestFactory $requestFactory
	 * @param array $dictionaries
	 * @return string|null Glossary ID or null on failure
	 */
	private function createV3Glossary(
		HttpRequestFactory $requestFactory,
		array $dictionaries
	): ?string {
		$url = rtrim( $this->config->get( 'DeeplTranslateServiceUrl' ), '/' );
		// B/C: strip trailing version path if present
		$url = preg_replace( '#/v\d+$#', '', $url );
		$url .= '/v3/glossaries';

		$jsonBody = FormatJson::encode( [
			'name' => 'BlueSpice Glossary',
			'dictionaries' => $dictionaries
		] );

		$req = $requestFactory->create( $url, [
			'method' => 'post',
			'postData' => $jsonBody,
			'timeout' => 120,
			'sslVerifyHost' => 0,
			'followRedirects' => true,
			'sslVerifyCert' => false,
		] );

		$req->setHeader( 'Content-Type', 'application/json' );
		$req->setHeader(
			'Authorization',
			'DeepL-Auth-Key ' . $this->config->get( 'DeeplTranslateServiceAuth' )
		);

		$status = $req->execute();
		if ( !$status->isOK() ) {
			$this->error( "DeepL API error: " . $req->getContent() . "\n" );
			return null;
		}

		$response = FormatJson::decode( $req->getContent(), true );
		return $response['glossary_id'] ?? null;
	}

	/**
	 * @inheritDoc
	 */
	protected function getUpdateKey() {
		return 'bs_translationtransfer_migrate_glossary_to_v3';
	}
}

$maintClass = MigrateGlossaryToV3::class;
require_once RUN_MAINTENANCE_IF_MAIN;
