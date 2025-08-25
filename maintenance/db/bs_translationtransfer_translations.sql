CREATE TABLE IF NOT EXISTS /*_*/bs_translationtransfer_translations (
    `tt_translations_source_prefixed_title_key` VARCHAR( 255 ) NOT NULL,
    `tt_translations_source_normalized_title` VARCHAR( 255 ) NOT NULL,
    `tt_translations_source_lang` VARCHAR( 10 ) NOT NULL,
    `tt_translations_target_prefixed_title_key` VARCHAR( 255 ) NOT NULL,
	`tt_translations_target_normalized_title` VARCHAR( 255 ) NOT NULL,
    `tt_translations_target_lang` VARCHAR( 10 ) NOT NULL,
    `tt_translations_release_date` VARCHAR( 15 ) NOT NULL,
    `tt_translations_source_last_change_date` VARCHAR( 15 ) NOT NULL,
	PRIMARY KEY (tt_translations_target_prefixed_title_key, tt_translations_target_lang)
) /*$wgDBTableOptions*/;

CREATE INDEX bs_translationtransfer_translations_source_normalized_title ON /*_*/bs_translationtransfer_translations (tt_translations_source_normalized_title);
CREATE INDEX bs_translationtransfer_translations_target_normalized_title ON /*_*/bs_translationtransfer_translations (tt_translations_target_normalized_title);
CREATE INDEX bs_translationtransfer_translations_source_lang ON /*_*/bs_translationtransfer_translations (tt_translations_source_lang);
CREATE INDEX bs_translationtransfer_translations_target_lang ON /*_*/bs_translationtransfer_translations (tt_translations_target_lang);
CREATE INDEX bs_translationtransfer_translations_release_date ON /*_*/bs_translationtransfer_translations (tt_translations_release_date);
