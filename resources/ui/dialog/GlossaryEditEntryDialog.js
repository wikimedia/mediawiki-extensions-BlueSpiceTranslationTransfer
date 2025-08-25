translationTransfer.ui.dialog.GlossaryEditEntryDialog = function ( cfg ) {
	cfg = cfg || {};

	translationTransfer.ui.dialog.GlossaryEditEntryDialog.parent.call( this, cfg );

	this.sourceText = cfg.sourceText || '';
	this.translationText = cfg.translationText || '';
	this.targetLanguage = cfg.targetLanguage || false;

	this.newTranslationText = '';
};

OO.inheritClass( translationTransfer.ui.dialog.GlossaryEditEntryDialog, OO.ui.ProcessDialog );

translationTransfer.ui.dialog.GlossaryEditEntryDialog.static.name = 'glossaryEditDialog';
translationTransfer.ui.dialog.GlossaryEditEntryDialog.static.title = mw.message( 'bs-translation-transfer-glossary-edit-dialog-title' ).text();
translationTransfer.ui.dialog.GlossaryEditEntryDialog.static.actions = [
	{
		action: 'save',
		label: mw.message( 'bs-translation-transfer-glossary-edit-dialog-save' ).text(),
		flags: [ 'primary', 'progressive' ],
		disabled: true
	},
	{
		action: 'cancel',
		title: mw.message( 'bs-translation-transfer-glossary-edit-dialog-cancel' ).text(),
		icon: 'close',
		flags: 'safe'
	}
];

translationTransfer.ui.dialog.GlossaryEditEntryDialog.prototype.initialize = function () {
	translationTransfer.ui.dialog.GlossaryEditEntryDialog.parent.prototype.initialize.apply( this );

	this.makeSourceLabel();
	this.makeInput();

	this.$body.addClass( 'translate-transfer-glossary-edit-dialog' );
};

translationTransfer.ui.dialog.GlossaryEditEntryDialog.prototype.makeSourceLabel = function () {
	const sourceLabelMsg = mw.message(
		'bs-translation-transfer-glossary-edit-dialog-change-translation-label',
		this.sourceText
	).parse();

	const sourceLabelWidget = new OO.ui.LabelWidget( {
		$element: $( '<p>' )
	} );
	sourceLabelWidget.$element.html( sourceLabelMsg );

	this.$body.append( sourceLabelWidget.$element );
};

translationTransfer.ui.dialog.GlossaryEditEntryDialog.prototype.makeInput = function () {
	const newTranslationInput = new OO.ui.InputWidget( {
		name: 'translation',
		value: this.translationText
	} );

	newTranslationInput.$element.on( 'change', ( e ) => {
		const val = $( e.target ).val();

		if ( val && val !== this.translationText ) {
			this.actions.setAbilities( { save: true } );
			this.newTranslationText = val;
		} else {
			this.actions.setAbilities( { save: false } );
		}
	} );

	this.$body.append( newTranslationInput.$element );
};

translationTransfer.ui.dialog.GlossaryEditEntryDialog.prototype.getBodyHeight = function () {
	return translationTransfer.ui.dialog.GlossaryEditEntryDialog.parent.prototype.getBodyHeight.call( this ) + 50;
};

translationTransfer.ui.dialog.GlossaryEditEntryDialog.prototype.getActionProcess = function ( action ) {
	switch ( action ) {
		case 'save':
			// Save changed translation
			this.execute().done( () => {
				this.emit( 'translationUpdated' );

				this.close();
			} ).fail( () => {

			} );

			break;
		case 'cancel':
			this.close();
			break;
	}

	return translationTransfer.ui.dialog.GlossaryEditEntryDialog.super.prototype.getActionProcess.call( this, action );
};

translationTransfer.ui.dialog.GlossaryEditEntryDialog.prototype.execute = function () {
	const dfd = $.Deferred();

	translationTransfer._internal._getApi().done( ( api ) => { // eslint-disable-line no-underscore-dangle
		api.updateGlossaryEntry(
			this.targetLanguage,
			{
				sourceText: this.sourceText,
				newTranslationText: this.newTranslationText
			}
		).done( ( response ) => { // eslint-disable-line no-unused-vars
			dfd.resolve();
		} ).fail( ( jqXHR, statusText, error ) => {
			console.dir( jqXHR ); // eslint-disable-line no-console
			console.dir( statusText ); // eslint-disable-line no-console
			console.dir( error ); // eslint-disable-line no-console
		} );
	} );

	return dfd.promise();
};
