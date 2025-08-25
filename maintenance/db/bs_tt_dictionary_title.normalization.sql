ALTER TABLE /*$wgDBprefix*/bs_tt_dictionary_title
	ADD `tt_dt_source_normalized_text` VARCHAR(255) NOT NULL,
	ADD `tt_dt_normalized_translation` VARCHAR(255) NOT NULL;
