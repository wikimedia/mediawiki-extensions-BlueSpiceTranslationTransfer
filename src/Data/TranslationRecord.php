<?php

namespace BlueSpice\TranslationTransfer\Data;

use MWStake\MediaWiki\Component\DataStore\Record;

class TranslationRecord extends Record {
	public const SOURCE_LANGUAGE = 'source_lang';

	public const SOURCE_PAGE_PREFIXED_TITLE_KEY = 'source_page_prefixed_title_key';

	public const SOURCE_PAGE_NORMALIZED_TEXT = 'source_page_normalized_title';

	public const SOURCE_PAGE_LINK = 'source_page_link';

	public const TARGET_LANGUAGE = 'target_lang';

	public const TARGET_PAGE_PREFIXED_TITLE_KEY = 'target_page_prefixed_title_key';

	public const TARGET_PAGE_NORMALIZED_TEXT = 'target_page_normalized_title';

	public const TARGET_PAGE_LINK = 'target_page_link';

	public const RELEASE_TIMESTAMP = 'release_ts';

	public const SOURCE_LAST_CHANGE_TIMESTAMP = 'source_last_change_ts';

	public const TARGET_LAST_CHANGE_TIMESTAMP = 'target_last_change_ts';

	public const RELEASE_TIMESTAMP_FORMATTED = 'release_formatted';

	public const SOURCE_LAST_CHANGE_TIMESTAMP_FORMATTED = 'source_last_change_formatted';

	public const TARGET_LAST_CHANGE_TIMESTAMP_FORMATTED = 'target_last_change_formatted';
}
