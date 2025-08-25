<?php

namespace BlueSpice\TranslationTransfer\AlertProvider;

use BlueSpice\AlertProviderBase;
use BlueSpice\TranslationTransfer\Util\ContentTransfer\TargetRecognizer;
use BlueSpice\TranslationTransfer\Util\TranslationsDao;
use ContentTransfer\TargetManager;
use MediaWiki\Config\Config;
use MediaWiki\Context\RequestContext;
use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\Message\Message;
use MediaWiki\Title\Title;
use MediaWiki\User\UserGroupManager;
use Skin;
use Wikimedia\Rdbms\LoadBalancer;

/**
 * Alert banner containing either message that current translation is outdated,
 * or message that fresh translation is already in "Draft" namespace.
 *
 * Let's consider such a case:
 * Some page was translated from EN to DE.
 * So now translation of that page exists in "Draft" namespace (if enabled).
 * Then page is merged to the main namespace, it's up-to-date.
 *
 * After some time source page is updated, so corresponding message appears on "target" page.
 * That's "outdated translation" message.
 * User goes to the source page and does one more translation.
 * Now fresh translation is being transferred to "Draft namespace (if enabled).
 *
 * And in such case user should not get message "outdated translation" message,
 * but should get message: "New translation is already in 'Draft' namespace. Please merge it."
 */
class TranslationBanner extends AlertProviderBase {

	/**
	 * @var TargetRecognizer
	 */
	private $targetRecognizer;

	/**
	 * @var TargetManager
	 */
	private $targetManager;

	/**
	 * @var TranslationsDao
	 */
	private $dao;

	/**
	 * @var null
	 */
	private $currentLang;

	/**
	 * @var UserGroupManager
	 */
	private $userGroupManager;

	/**
	 * @inheritDoc
	 */
	public static function factory( $skin = null ) {
		return new static(
			$skin,
			MediaWikiServices::getInstance()->getDBLoadBalancer(),
			MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'bsg' ),
			MediaWikiServices::getInstance()->getService( 'TranslationTransferTargetRecognizer' ),
			MediaWikiServices::getInstance()->getService( 'ContentTransferTargetManager' ),
			MediaWikiServices::getInstance()->getUserGroupManager()
		);
	}

	/**
	 * @param Skin $skin
	 * @param LoadBalancer $loadBalancer
	 * @param Config $config
	 * @param TargetRecognizer $targetRecognizer
	 * @param TargetManager $targetManager
	 * @param UserGroupManager $userGroupManager
	 */
	public function __construct(
		$skin, $loadBalancer, $config,
		TargetRecognizer $targetRecognizer,
		TargetManager $targetManager,
		UserGroupManager $userGroupManager
	) {
		parent::__construct( $skin, $loadBalancer, $config );
		$this->targetRecognizer = $targetRecognizer;
		$this->targetManager = $targetManager;
		$this->userGroupManager = $userGroupManager;
	}

	/**
	 * @inheritDoc
	 */
	public function getType() {
		return 'warning';
	}

	/**
	 * @inheritDoc
	 */
	public function getHTML() {
		$this->init();

		if ( !$this->shouldRender() ) {
			return '';
		}

		$dismissHtml = '';

		// There could be case when new translation of current page is available
		// But it's still in "Draft" namespace
		// Then output different message with a link to draft of fresh translation
		$freshDraftPrefixedKey = $this->getFreshDraftTitle();
		if ( $freshDraftPrefixedKey === '' ) {
			if ( $this->dao->isTranslationAcked( $this->skin->getTitle()->getPrefixedDBkey(), $this->currentLang ) ) {
				// Current translation is already marked as "acknowledged".
				// That means that there is no need to output "outdated translation" banner
				// for that specific translation
				return '';
			}

			$user = RequestContext::getMain()->getUser();
			$groups = $this->userGroupManager->getUserGroups( $user );

			// Only "sysop" users should be able to "dismiss" translation
			if ( in_array( 'sysop', $groups ) ) {
				$this->skin->getOutput()->addModules( 'ext.translate.transfer.banner' );

				$dismissMsg = Message::newFromKey( 'bs-translation-transfer-banner-dismiss' );
				$dismissHtml = Html::rawElement( 'span', [
					'id' => 'bs-tt-banner-dismiss',
					'style' => 'margin-left: 20px;cursor: pointer;font-weight: bold;'
				], $dismissMsg->parse() );
			}

			$titleUrl = $this->composeSourceTitleUrl();
			$msg = Message::newFromKey( 'bs-translation-transfer-outdated-translation-label', $titleUrl );
		} else {
			if ( $freshDraftPrefixedKey === $this->skin->getTitle()->getPrefixedDBkey() ) {
				// We are already on the "fresh draft" wiki page.
				// No need to output "fresh draft" banner here
				return '';
			}

			$titleUrl = $this->composeDraftTitleUrl( $freshDraftPrefixedKey );
			$msg = Message::newFromKey( 'bs-translation-transfer-new-draft-translation-label', $titleUrl );
		}

		$html = Html::rawElement( 'span', [ 'role' => 'alert' ], $msg->parse() );

		if ( $dismissHtml ) {
			$html .= $dismissHtml;
		}

		return $html;
	}

	/**
	 * @return void
	 */
	private function init(): void {
		$this->currentLang = $this->targetRecognizer->recognizeCurrentTarget()['lang'];
		$this->dao = new TranslationsDao( $this->loadBalancer );
	}

	/**
	 * If alert banner should be shown or not.
	 * Alert banner should be shown if:
	 *
	 * * current wiki instance is configured for translations
	 * * current page is translation of some source page from other language
	 * * source page's last change timestamp if later than translation release timestamp,
	 * 		(so translation is outdated)
	 * * there is already a draft of fresh translation (so it should be merged with current translation version)
	 * 		and this draft is not outdated (so it contains all the latest source changes)
	 *
	 * @return bool
	 */
	private function shouldRender(): bool {
		if ( !$this->currentLang ) {
			// Current instance does not take part in translation workflow
			return false;
		}

		$currentTitlePrefixedDbKey = $this->skin->getTitle()->getPrefixedDBkey();
		if ( !$this->dao->isTarget( $this->currentLang, $currentTitlePrefixedDbKey ) ) {
			// Current title is not a translation target, so no need to display translation banner
			return false;
		}

		$draftTitlePrefixedDbKey = $this->getDraftTitle();
		$draftTitle = Title::newFromDBkey( $draftTitlePrefixedDbKey );
		if (
			$this->isTitleOutdated( $currentTitlePrefixedDbKey ) ||
			(
				$draftTitle &&
				$draftTitle->exists() &&
				!$this->isTitleOutdated( $draftTitlePrefixedDbKey )
			)
		) {
			return true;
		}

		return false;
	}

	/**
	 * Gets draft of fresh translation if it exists and if it's fresh. Otherwise returns empty string.
	 * So if draft exists, but it is outdated - empty string will be returned as well.
	 *
	 * Let's consider such a case:
	 * Some page was translated from EN to DE.
	 *
	 * After some time source page is updated, so corresponding message appears on "target" page.
	 * That's "outdated translation" message.
	 * User goes to the source page and does one more translation.
	 * Now fresh translation is being transferred to "Draft" namespace (if enabled).
	 *
	 * And in such case user should not get message "outdated translation" message,
	 * but should get message: "New translation is already in 'Draft' namespace. Please merge it."
	 *
	 * @return string Prefixed DB key of draft title if it exists,
	 * 		and it's fresh, or empty string otherwise
	 */
	private function getFreshDraftTitle(): string {
		$draftTitlePrefixedDbKey = $this->getDraftTitle();
		$draftTitle = Title::newFromDBkey( $draftTitlePrefixedDbKey );

		if ( !$draftTitle || !$draftTitle->exists() ) {
			return '';
		}

		// Actually in regular workflow there is no case when there can be "outdated draft".
		// But still it is better to check
		if ( $this->isTitleOutdated( $draftTitlePrefixedDbKey ) ) {
			return '';
		}

		return $draftTitlePrefixedDbKey;
	}

	/**
	 * Composes prefixed DB key of the "draft" title
	 *
	 * @return string Potential "draft" version of the title
	 */
	private function getDraftTitle(): string {
		// Get DB key of current title (not prefixed!)
		// Even if title is in custom namespace (example - "Demo:Some Page")
		// Then draft will be in "Draft:Some Page"
		$currentDbKey = $this->skin->getTitle()->getDBkey();

		$draftNsName = $this->targetRecognizer->getDraftNamespace( $this->currentLang );
		if ( $draftNsName === null ) {
			return '';
		}

		$draftTitlePrefixedDbKey = $draftNsName . ':' . $currentDbKey;

		// Check if draft version of that title even exists, and it's result of translation
		if ( !$this->dao->isTarget( $this->currentLang, $draftTitlePrefixedDbKey ) ) {
			return '';
		}

		return $draftTitlePrefixedDbKey;
	}

	/**
	 * @param string $titlePrefixedDbKey
	 * @return bool
	 */
	private function isTitleOutdated( string $titlePrefixedDbKey ): bool {
		$translationData = $this->dao->getTranslation( $titlePrefixedDbKey, $this->currentLang );

		$sourceLastChangeTimestamp = $translationData['tt_translations_source_last_change_date'];
		$releaseTimestamp = $translationData['tt_translations_release_date'];

		// Source last change timestamp is greater than release timestamp
		// That means that source page was changed after translation release
		if ( $sourceLastChangeTimestamp > $releaseTimestamp ) {
			return true;
		}

		return false;
	}

	/**
	 * @return string
	 */
	private function composeSourceTitleUrl(): string {
		$currentPrefixedDbKey = $this->skin->getTitle()->getPrefixedDBkey();

		$sourceData = $this->dao->getSourceFromTarget( $currentPrefixedDbKey, $this->currentLang );
		if ( !$sourceData ) {
			return '';
		}

		$sourceLang = strtolower( $sourceData['lang'] );

		return $this->targetRecognizer->composeTargetTitleLink(
			$sourceLang, $sourceData['key']
		);
	}

	/**
	 * @param string $draftTitlePrefixedKey
	 * @return string
	 */
	private function composeDraftTitleUrl( string $draftTitlePrefixedKey ): string {
		return $this->targetRecognizer->composeTargetTitleLink(
			$this->currentLang, $draftTitlePrefixedKey
		);
	}
}
