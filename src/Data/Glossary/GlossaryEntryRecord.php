<?php

namespace BlueSpice\TranslationTransfer\Data\Glossary;

use MWStake\MediaWiki\Component\DataStore\Record;

class GlossaryEntryRecord extends Record {

	public const SOURCE_TEXT = 'source';

	public const SOURCE_NORMALIZED = 'source_normalized';

	public const TRANSLATION_TEXT = 'translation';

	public const TRANSLATION_NORMALIZED = 'translation_normalized';
}
