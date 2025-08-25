CREATE TABLE IF NOT EXISTS /*$wgDBprefix*/bs_tt_glossary (
	tt_glossary_id VARCHAR(40) NOT NULL,
	tt_glossary_lang VARCHAR(10) NOT NULL,
	PRIMARY KEY (tt_glossary_id)
) /*$wgDBTableOptions*/;
