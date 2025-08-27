translationTransfer.ui.booklet.dictionary.page.TranslationChange = function ( cfg ) {
	translationTransfer.ui.booklet.dictionary.page.TranslationChange.parent.call(
		this, 'translationChange', cfg
	);
	OO.EventEmitter.call( this );

	this.sourceText = cfg.sourceText;
	this.translationText = cfg.translationText;

	this.makeSourceLabel();
	this.makeInput();
};

OO.inheritClass( translationTransfer.ui.booklet.dictionary.page.TranslationChange, OO.ui.PageLayout );
OO.mixinClass( translationTransfer.ui.booklet.dictionary.page.TranslationChange, OO.EventEmitter );

translationTransfer.ui.booklet.dictionary.page.TranslationChange.prototype.makeSourceLabel = function () {
	const sourceLabelMsg = mw.message(
		'bs-translation-transfer-dictionary-edit-dialog-change-translation-label',
		this.sourceText
	).parse();

	const sourceLabelWidget = new OO.ui.LabelWidget( {
		$element: $( '<p>' )
	} );
	sourceLabelWidget.$element.html( sourceLabelMsg );

	this.$element.append( sourceLabelWidget.$element );
};

translationTransfer.ui.booklet.dictionary.page.TranslationChange.prototype.makeInput = function () {
	// We should "cut off" namespace for new translation input,
	// as soon that namespace cannot be changed in the dictionary
	let translationInputValue = this.translationText;
	if ( this.translationText.indexOf( ':' ) > 0 ) {
		const inputTextBits = this.translationText.split( ':', 2 );
		translationInputValue = inputTextBits[ 1 ];
	}

	const newTranslationInput = new OO.ui.InputWidget( {
		name: 'translation',
		value: translationInputValue
	} );

	newTranslationInput.$element.on( 'change', ( e ) => {
		const val = $( e.target ).val();
		if ( val ) {
			this.emit( 'inputValueChanged', val );
		}
	} );

	this.$element.append( newTranslationInput.$element );
};
