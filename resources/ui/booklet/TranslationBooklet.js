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
			languages: this.languages
		} ),
		translation: new translationTransfer.ui.TranslationPageLayout(),
		targetTitle: new translationTransfer.ui.TargetTitlePageLayout(),
		preview: new translationTransfer.ui.PreviewPageLayout(),
		transfer: new translationTransfer.ui.TransferPageLayout()
	};

	this.addPages( Object.values( this.pages ) );
};
