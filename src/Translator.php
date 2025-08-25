<?php

namespace BlueSpice\TranslationTransfer;

use BlueSpice\TranslationTransfer\Dictionary\TitleDictionary;
use BlueSpice\TranslationTransfer\Tests\TranslatorTest;
use BlueSpice\TranslationTransfer\Util\TranslationsDao;
use Exception;
use LogicException;
use MediaWiki\Content\TextContent;
use MediaWiki\Message\Message;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Title\Title;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Class which can be used for translating wiki titles.
 *
 * @see TranslatorTest
 */
class Translator implements LoggerAwareInterface {

	/**
	 * @var DeepL
	 */
	private $deepL;

	/**
	 * @var TranslationWikitextConverter
	 */
	private $wtConverter;

	/**
	 * @var LoggerInterface
	 */
	private $logger;

	/**
	 * @var WikiPageFactory
	 */
	private $wikiPageFactory;

	/**
	 * @var TranslationsDao
	 */
	private $translationsDao;

	/**
	 * @var TitleDictionary
	 */
	private $titleDictionary;

	/**
	 * @param DeepL $deepL
	 * @param TranslationWikitextConverter $wtConverter
	 * @param WikiPageFactory $wikiPageFactory
	 * @param TranslationsDao $translationsDao
	 * @param TitleDictionary $titleDictionary
	 */
	public function __construct(
		DeepL $deepL, TranslationWikitextConverter $wtConverter,
		WikiPageFactory $wikiPageFactory, TranslationsDao $translationsDao, TitleDictionary $titleDictionary
	) {
		$this->deepL = $deepL;
		$this->wtConverter = $wtConverter;
		$this->wikiPageFactory = $wikiPageFactory;
		$this->translationsDao = $translationsDao;
		$this->titleDictionary = $titleDictionary;

		$this->logger = new NullLogger();
	}

	/**
	 * @param LoggerInterface $logger
	 */
	public function setLogger( LoggerInterface $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Translates specified title (title text and title content).
	 *
	 * For title content translation logic is a bit more complicated, because that's a wikitext.
	 * Wikitext needs some pre-processing before sending to DeepL
	 * (like escaping syntax elements or translating of page links, namespaces etc.).
	 * See {@link TranslationWikitextConverter}
	 *
	 * @param Title $title
	 * @param string $targetLang
	 * @param bool $addToDictionary When <tt>true</tt> and dictionary does not have translation for that title yet -
	 * 		- add proposed by DeepL translation to the dictionary instantly. If <tt>false</tt> and/or
	 * 		dictionary has translation for that title - dictionary won't be changed. By default, equals <tt>true</tt>
	 * 		for backward compatibility.
	 *
	 * @return array Array with such structure:
	 * [
	 *        'title' => <translated_title_text>,
	 *        'wikitext' => <translated_title_wikitext>
	 * ]
	 *
	 * @throws Exception
	 * @see TranslatorTest::testTranslateTitle()
	 * @see TranslationWikitextConverter
	 */
	public function translateTitle( Title $title, string $targetLang, bool $addToDictionary = true ): array {
		$this->logger->debug( "Translator: starting translation of the title - '{$title->getPrefixedText()}'" );

		try {
			$contentWikitext = $this->convertForTranslation( $title, $targetLang );
		} catch ( Exception $e ) {
			throw new Exception( 'Converting text for translation failed. Error: ' . $e->getMessage() );
		}

		// Translate title text and content
		$status = $this->deepL->translate( $title, $contentWikitext, [ $targetLang ] );
		if ( !$status->isOk() ) {
			throw new Exception( 'DeepL translation failed. Error: ' . $status->getWikiText() );
		}

		$value = $status->getValue();
		if ( !isset( $value[$targetLang] ) ) {
			throw new Exception( 'Failed to translate content' );
		}
		$translate = $value[$targetLang];

		try {
			$wikitext = $this->convertBackFromTranslation( $translate );
		} catch ( Exception $e ) {
			throw new Exception(
				'Converting translation data back to wikitext failed. Error: ' . $e->getMessage()
			);
		}

		[ $translatedTitlePrefixedText, $dictionaryUsed ] = $this->wtConverter->getTitleText(
			$title, $translate['title'], $targetLang, $addToDictionary
		);

		// Check if such translation (considering namespace as well) is already linked to some other source
		// We need to do that as soon as there may be a case when few titles will have the same translation

		// So if some other title is already translated the same way -
		// - we should warn user about that and break the process, because otherwise we'll override some existing title.
		$this->checkIfTranslationExists( $title, $translatedTitlePrefixedText, $targetLang );

		return [
			'title' => $translatedTitlePrefixedText,
			'wikitext' => $wikitext,
			'dictionaryUsed' => $dictionaryUsed
		];
	}

	/**
	 * Do some "pre-translation processing" with {@link TranslationWikitextConverter} for specified wikitext
	 *
	 * @param Title $title
	 * @param string $targetLang Translation target language code
	 * @return string|null
	 * @throws LogicException
	 */
	private function convertForTranslation( Title $title, string $targetLang ): ?string {
		$wikiPage = $this->wikiPageFactory->newFromTitle( $title );
		$content = $wikiPage->getContent();
		if ( !$content instanceof TextContent ) {
			throw new LogicException( 'Cannot translate non-text content' );
		}
		$wikitext = $content->getText();

		// Here we specify only target lang, because converter considers wiki language as source lang
		// Probably we should change that in the future...
		return $this->wtConverter->preTranslationProcessing( $wikitext, $targetLang );
	}

	/**
	 * Transform HTML (which we got from DeepL) to wikitext.
	 * Do some "post-translation processing" with {@link TranslationWikitextConverter}
	 *
	 * @param array $translateData
	 * @return string|null
	 */
	private function convertBackFromTranslation( array $translateData ): ?string {
		$wikitext = $translateData['text'];
		$wikitext = html_entity_decode( $wikitext );

		$translateData['wikitext'] = $wikitext;
		return $this->wtConverter->postTranslationProcessing( $translateData );
	}

	/**
	 * Check if specified translation is already linked with some other source title.
	 * If that's it - throw an exception.
	 *
	 * @param Title $originalTitle
	 * @param string $translatedTitlePrefixedText
	 * @param string $targetLang
	 * @return void
	 *
	 * @throws Exception If translation which we got - already exists and is linked to some other source title
	 */
	private function checkIfTranslationExists(
		Title $originalTitle, string $translatedTitlePrefixedText, string $targetLang
	): void {
		// At first, we need to transform "title prefixed text" to "title prefixed DB key"
		// Because "translations table" holds "prefixed DB keys"
		$translatedTitleObj = Title::newFromText( $translatedTitlePrefixedText );

		$translatedTitlePrefixedDbKey = $translatedTitleObj->getPrefixedDBKey();

		$translationSource = $this->translationsDao->getSourceFromTarget( $translatedTitlePrefixedDbKey, $targetLang );
		if ( $translationSource !== null ) {
			// If source of translated title is the same which we are currently translating from - then all is OK
			// We'll just update this translation

			// Check not only "prefixed DB key" but source language as well.
			// So if "prefixed DB key" is the same but source language is different
			// from the one which we are currently translating from - throw an exception.
			if (
				$translationSource['key'] !== $originalTitle->getPrefixedDBkey() ||
				$translationSource['lang'] !== strtoupper( $this->deepL->extractSourceLanguage() )
			) {
				$errorText = Message::newFromKey( 'bs-translation-transfer-translation-exists' )
					->params( $originalTitle->getPrefixedDBkey() )->text();

				// Otherwise - throw an exception
				throw new Exception( $errorText );
			}
		}
	}
}
