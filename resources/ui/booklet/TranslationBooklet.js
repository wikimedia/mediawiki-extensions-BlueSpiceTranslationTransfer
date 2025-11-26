bs.util.registerNamespace( 'translationTransfer.ui' );

translationTransfer.ui.TranslationBooklet = function ( cfg ) {
	translationTransfer.ui.TranslationBooklet.parent.call( this, cfg );
	this.languages = cfg.languages;

	this.makePages();
};

OO.inheritClass( translationTransfer.ui.TranslationBooklet, OO.ui.BookletLayout );

translationTransfer.ui.TranslationBooklet.prototype.makePages = function () {
	this.pages = {
		languageSelection: new translationTransfer.ui.LanguageSelectionPageLayout( {
			languages: this.languages,
			expanded: false
		} ),
		translation: new translationTransfer.ui.TranslationPageLayout( {
			expanded: false
		} ),
		targetTitle: new translationTransfer.ui.TargetTitlePageLayout( {
			expanded: false
		} ),
		preview: new translationTransfer.ui.PreviewPageLayout( {
			expanded: false
		} ),
		transfer: new translationTransfer.ui.TransferPageLayout( {
			expanded: false
		} )
	};

	this.addPages( Object.values( this.pages ) );
};
