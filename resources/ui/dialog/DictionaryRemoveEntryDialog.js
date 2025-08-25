translationTransfer.ui.dialog.DictionaryRemoveEntryDialog = function ( cfg ) {
	cfg = cfg || {};

	translationTransfer.ui.dialog.DictionaryRemoveEntryDialog.parent.call( this, cfg );

	this.sourceText = cfg.sourceText || '';
	this.targetLanguage = cfg.targetLanguage || false;

	// For API call, we need prefixed DB keys there
	this.sourcePrefixedDbKey = cfg.sourcePrefixedDbKey || '';

	this.affectedPagesHref = cfg.affectedPagesHref || '';
	this.affectedPagesCount = cfg.affectedPagesCount || 0;
};

OO.inheritClass( translationTransfer.ui.dialog.DictionaryRemoveEntryDialog, OO.ui.ProcessDialog );

translationTransfer.ui.dialog.DictionaryRemoveEntryDialog.static.name = 'dictionaryRemoveDialog';
translationTransfer.ui.dialog.DictionaryRemoveEntryDialog.static.title = mw.message( 'bs-translation-transfer-dictionary-remove-dialog-title' ).text();
translationTransfer.ui.dialog.DictionaryRemoveEntryDialog.static.actions = [
	{
		action: 'delete',
		label: mw.message( 'bs-translation-transfer-dictionary-remove-dialog-delete' ).text(),
		flags: [ 'primary', 'destructive' ]
	},
	{
		action: 'cancel',
		title: mw.message( 'bs-translation-transfer-dictionary-remove-dialog-cancel' ).text(),
		icon: 'close',
		flags: 'safe'
	}
];

translationTransfer.ui.dialog.DictionaryRemoveEntryDialog.prototype.initialize = function () {
	translationTransfer.ui.dialog.DictionaryRemoveEntryDialog.parent.prototype.initialize.apply( this );

	this.makeConfirmationMessage();

	this.$body.addClass( 'translate-transfer-dictionary-remove-dialog' );
};

translationTransfer.ui.dialog.DictionaryRemoveEntryDialog.prototype.makeConfirmationMessage = function () {
	const confirmationMsg = mw.message(
		'bs-translation-transfer-dictionary-remove-dialog-confirmation-label',
		this.sourceText
	).parse();

	const confirmationLabelWidget = new OO.ui.LabelWidget( {
		classes: [ 'translate-transfer-dictionary-remove-dialog-confirmation' ],
		$element: $( '<p>' )
	} );
	confirmationLabelWidget.$element.html( confirmationMsg );

	this.$body.append( confirmationLabelWidget.$element );
};

translationTransfer.ui.dialog.DictionaryRemoveEntryDialog.prototype.getBodyHeight = function () {
	return translationTransfer.ui.dialog.DictionaryRemoveEntryDialog.parent.prototype.getBodyHeight.call( this ) + 150;
};

translationTransfer.ui.dialog.DictionaryRemoveEntryDialog.prototype.getActionProcess = function ( action ) {
	switch ( action ) {
		case 'delete':
			// Remove translation
			this.execute().done( () => {
				this.emit( 'translationRemoved' );

				this.close();
			} ).fail( () => {

			} );

			break;
		case 'cancel':
			this.close();
			break;
	}

	return translationTransfer.ui.dialog.DictionaryRemoveEntryDialog.super.prototype.getActionProcess.call( this, action );
};

translationTransfer.ui.dialog.DictionaryRemoveEntryDialog.prototype.execute = function () {
	const dfd = $.Deferred();

	translationTransfer._internal._getApi().done( ( api ) => { // eslint-disable-line no-underscore-dangle
		api.removeDictionaryEntry(
			this.targetLanguage,
			{
				sourcePrefixedDbKey: this.sourcePrefixedDbKey
			} ).done( ( response ) => { // eslint-disable-line no-unused-vars
			dfd.resolve();
		} ).fail( ( jqXHR, statusText, error ) => {
			console.dir( jqXHR ); // eslint-disable-line no-console
			console.dir( statusText ); // eslint-disable-line no-console
			console.dir( error ); // eslint-disable-line no-console
		} );
	} );

	return dfd.promise();
};
