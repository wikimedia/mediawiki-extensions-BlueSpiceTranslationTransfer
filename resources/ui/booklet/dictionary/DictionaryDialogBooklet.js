translationTransfer.ui.booklet.dictionary.DictionaryDialogBooklet = function ( cfg ) {
	translationTransfer.ui.booklet.dictionary.DictionaryDialogBooklet.parent.call( this, cfg );

	this.targetLanguage = cfg.targetLanguage;

	this.sourceText = cfg.sourceText;
	this.translationText = cfg.translationText;

	this.affectedPagesHref = cfg.affectedPagesHref;

	// For API call, we need prefixed DB keys there
	this.sourcePrefixedDbKey = cfg.sourcePrefixedDbKey;
	this.targetPrefixedDbKey = cfg.targetPrefixedDbKey;

	this.makePages();
};

OO.inheritClass( translationTransfer.ui.booklet.dictionary.DictionaryDialogBooklet, OO.ui.BookletLayout );

translationTransfer.ui.booklet.dictionary.DictionaryDialogBooklet.prototype.makePages = function () {
	this.pages = {
		translationChange: new translationTransfer.ui.booklet.dictionary.page.TranslationChange( {
			sourceText: this.sourceText,
			translationText: this.translationText
		} ),
		changeConfirm: new translationTransfer.ui.booklet.dictionary.page.ChangeConfirm( {
			translationText: this.translationText,
			affectedPagesHref: this.affectedPagesHref,

			targetLanguage: this.targetLanguage,

			sourcePrefixedDbKey: this.sourcePrefixedDbKey,
			targetPrefixedDbKey: this.targetPrefixedDbKey
		} )
	};

	this.addPages( Object.values( this.pages ) );
};
