CREATE TABLE IF NOT EXISTS /*$wgDBprefix*/bs_tt_glossary_entries (
	tt_ge_source_text VARCHAR(255) NOT NULL,
	tt_ge_source_normalized_text VARCHAR(255) NOT NULL,
	tt_ge_lang VARCHAR(10) NOT NULL,
	tt_ge_translation VARCHAR(255) NOT NULL,
	tt_ge_normalized_translation VARCHAR(255) NOT NULL,
	PRIMARY KEY (tt_ge_source_text, tt_ge_lang)
) /*$wgDBTableOptions*/;
