<?php

namespace BlueSpice\TranslationTransfer\Pipeline;

use BlueSpice\TranslationTransfer\IDictionary;
use Exception;
use MWStake\MediaWiki\Component\DeepLTranslator\DeepLTranslator;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Post-processing step that translates registered template arguments in assembled wikitext.
 *
 * Uses a configurable registry ($bsgTranslateTransferTemplateArgs) to determine which
 * template arguments should be translated and how:
 * - "text" — translate as regular prose via DeepL (wikitext formatting preserved via InlineConverter)
 * - "title" — translate as a wiki page title via TitleDictionary (cache) → DeepL (fallback)
 *
 * Templates not in the registry are left untouched. Parser functions (e.g., {{#if:...}})
 * are always skipped.
 *
 * The tokenizer approach is ported from ComplexWikitextParser: a state machine that properly
 * handles nested templates, internal links inside arguments, and pipe separators.
 *
 * Known limitations:
 * - Only named arguments (key=value) are supported. Positional arguments cannot be
 *   registered because there's no key to match against.
 * - Template name matching is exact (case-sensitive after first character).
 *   Registry should use the canonical template name as displayed on the wiki.
 * - Leading/trailing whitespace around the template name is trimmed before registry lookup.
 *
 * Configuration example (in LocalSettings.php):
 *   $bsgTranslateTransferTemplateArgs = [
 *       'Hint box' => [ 'text' => 'text', 'heading' => 'text' ],
 *       'ButtonLink' => [ 'title' => 'title', 'label' => 'text' ],
 *   ];
 *
 * @see WikitextTranslator Where this is called as step 5 (after LinkTranslator)
 * @see InlineConverter Used internally for "text" type arg translation
 */
class TemplateTranslator implements LoggerAwareInterface {

	/**
	 * Tokenizer states.
	 */
	private const STATE_TEXT = 0;
	private const STATE_TEMPLATE = 1;
	private const STATE_INTERNAL_LINK = 2;

	/** @var array Template args registry: ['Template Name' => ['argName' => 'text'|'title']] */
	private $registry;

	/** @var DeepLTranslator */
	private $deepL;

	/** @var IDictionary */
	private $titleDictionary;

	/** @var LoggerInterface */
	private $logger;

	/** @var string */
	private $sourceLang;

	/** @var string */
	private $targetLang;

	/**
	 * Tokenizer state for the current parse run.
	 * @var int
	 */
	private $state;

	/** @var array Stack of open templates/links */
	private $stack;

	/** @var string Accumulated output */
	private $output;

	/**
	 * Collects all "text" type arg values that need DeepL translation.
	 * Each entry: ['templateIdx' => int, 'argIdx' => int, 'value' => string]
	 * @var array
	 */
	private $textArgQueue;

	/**
	 * @param array $registry Template args registry
	 * @param DeepLTranslator $deepL
	 * @param IDictionary $titleDictionary
	 */
	public function __construct(
		array $registry,
		DeepLTranslator $deepL,
		IDictionary $titleDictionary
	) {
		$this->registry = $registry;
		$this->deepL = $deepL;
		$this->titleDictionary = $titleDictionary;
		$this->logger = new NullLogger();
		$this->sourceLang = '';
		$this->targetLang = '';
	}

	/**
	 * @param LoggerInterface $logger
	 */
	public function setLogger( LoggerInterface $logger ): void {
		$this->logger = $logger;
	}

	/**
	 * Translate registered template arguments in assembled wikitext.
	 *
	 * @param string $wikitext Assembled wikitext
	 * @param string $sourceLang Source language code
	 * @param string $targetLang Target language code
	 * @return string Wikitext with translated template arguments
	 */
	public function translateTemplates( string $wikitext, string $sourceLang, string $targetLang ): string {
		if ( empty( $this->registry ) ) {
			return $wikitext;
		}

		$this->sourceLang = $sourceLang;
		$this->targetLang = $targetLang;
		$this->state = self::STATE_TEXT;
		$this->stack = [];
		$this->output = '';
		$this->textArgQueue = [];

		$tokens = $this->tokenize( $wikitext );
		foreach ( $tokens as $token ) {
			$this->handleToken( $token );
		}

		return $this->output;
	}

	/**
	 * Split wikitext into tokens: {{, }}, |, =, [[, ]], and text between them.
	 *
	 * @param string $wikitext
	 * @return string[]
	 */
	private function tokenize( string $wikitext ): array {
		$pattern = '/(\{\{|\}\}|\||\[\[|\]\])/';

		return preg_split(
			$pattern,
			$wikitext,
			-1,
			PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
		);
	}

	/**
	 * @param string $token
	 */
	private function handleToken( string $token ): void {
		switch ( $this->state ) {
			case self::STATE_TEXT:
				if ( $token === '{{' ) {
					$this->startTemplate();
				} else {
					$this->output .= $token;
				}
				break;

			case self::STATE_INTERNAL_LINK:
				if ( $token === ']]' ) {
					$this->appendToTop( ']]' );
					$this->closeLink();
				} else {
					$this->appendToTop( $token );
				}
				break;

			case self::STATE_TEMPLATE:
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
	 * Push a new template frame onto the stack.
	 */
	private function startTemplate(): void {
		$this->state = self::STATE_TEMPLATE;

		$this->stack[] = [
			'type' => 'template',
			// parts[0] = template name, then arguments
			'parts' => [ '' ]
		];
	}

	/**
	 * Start a new argument in the current template.
	 */
	private function newTemplateArg(): void {
		$top = &$this->stack[array_key_last( $this->stack )];
		$top['parts'][] = '';
	}

	/**
	 * Pop a template from the stack and emit its (possibly translated) output.
	 */
	private function closeTemplate(): void {
		$template = array_pop( $this->stack );

		$rebuilt = $this->processTemplate( $template );

		if ( empty( $this->stack ) ) {
			$this->output .= $rebuilt;
			$this->state = self::STATE_TEXT;
		} else {
			$this->appendToTop( $rebuilt );
			$this->state = $this->stackStateTop();
		}
	}

	/**
	 * Process a parsed template: translate registered args, leave others as-is.
	 *
	 * @param array $template Parsed template with 'parts' array
	 * @return string Rebuilt template wikitext
	 */
	private function processTemplate( array $template ): string {
		$parts = $template['parts'];
		$templateName = trim( array_shift( $parts ) );

		// Skip parser functions (e.g., #if, #switch, #invoke)
		if ( strpos( $templateName, '#' ) === 0 || strpos( $templateName, ':' ) !== false ) {
			return $this->rebuildTemplate( $templateName, $parts );
		}

		// Check if this template is in the registry
		if ( !isset( $this->registry[$templateName] ) ) {
			return $this->rebuildTemplate( $templateName, $parts );
		}

		$registeredArgs = $this->registry[$templateName];

		$translatedParts = [];
		foreach ( $parts as $arg ) {
			$translatedParts[] = $this->processArg( $arg, $registeredArgs );
		}

		return $this->rebuildTemplate( $templateName, $translatedParts );
	}

	/**
	 * Process a single template argument: translate if registered, leave as-is otherwise.
	 *
	 * @param string $arg Raw argument string (may contain "key=value" or just "value")
	 * @param array $registeredArgs Map of argName => 'text'|'title' for this template
	 * @return string Processed argument string
	 */
	private function processArg( string $arg, array $registeredArgs ): string {
		// Check for named argument (contains '=')
		$eqPos = strpos( $arg, '=' );
		if ( $eqPos === false ) {
			// Positional argument — not translatable via registry (no key to match)
			return $arg;
		}

		$key = substr( $arg, 0, $eqPos );
		$value = substr( $arg, $eqPos + 1 );

		$trimmedKey = trim( $key );
		if ( !isset( $registeredArgs[$trimmedKey] ) ) {
			// Not in registry — leave as-is
			return $arg;
		}

		$method = $registeredArgs[$trimmedKey];
		$trimmedValue = trim( $value );

		if ( $trimmedValue === '' ) {
			return $arg;
		}

		if ( $method === 'text' ) {
			$translated = $this->translateAsText( $trimmedValue );
		} elseif ( $method === 'title' ) {
			$translated = $this->translateAsTitle( $trimmedValue );
		} else {
			$this->logger->warning(
				'TemplateTranslator: unknown translation method "{method}" for arg "{key}"',
				[ 'method' => $method, 'key' => $trimmedKey ]
			);
			return $arg;
		}

		// Preserve original whitespace around the value
		$leadingSpace = '';
		$trailingSpace = '';
		if ( strlen( $value ) > 0 && $value[0] === ' ' ) {
			$leadingSpace = ' ';
		}
		if ( strlen( $value ) > 1 && $value[strlen( $value ) - 1] === ' ' ) {
			$trailingSpace = ' ';
		}

		return $key . '=' . $leadingSpace . $translated . $trailingSpace;
	}

	/**
	 * Translate a value as regular prose via DeepL.
	 *
	 * Runs the value through InlineConverter → DeepL → InlineConverter
	 * to properly handle wikitext formatting (bold, italic, links) within the arg value.
	 *
	 * @param string $value
	 * @return string Translated value
	 */
	private function translateAsText( string $value ): string {
		$inlineConverter = new InlineConverter();

		// Convert wikitext to HTML
		$html = $inlineConverter->wikitextToHtml( $value );

		// Skip if nothing translatable after opaque extraction
		if ( trim( strip_tags( $html ) ) === '' ) {
			return $value;
		}

		// Translate via DeepL
		$status = $this->deepL->translateText( $html, $this->sourceLang, $this->targetLang, [
			'tag_handling' => 'html'
		] );

		if ( !$status->isOK() ) {
			$this->logger->warning(
				'TemplateTranslator: DeepL translation failed for template arg value',
				[ 'value' => $value ]
			);
			return $value;
		}

		$translatedHtml = $status->getValue();

		// Convert HTML back to wikitext
		$translatedWikitext = $inlineConverter->htmlToWikitext( $translatedHtml );

		return $translatedWikitext;
	}

	/**
	 * Translate a value as a wiki page title using TitleDictionary, falling back to DeepL.
	 *
	 * @param string $value
	 * @return string Translated title
	 */
	private function translateAsTitle( string $value ): string {
		// Check dictionary cache
		$cached = $this->titleDictionary->get( $value, $this->targetLang );
		if ( $cached !== null ) {
			return $cached;
		}

		// Translate via DeepL
		$status = $this->deepL->translateText( $value, $this->sourceLang, $this->targetLang );
		if ( !$status->isOK() ) {
			$this->logger->warning(
				'TemplateTranslator: DeepL title translation failed for "{value}"',
				[ 'value' => $value ]
			);
			return $value;
		}

		$translated = $status->getValue();

		// Cache in dictionary if translation differs
		if ( $translated !== $value ) {
			try {
				$this->titleDictionary->insert( $value, $this->targetLang, $translated );
			} catch ( Exception $e ) {
				$this->logger->error(
					'TemplateTranslator: dictionary insert failed for "{title}": {error}',
					[ 'title' => $value, 'error' => $e->getMessage() ]
				);
			}
		}

		return $translated;
	}

	/**
	 * Rebuild a template from its name and argument parts.
	 *
	 * @param string $name Template name
	 * @param string[] $args Argument strings
	 * @return string Wikitext template invocation
	 */
	private function rebuildTemplate( string $name, array $args ): string {
		$result = '{{' . $name;

		foreach ( $args as $arg ) {
			$result .= '|' . $arg;
		}

		$result .= '}}';

		return $result;
	}

	/**
	 * Push a link frame onto the stack (to properly handle | inside [[...]]).
	 */
	private function startLink(): void {
		$this->state = self::STATE_INTERNAL_LINK;

		$this->stack[] = [
			'type' => 'link',
			'content' => '[['
		];
	}

	/**
	 * Pop a link from the stack and append its content to the parent.
	 */
	private function closeLink(): void {
		$link = array_pop( $this->stack );

		$this->appendToTop( $link['content'] );

		$this->state = $this->stackStateTop();
	}

	/**
	 * Append text to the current top-of-stack element.
	 *
	 * @param string $token
	 */
	private function appendToTop( string $token ): void {
		$top = &$this->stack[array_key_last( $this->stack )];

		if ( $top['type'] === 'template' ) {
			$latestPartKey = array_key_last( $top['parts'] );
			$top['parts'][$latestPartKey] .= $token;
		} else {
			$top['content'] .= $token;
		}
	}

	/**
	 * Determine the parser state based on the top of the stack.
	 *
	 * @return int
	 */
	private function stackStateTop(): int {
		if ( empty( $this->stack ) ) {
			return self::STATE_TEXT;
		}
		$top = $this->stack[array_key_last( $this->stack )];
		return $top['type'] === 'template' ? self::STATE_TEMPLATE : self::STATE_INTERNAL_LINK;
	}
}
