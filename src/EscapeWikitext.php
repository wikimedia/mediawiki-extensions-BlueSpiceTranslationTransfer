<?php

namespace BlueSpice\TranslationTransfer;

use MediaWiki\Language\Language;
use MediaWiki\Title\Title;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @see \BlueSpice\TranslationTransfer\Tests\EscapeWikitextTest
 *
 * // TODO: Split functionality to separate wikitext "processors", add unit test for each of them
 */
class EscapeWikitext implements LoggerAwareInterface {

	/**
	 * @var LoggerInterface
	 */
	private $logger;

	/**
	 * @var Language
	 */
	private $enLang;

	/**
	 * @var Language
	 */
	private $contentLang;

	/**
	 * @var array
	 */
	private $lines;

	/**
	 * @var array
	 */
	private $wikitextMasks = [
		'[[' => '###DOUBLE_BRACKET_OPEN###',
		']]' => '###DOUBLE_BRACKET_CLOSE###',
		'[' => '###SINGLE_BRACKET_OPEN###',
		']' => '###SINGLE_BRACKET_CLOSE###',
		'\'\'' => '###ITALIC_FORMAT###',
		'\'\'\'' => '###BOLD_FORMAT###',
		'\'\'\'\'\'' => '###ITALIC_AND_BOLD_FORMAT###',
		'==' => '###HEADING_2###',
		'===' => '###HEADING_3###',
		'====' => '###HEADING_4###',
		'=====' => '###HEADING_5###',
		'======' => '###HEADING_6###'
	];

	/**
	 * Order really matters here!
	 * Especially in case with headings.
	 *
	 * @var string[]
	 */
	private $inlineFormattingWikitext = [
		'\'\'\'\'\'',
		'\'\'\'',
		'\'\'',
		'======',
		'=====',
		'====',
		'===',
		'=='
	];

	/**
	 * @param string $wikitext
	 * @param Language $enLang
	 * @param Language $contentLang
	 */
	public function __construct( string $wikitext, Language $enLang, Language $contentLang ) {
		$this->lines = explode( "\n", $wikitext );

		$this->enLang = $enLang;
		$this->contentLang = $contentLang;

		$this->logger = new NullLogger();
	}

	/**
	 * @param LoggerInterface $logger
	 */
	public function setLogger( LoggerInterface $logger ): void {
		$this->logger = $logger;
	}

	/**
	 * @return void
	 */
	public function process(): void {
		$startTime = microtime( true );
		$this->logger->debug( 'Start escaping wikitext' );

		$this->processFileLinks();

		$this->processComplexWikitext();

		$this->processTables();

		$this->processInternalLinks();
		$this->processExternalLinks();

		$this->processInlineFormatting();

		$this->processRedirects();

		$this->processMagicWords();

		$this->processNonTranslatedBlocks();

		$this->processLists();

		$this->processGallery();

		$this->unmaskWikitext();

		$runTime = microtime( true ) - $startTime;
		$this->logger->debug( 'End escaping wikitext in "' . $runTime . '" seconds' );
	}

	/**
	 * Wikitext after all transformations
	 *
	 * @return string
	 */
	public function getResultWikitext(): string {
		return implode( "\n", $this->lines );
	}

	/**
	 * Process files links, escape all image options (like 'thumb', '200x200px' and so on).
	 *
	 * Also translate all of them to English to work on any language after page translation.
	 *
	 * @return void
	 */
	private function processFileLinks() {
		$contentLangMagic = $this->contentLang->getMagicWords();
		$enMagic = $this->enLang->getMagicWords();

		$openBracketMask = $this->wikitextMasks['[['];
		$closeBracketMask = $this->wikitextMasks[']]'];

		foreach ( $this->lines as $index => &$line ) {
			$line = preg_replace_callback(
				// We already translated all NS_FILE links to English variant "File" previously
				// So now we can use that to reduce number of matches
				'/\[\[File:(.*?)]]/',
				static function ( $matches ) use ( $contentLangMagic, $enMagic, $openBracketMask, $closeBracketMask ) {
					$link = $matches[1];

					$mainBits = explode( '|', $link );
					$target = array_shift( $mainBits );

					if ( !empty( $mainBits ) ) {
						$options = [];

						foreach ( $mainBits as $bit ) {
							if ( strpos( $bit, '=' ) ) {
								[ $name, $value ] = explode( '=', $bit );

								$options[$name] = trim( $value );
							} else {
								$options[$bit] = null;
							}
						}

						$notTranslatedOptions = [];

						$alt = '';
						$label = '';

						foreach ( $options as $option => $value ) {
							$magicWordFound = false;

							foreach ( $contentLangMagic as $key => $synonyms ) {
								if ( strpos( $key, 'img_' ) !== 0 ) {
									continue;
								}

								if ( $magicWordFound ) {
									break;
								}

								foreach ( $synonyms as $synonym ) {
									if ( $magicWordFound ) {
										break;
									}

									if ( $value === null ) {
										// If that's an option without value - just compare it as it is
										if ( $option === $synonym ) {
											// Translate to English if possible
											if ( isset( $enMagic[$key] ) && isset( $enMagic[$key][1] ) ) {
												$option = $enMagic[$key][1];
											}

											$notTranslatedOptions[$option] = null;

											$magicWordFound = true;
										}

										// That's quite hard to recognize image width with synonym
										// So just check if option ends up with "px"
										if ( substr( $option, -2 ) === 'px' ) {
											$notTranslatedOptions[$option] = null;

											$magicWordFound = true;
										}
									} else {
										if ( !strpos( $synonym, '=' ) ) {
											continue;
										}

										$optionContentLangName = explode( '=', $synonym )[0];

										if ( $option === $optionContentLangName ) {
											// Translate to English if possible
											if ( isset( $enMagic[$key] ) && isset( $enMagic[$key][1] ) ) {
												$option = explode( '=', $enMagic[$key][1] )[0];
											}

											if ( $key === 'img_alt' ) {
												$alt = $value;
											} else {
												$notTranslatedOptions[$option] = $value;
											}

											$magicWordFound = true;
										}
									}
								}
							}

							// If that's just a value without option name, and there was no synonym found
							// Then it's most likely is a label, which should be translated
							if ( !$magicWordFound && $value === null ) {
								$label = $option;
							}
						}

						// Now compose string with options which we'll wrap into "deepl:ignore" tag
						$optionsString = '';
						foreach ( $notTranslatedOptions as $option => $value ) {
							// Add separator if that's not the first option
							if ( $optionsString ) {
								$optionsString .= '|';
							}

							if ( $value === null ) {
								$optionsString .= $option;
							} else {
								$optionsString .= "$option=$value";
							}
						}

						// And then add unwrapped label or/and alternative text
						$translatedString = '';
						if ( $alt ) {
							$translatedString .= "<deepl:ignore>alt=</deepl:ignore>$alt";
						}

						if ( $label ) {
							if ( $alt ) {
								$translatedString .= '<deepl:ignore>|</deepl:ignore>';
							}

							$translatedString .= $label;
						}

						if ( $optionsString && $translatedString ) {
							$optionsString .= '|';
						}

						if ( $translatedString ) {
							return "<deepl:ignore>{$openBracketMask}File:$target|$optionsString</deepl:ignore>$translatedString<deepl:ignore>{$closeBracketMask}</deepl:ignore>";
						} else {
							return "<deepl:ignore>{$openBracketMask}File:$target|$optionsString{$closeBracketMask}</deepl:ignore>";
						}
					}

					return "<deepl:ignore>{$openBracketMask}$link{$closeBracketMask}</deepl:ignore>";
				},
				$line
			);
		}
		unset( $line );
	}

	/**
	 * Look up and escape all structures wrapped into curly brackets: "magic words", templates, parser functions.
	 *
	 * @return void
	 */
	private function processComplexWikitext() {
		$wikitext = implode( "\n", $this->lines );

		// TODO: Bad approach, does not take in account nested structures
		// Also we probably should translate arguments values in some cases...
		// To change!
		$wikitext = preg_replace( '#{{.*?}}#sm', '<deepl:ignore>$0</deepl:ignore>', $wikitext );

		$this->lines = explode( "\n", $wikitext );
	}

	/**
	 * Escape MediaWiki tables syntax elements.
	 *
	 * Wrap all tables elements in the wikitext in the "<deepl:ignore>" tag.
	 * Example:
	 * {|
	 * |-
	 * | Some header 1
	 * | Some header 2
	 * |-
	 * | Some content 1
	 * | Some content 2
	 * | -
	 * | Some content 3
	 * | Some content 4
	 * |}
	 *
	 * Nested tables are not supported:
	 * https://community.fandom.com/wiki/Help:Avoiding_nested_tables
	 *
	 * @return void
	 */
	private function processTables(): void {
		$isTable = false;

		foreach ( $this->lines as $index => &$line ) {
			if ( strpos( $line, '{|' ) === 0 ) {
				// If we found "{|" - then we start processing the table
				$isTable = true;

				// We should wrap first table line completely, as soon as it may also contain some CSS classes/styles
				$line = '<deepl:ignore>' . $line . '</deepl:ignore>';

				continue;
			}

			if ( strpos( $line, '|}' ) === 0 ) {
				$isTable = false;

				$line = '<deepl:ignore>' . $line . '</deepl:ignore>';

				continue;
			}

			if ( $isTable ) {
				// Row separator
				if ( strpos( $line, '|-' ) === 0 ) {
					$line = '<deepl:ignore>' . $line . '</deepl:ignore>';

					continue;
				}

				// Table caption
				if ( strpos( $line, '|+' ) === 0 ) {
					$line = preg_replace(
						'/^\|\+(.*?$)/',
						'<deepl:ignore>|+</deepl:ignore>$1',
						$line,
						1
					);

					continue;
				}

				// Regular cell with some content
				if ( strpos( $line, '|' ) === 0 ) {
					$line = preg_replace(
						'/^\|(.*?$)/',
						'<deepl:ignore>|</deepl:ignore>$1',
						$line,
						1
					);

					// Also take care of potential "||" cells separator,
					// if there are few cells defined on the same line
					$line = str_replace(
						'||',
						'<deepl:ignore>||</deepl:ignore>',
						$line
					);

					continue;
				}

				// Header cell
				if ( strpos( $line, '!' ) === 0 ) {
					$line = preg_replace(
						'/^!(.*?$)/',
						'<deepl:ignore>!</deepl:ignore>$1',
						$line,
						1
					);

					// Also take care of potential "!!" header cell separator,
					// if there are few header cells defined on the same line
					$line = str_replace(
						'!!',
						'<deepl:ignore>!!</deepl:ignore>',
						$line
					);
				}
			}
		}
		unset( $line );
	}

	/**
	 * Escape MediaWiki internal links, as soon as they are already translated previously.
	 * See {@link TranslationWikitextConverter::translateTitlesInLinks} and
	 * {@link TranslationWikitextConverter::translateNamespacesInLinks}.
	 *
	 * Also, DeepL sometimes "loses" brackets.
	 * Example: "[[Category:HeKo Message]]" -> "[Category:HeKo Message]]".
	 *
	 * Cases with labels like [[Media:Example.jpg|file label]] are also taken into account.
	 * In such cases we translate label, but do not translate target.
	 *
	 * @return void
	 */
	private function processInternalLinks(): void {
		$openBracketMask = $this->wikitextMasks['[['];
		$closeBracketMask = $this->wikitextMasks[']]'];

		foreach ( $this->lines as $index => &$line ) {
			$line = preg_replace_callback(
				'/\[\[(.*?)]]/',
				static function ( $matches ) use ( $openBracketMask, $closeBracketMask ) {
					$link = $matches[1];

					$mainBits = explode( '|', $link );
					$target = array_shift( $mainBits );

					$title = Title::newFromText( $target );
					if ( !$title ) {
						// Not a correct title, probably semantic property?
						// Just wrap whole in that case

						// TODO: If we'll decide to actually translate semantic properties - do no wrap them
						return "<deepl:ignore>{$openBracketMask}$link{$closeBracketMask}</deepl:ignore>";
					}

					// File links with possible arbitrary display options are processed in separate method
					// File links like "[[:File:...]]" do not hold arbitrary display options, so they are processed here
					if ( $title->getNamespace() === NS_FILE && strpos( $target, ':' ) !== 0 ) {
						return $matches[0];
					}

					// We do not need to escape link parts for files, because they may have some arbitrary options
					// Files are processed here in "EscapeWikitext::processFileLinks"
					// Also preserve such links without changes: [[Example property::link| ]]
					if ( !empty( $mainBits ) & !( count( $mainBits ) === 1 && $mainBits[0] === ' ' ) ) {
						$label = implode( '|', $mainBits );

						return "<deepl:ignore>{$openBracketMask}$target|</deepl:ignore>$label<deepl:ignore>{$closeBracketMask}</deepl:ignore>";
					}

					return "<deepl:ignore>{$openBracketMask}$link{$closeBracketMask}</deepl:ignore>";
				},
				$line
			);
		}
		unset( $line );
	}

	/**
	 * Escape MediaWiki external links to make sure that targets won't be changed
	 * Link example: "[https://mediawiki.org MediaWiki]"
	 *
	 * @return void
	 */
	private function processExternalLinks(): void {
		$openBracketMask = $this->wikitextMasks['['];
		$closeBracketMask = $this->wikitextMasks[']'];

		foreach ( $this->lines as $index => &$line ) {
			$line = preg_replace_callback(
				'/\[(.*?)]/',
				static function ( $matches ) use ( $openBracketMask, $closeBracketMask ) {
					$link = $matches[1];

					$mainBits = explode( ' ', $link );
					$target = array_shift( $mainBits );

					if ( !empty( $mainBits ) ) {
						$label = implode( ' ', $mainBits );

						return "<deepl:ignore>{$openBracketMask}$target </deepl:ignore>$label<deepl:ignore>$closeBracketMask</deepl:ignore>";
					}

					return "<deepl:ignore>{$openBracketMask}$link{$closeBracketMask}</deepl:ignore>";
				},
				$line
			);
		}
		unset( $line );
	}

	private function processInlineFormatting(): void {
		foreach ( $this->lines as $index => &$line ) {
			foreach ( $this->inlineFormattingWikitext as $inlineFormatWikitext ) {
				// If that's a heading - we should also check that it's start of the line
				// Otherwise it won't be a valid heading
				if ( strpos( $inlineFormatWikitext, '=' ) === 0 ) {
					$pattern = "/^$inlineFormatWikitext(.*?)$inlineFormatWikitext/";
				} else {
					$pattern = "/$inlineFormatWikitext(.*?)$inlineFormatWikitext/";
				}

				$wikitextMask = $this->wikitextMasks[$inlineFormatWikitext];

				$line = preg_replace(
					$pattern,
					"<deepl:ignore>$wikitextMask</deepl:ignore>$1<deepl:ignore>$wikitextMask</deepl:ignore>",
					$line
				);
			}
		}
		unset( $line );
	}

	private function processRedirects(): void {
		foreach ( $this->lines as $index => &$line ) {
			$line = str_ireplace(
				'#REDIRECT',
				'<deepl:ignore>#REDIRECT</deepl:ignore>',
				$line
			);
		}
		unset( $line );
	}

	private function processMagicWords(): void {
		// TODO: Implement
	}

	private function processLists(): void {
		foreach ( $this->lines as $index => &$line ) {
			// If we find any of those symbols at the start of the line - then most likely we are in the list
			// Look for the latest "list symbol" and wrap all of them in the "deepl:ignore" tag
			$line = preg_replace( '#^[\*\#\:\;]+#', '<deepl:ignore>$0</deepl:ignore>', $line );
		}
		unset( $line );
	}

	/**
	 * We should make sure that file names inside "<gallery>" tags will leave unchanged.
	 * They need a bit other processing as soon as files in the gallery do not use internal links syntax.
	 *
	 * Logic for looking up and working with galleries is partly C&P from here:
	 * {@link TranslationWikitextConverter::translateGallery()}
	 *
	 * @return void
	 */
	private function processGallery(): void {
		$wikitext = implode( "\n", $this->lines );

		$matches = [];
		preg_match_all( '/(<gallery>|<gallery.*?>)(.*?)<\/gallery>/sm', $wikitext, $matches );
		foreach ( $matches[2] as $index => $match ) {
			$match = trim( $match );
			$lines = explode( "\n", $match );

			$newLines = '';
			foreach ( $lines as $line ) {
				$mainBits = explode( '|', $line );
				$target = array_shift( $mainBits );

				if ( !empty( $mainBits ) ) {
					$label = implode( '|', $mainBits );

					$newLines .= "<deepl:ignore>$target|</deepl:ignore>$label\n";
				} else {
					$newLines .= "<deepl:ignore>$target</deepl:ignore>\n";
				}
			}
			$newText = "{$matches[1][$index]}\n{$newLines}</gallery>";

			$wikitext = str_replace(
				$matches[0][$index],
				$newText,
				$wikitext
			);
		}

		$this->lines = explode( "\n", $wikitext );
	}

	private function processNonTranslatedBlocks(): void {
		$tagsWithNonTranslatedContent = [
			'code',
			'inputbox',
			'syntaxhighlight',
			'math'
		];

		foreach ( $this->lines as $index => &$line ) {
			foreach ( $tagsWithNonTranslatedContent as $tag ) {
				$line = str_replace(
					"<$tag>",
					"<deepl:ignore><$tag>",
					$line
				);

				$line = str_replace(
					"</$tag>",
					"</$tag></deepl:ignore>",
					$line
				);
			}
		}
		unset( $line );
	}

	private function unmaskWikitext(): void {
		$masksToSearch = array_values( $this->wikitextMasks );
		$wikitext = array_keys( $this->wikitextMasks );

		foreach ( $this->lines as $index => &$line ) {
			$line = str_replace(
				$masksToSearch,
				$wikitext,
				$line
			);
		}
		unset( $line );
	}
}
