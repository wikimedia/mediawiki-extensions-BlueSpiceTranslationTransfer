<?php

namespace BlueSpice\TranslationTransfer\Pipeline;

/**
 * A single translatable unit extracted from wikitext by WikitextSegmenter.
 *
 * Each segment has:
 * - A unique ID (sequential integer, 0-based)
 * - A type indicating its structural role (heading, paragraph, table cell, etc.)
 * - Source text (the original wikitext content to translate)
 * - A PUA marker (Unicode Private Use Area character pair wrapping a unique ID string)
 *   used as a placeholder in the skeleton
 * - Translated text (set after DeepL translation, consumed by SkeletonAssembler)
 *
 * The PUA marker format is: U+E000 + "PH_{id}" + U+E001
 * These characters never appear in normal text, ensuring unique replacements.
 */
class Segment {

	public const TYPE_HEADING = 'heading';
	public const TYPE_PARAGRAPH = 'paragraph';
	public const TYPE_LIST_ITEM = 'list-item';
	public const TYPE_TABLE_CELL = 'table-cell';
	public const TYPE_TABLE_CAPTION = 'table-caption';
	public const TYPE_GALLERY_CAPTION = 'gallery-caption';

	/** @var int */
	private $id;

	/** @var string */
	private $type;

	/** @var int|null */
	private $level;

	/** @var string */
	private $sourceText;

	/** @var string */
	private $marker;

	/** @var string|null */
	private $translatedText;

	/**
	 * @param int $id
	 * @param string $type
	 * @param string $sourceText
	 * @param int|null $level
	 */
	public function __construct( int $id, string $type, string $sourceText, ?int $level = null ) {
		$this->id = $id;
		$this->type = $type;
		$this->sourceText = $sourceText;
		$this->level = $level;
		$this->marker = "\u{E000}PH_{$id}\u{E001}";
		$this->translatedText = null;
	}

	/**
	 * @return int
	 */
	public function getId(): int {
		return $this->id;
	}

	/**
	 * @return string
	 */
	public function getType(): string {
		return $this->type;
	}

	/**
	 * @return int|null
	 */
	public function getLevel(): ?int {
		return $this->level;
	}

	/**
	 * @return string
	 */
	public function getSourceText(): string {
		return $this->sourceText;
	}

	/**
	 * @return string
	 */
	public function getMarker(): string {
		return $this->marker;
	}

	/**
	 * @return string|null
	 */
	public function getTranslatedText(): ?string {
		return $this->translatedText;
	}

	/**
	 * @param string $text
	 */
	public function setTranslatedText( string $text ): void {
		$this->translatedText = $text;
	}

	/**
	 * @return bool
	 */
	public function isTranslatable(): bool {
		$stripped = trim( $this->sourceText );
		return $stripped !== '';
	}
}
