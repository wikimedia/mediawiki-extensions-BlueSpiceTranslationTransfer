<?php

namespace BlueSpice\TranslationTransfer;

use BlueSpice\TranslationTransfer\Dictionary\TitleDictionary;

/**
 * Wraps complex wikitext structures into "<deepl:ignore>" tag.
 *
 * By complex wikitext structures is meant:
 * * Templates (the most common and tricky case)
 * * Magic words
 * * Parser functions
 *
 * For now, we consider only templates as the most common use case.
 * Also are considered:
 * * Nested templates.
 * * Internal links in arguments values (we have to process internal links because they also use "|" as separator).
 * 		So if we will not take in account internal links when processing templates - link argument separator ( | )
 * 		will be recognized as template argument separator, which is wrong.
 */
class ComplexWikitextParser {

	/**
	 * States
	 */
	public const TEXT = 0;
	public const TEMPLATE = 1;
	public const INTERNAL_LINK = 2;

	/**
	 * @var array
	 */
	private $wikitextMasks = [
		'{{' => '###TEMPLATE_OPEN###',
		'}}' => '###TEMPLATE_CLOSE###',
	];

	/**
	 * Templates which arguments should be translated.
	 * * "text" - regular DeepL translation.
	 * * "title" - translation using "title dictionary"
	 *
	 * @var array[]
	 */
	private $templatesToTranslate = [
		'Hint box' => [
			'Note text' => 'text'
		],
		'Hinweisbox' => [
			'Note text' => 'text'
		],
		'ButtonLink' => [
			'label' => 'text',
			'target' => 'title'
		]
	];

	/**
	 * @var TitleDictionary
	 */
	private $titleDictionary;

	/**
	 * @var string
	 */
	private $targetLang;

	/**
	 * @var int
	 */
	private $state;

	/**
	 * @var array
	 */
	private $stack;

	/**
	 * @var string
	 */
	private $output;

	/**
	 * @param string $targetLang
	 */
	public function __construct( string $targetLang ) {
		$this->state = self::TEXT;
		$this->stack = [];
		$this->output = '';

		// For instant titles translation using title dictionary.
		$this->titleDictionary = TitleDictionary::factory();
		$this->targetLang = $targetLang;
	}

	/**
	 * @param string $wikitext
	 * @return string
	 */
	public function parse( string $wikitext ): string {
		$tokens = $this->tokenize( $wikitext );

		foreach ( $tokens as $token ) {
			$this->handleToken( $token );
		}

		return $this->output;
	}

	/**
	 * @param string $wikitext
	 * @return array
	 */
	private function tokenize( string $wikitext ): array {
		// Split wikitext by such tokens: {{, }}, |, =, [[, ]]
		// We need to consider tokens for internal wiki links syntax because they also use "|" as separator
		$pattern = '/(\{\{|\}\}|\||=|\[\[|\]\])/';

		return preg_split(
			$pattern,
			$wikitext,
			-1,
			PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
		);
	}

	/**
	 * @param string $token
	 * @return void
	 */
	private function handleToken( string $token ): void {
		switch ( $this->state ) {
			case self::TEXT:
				if ( $token === '{{' ) {
					$this->startTemplate();
				} else {
					$this->output .= $token;
				}
				break;

			case self::INTERNAL_LINK:
				if ( $token === ']]' ) {
					$this->appendToTop( ']]' );
					$this->closeLink();
				} else {
					$this->appendToTop( $token );
				}

				break;

			case self::TEMPLATE:
				if ( $token === '{{' ) {
					$this->startTemplate();
				} elseif ( $token === '}}' ) {
					$this->closeTemplate();
				} elseif ( $token === '[[' ) {
					$this->startLink();
				} elseif ( $token === '|' ) {
					$this->newTemplateArg();
				} else {
					$this->appendToTop( $token );
				}

				break;
		}
	}

	/**
	 * @return void
	 */
	private function startTemplate(): void {
		$this->state = self::TEMPLATE;

		$this->stack[] = [
			'type' => 'template',
			// [0] - template name, then arguments
			'parts' => [ '' ]
		];
	}

	/**
	 * @return void
	 */
	private function newTemplateArg(): void {
		$top = &$this->stack[ array_key_last( $this->stack ) ];

		$top['parts'][] = '';
	}

	/**
	 * @return void
	 */
	private function closeTemplate(): void {
		$template = array_pop( $this->stack );

		$wrapped = $this->wrapTemplate( $template );
		if ( empty( $this->stack ) ) {
			$this->output .= $wrapped;

			$this->state = self::TEXT;
		} else {
			$this->appendToTop( $wrapped );

			$this->state = $this->stackStateTop();
		}
	}

	/**
	 * @param array $template
	 * @return string
	 */
	private function wrapTemplate( array $template ): string {
		// We still need to "mask" curly brackets after processing template, because
		// we still process some other "magic words" and "parser functions" cases
		// in "EscapeWikitext" class
		// TODO: Once all such cases will be covered in current class - there will be no point in masking anymore
		$openBracketMask = $this->wikitextMasks['{{'];
		$closeBracketMask = $this->wikitextMasks['}}'];

		$out = "<deepl:ignore>$openBracketMask";

		$parts = $template['parts'];

		$templateName = trim( array_shift( $parts ) );
		// Template name
		$out .= $templateName;

		foreach ( $parts as $arg ) {
			if ( str_contains( $arg, '=' ) ) {
				[ $key, $value ] = explode( '=', $arg, 2 );

				// Check if we need to translate this argument or not.
				// If we need to translate that specific argument value,
				// then close "<deepl:ignore>" tag before this value,
				// and open again afterwards.
				if ( isset( $this->templatesToTranslate[ $templateName ][ $key ] ) ) {
					$translationMethod = $this->templatesToTranslate[ $templateName ][ $key ];
					if ( $translationMethod === 'text' ) {
						// Regular DeepL translation.
						// Just take this argument's value out from "<deepl:ignore>" tag,
						// and DeepL will do it on its own.
						$out .= '|' . trim( $key ) . '=</deepl:ignore>' . trim( $value ) . '<deepl:ignore>';
					} elseif ( $translationMethod === 'title' ) {
						// Argument value is a wiki title, so should be translated using dictionary

						$value = $this->tryTranslateTitle( $value );

						$out .= '|' . trim( $key ) . '=' . $value;
					}
				} else {
					// No need to translate this argument, so just output it as it is
					$out .= '|' . trim( $key ) . '=' . trim( $value );
				}
			} else {
				// There is just a value, without argument name
				$out .= '|' . trim( $arg );
			}
		}

		$out .= "$closeBracketMask</deepl:ignore>";

		return $out;
	}

	/**
	 * If there is no translation in the dictionary - do not translate title.
	 *
	 * TODO: Should we translate with DeepL if did not find translation in the dictionary?
	 *
	 * @param string $title
	 * @return string
	 */
	private function tryTranslateTitle( string $title ): string {
		$translation = $this->titleDictionary->get( $title, $this->targetLang );

		if ( $translation !== null ) {
			return $translation;
		} else {
			return $title;
		}
	}

	/**
	 * @return void
	 */
	private function startLink(): void {
		$this->state = self::INTERNAL_LINK;

		$this->stack[] = [
			'type' => 'link',
			'content' => '[['
		];
	}

	/**
	 * @return void
	 */
	private function closeLink(): void {
		$link = array_pop( $this->stack );

		$this->appendToTop( $link['content'] );

		$this->state = $this->stackStateTop();
	}

	/**
	 * @param string $token
	 * @return void
	 */
	private function appendToTop( string $token ): void {
		$top = &$this->stack[ array_key_last( $this->stack ) ];

		if ( $top['type'] === 'template' ) {
			$latestPartKey = array_key_last( $top['parts'] );

			$top['parts'][$latestPartKey] .= $token;
		} else {
			$top['content'] .= $token;
		}
	}

	/**
	 * @return int
	 */
	private function stackStateTop(): int {
		if ( empty( $this->stack ) ) {
			return self::TEXT;
		} else {
			return $this->stack[ array_key_last( $this->stack ) ]['type'] === 'template' ? self::TEMPLATE : self::INTERNAL_LINK;
		}
	}
}
