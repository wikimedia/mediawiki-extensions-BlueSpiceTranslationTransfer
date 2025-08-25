CREATE TABLE IF NOT EXISTS /*$wgDBprefix*/bs_tt_dictionary_title (
	tt_dt_source_ns_id INT NOT NULL,
	tt_dt_source_text VARCHAR(255) NOT NULL,
	tt_dt_source_normalized_text VARCHAR(255) NOT NULL,
	tt_dt_lang VARCHAR(10) NOT NULL,
	tt_dt_translation VARCHAR(255) NOT NULL,
	tt_dt_normalized_translation VARCHAR(255) NOT NULL,
	PRIMARY KEY (tt_dt_source_ns_id, tt_dt_source_text, tt_dt_lang)
) /*$wgDBTableOptions*/;
