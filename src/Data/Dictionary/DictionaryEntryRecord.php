<?php

namespace BlueSpice\TranslationTransfer\Data\Dictionary;

use MWStake\MediaWiki\Component\DataStore\Record;

class DictionaryEntryRecord extends Record {

	public const NS_ID = 'ns_id';

	public const SOURCE_PREFIXED_TEXT = 'source';

	public const SOURCE_PREFIXED_DB_KEY = 'source_prefixed_db_key';

	public const SOURCE_PAGE_LINK = 'source_page_link';

	public const SOURCE_NORMALIZED = 'source_normalized';

	public const TRANSLATION_PREFIXED_TEXT = 'translation';

	public const TRANSLATION_PREFIXED_DB_KEY = 'translation_prefixed_db_key';

	public const TRANSLATION_AFFECTED_PAGES_LINK = 'affected_pages_link';

	public const TRANSLATION_PAGE_LINK = 'translation_page_link';

	public const TRANSLATION_NORMALIZED = 'translation_normalized';
}
