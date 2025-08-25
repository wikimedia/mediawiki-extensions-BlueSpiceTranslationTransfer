translationTransfer.ui.dialog.DictionaryEditEntryDialog = function ( cfg ) {
	cfg = cfg || {};

	translationTransfer.ui.dialog.DictionaryEditEntryDialog.parent.call( this, cfg );

	this.nsId = cfg.nsId || 0;
	this.sourceText = cfg.sourceText || '';
	this.translationText = cfg.translationText || '';
	this.targetLanguage = cfg.targetLanguage || false;

	// For API call, we need prefixed DB keys there
	this.sourcePrefixedDbKey = cfg.sourcePrefixedDbKey || '';
	this.targetPrefixedDbKey = cfg.targetPrefixedDbKey || '';

	this.affectedPagesHref = cfg.affectedPagesHref || '';

	this.newTranslationText = '';
};

OO.inheritClass( translationTransfer.ui.dialog.DictionaryEditEntryDialog, OO.ui.ProcessDialog );

translationTransfer.ui.dialog.DictionaryEditEntryDialog.static.name = 'dictionaryEditDialog';
translationTransfer.ui.dialog.DictionaryEditEntryDialog.static.title = mw.message( 'bs-translation-transfer-dictionary-edit-dialog-title' ).text();
translationTransfer.ui.dialog.DictionaryEditEntryDialog.static.actions = [
	{
		action: 'save',
		label: mw.message( 'bs-translation-transfer-dictionary-edit-dialog-save' ).text(),
		flags: [ 'primary', 'progressive' ],
		disabled: true,
		modes: [ 'translationChange', 'changeConfirm' ]
	},
	{
		action: 'back',
		title: mw.message( 'bs-translation-transfer-dictionary-edit-dialog-back' ).text(),
		icon: 'previous',
		flags: 'safe',
		modes: [ 'changeConfirm' ]
	},
	{
		action: 'cancel',
		title: mw.message( 'bs-translation-transfer-dictionary-edit-dialog-cancel' ).text(),
		icon: 'close',
		flags: 'safe',
		modes: [ 'translationChange' ]
	}
];

translationTransfer.ui.dialog.DictionaryEditEntryDialog.prototype.initialize = function () {
	translationTransfer.ui.dialog.DictionaryEditEntryDialog.parent.prototype.initialize.apply( this );

	this.booklet = new translationTransfer.ui.booklet.dictionary.DictionaryDialogBooklet( {
		targetLanguage: this.targetLanguage,

		sourceText: this.sourceText,
		translationText: this.translationText,

		affectedPagesHref: this.affectedPagesHref,

		sourcePrefixedDbKey: this.sourcePrefixedDbKey,
		targetPrefixedDbKey: this.targetPrefixedDbKey,

		outlined: false,
		showMenu: false,
		expanded: true
	} );

	this.$body.append( this.booklet.$element );

	this.$body.addClass( 'translate-transfer-dictionary-edit-dialog' );
};

translationTransfer.ui.dialog.DictionaryEditEntryDialog.prototype.getReadyProcess = function ( data ) {
	return translationTransfer.ui.dialog.DictionaryEditEntryDialog.parent.prototype.getReadyProcess.call( this, data )
		.next( function () {
			this.switchPanel( 'translationChange' );
		}, this );
};

translationTransfer.ui.dialog.DictionaryEditEntryDialog.prototype.getBodyHeight = function () {
	return translationTransfer.ui.dialog.DictionaryEditEntryDialog.parent.prototype.getBodyHeight.call( this ) + 150;
};

translationTransfer.ui.dialog.DictionaryEditEntryDialog.prototype.switchPanel = function ( name ) {
	const page = this.booklet.getPage( name );
	if ( !page ) {
		return;
	}

	this.actions.setMode( name );
	this.booklet.setPage( name );
	this.updateSize();

	switch ( name ) {
		case 'translationChange':
			// Disable "Save" button only if we did not change translation yet
			// But it should be enabled if we already changed translation
			// and then just clicked "Back"
			if ( this.newTranslationText === '' ) {
				this.actions.setAbilities( { save: false } );
			}

			page.connect( this, {
				inputValueChanged: function ( val ) {
					if ( val && val !== this.translationText ) {
						this.actions.setAbilities( { save: true } );
						this.newTranslationText = val;
					} else {
						this.actions.setAbilities( { save: false } );
					}
				}
			} );
			break;
		case 'changeConfirm':
			this.actions.setAbilities( { save: true } );

			page.setNewTranslation( this.newTranslationText );

			this.updateSize();
			this.setDimensions( { width: 500, height: 300 } );
			break;
	}
};

translationTransfer.ui.dialog.DictionaryEditEntryDialog.prototype.getActionProcess = function ( action ) {
	switch ( action ) {
		case 'save':
			var page = this.booklet.getCurrentPage(); // eslint-disable-line no-var
			if ( page.getName() === 'translationChange' ) {
				this.switchPanel( 'changeConfirm' );
			} else {
				// Save changed translation
				page.execute().done( () => {
					this.emit( 'translationUpdated' );

					this.close();
				} ).fail( ( errorMsg ) => {
					OO.ui.alert( errorMsg );
				} );
			}

			break;
		case 'cancel':
			this.close();
			break;
		case 'back':
			this.switchPanel( 'translationChange' );
			break;
	}

	return translationTransfer.ui.dialog.DictionaryEditEntryDialog.super.prototype.getActionProcess.call( this, action );
};
