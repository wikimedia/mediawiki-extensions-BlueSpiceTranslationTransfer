bs.util.registerNamespace( 'translationTransfer.ui' );

translationTransfer.ui.TranslateDialog = function ( cfg ) {
	cfg = cfg || {};

	translationTransfer.ui.TranslateDialog.parent.call( this, cfg );

	this.sourcePrefixedDbKey = cfg.sourcePrefixedDbKey || {};

	this.languages = cfg.languages || {};
	this.selectedLanguage = false;
};

OO.inheritClass( translationTransfer.ui.TranslateDialog, OO.ui.ProcessDialog );

translationTransfer.ui.TranslateDialog.static.name = 'translationTransferDialog';
translationTransfer.ui.TranslateDialog.static.title = mw.message( 'bs-translation-transfer-ui-dialog-title' ).text();
translationTransfer.ui.TranslateDialog.static.actions = [
	{
		action: 'translate',
		label: mw.message( 'bs-translation-transfer-ui-dialog-action-translate' ).text(),
		flags: [ 'primary', 'progressive' ],
		disabled: true,
		modes: [ 'languageSelection', 'translation', 'targetTitle' ]
	},
	{
		action: 'cancel',
		title: mw.message( 'bs-translation-transfer-ui-dialog-action-cancel' ).text(),
		flags: [ 'safe', 'close' ],
		modes: [ 'languageSelection' ]
	},
	{
		action: 'back',
		title: mw.message( 'bs-translation-transfer-ui-dialog-action-back' ).text(),
		flags: [ 'safe', 'back' ],
		modes: [ 'translation', 'targetTitle', 'preview' ]
	},
	{
		action: 'save',
		disabled: true,
		label: mw.message( 'bs-translation-transfer-ui-dialog-action-save' ).text(),
		flags: [ 'primary', 'progressive' ],
		modes: [ 'preview' ]
	},
	{
		action: 'wikitext',
		label: mw.message( 'bs-translation-transfer-ui-dialog-action-wikitext' ).text(),
		flags: 'secondary',
		modes: [ 'preview' ]
	},
	{
		action: 'done',
		flags: [ 'primary', 'progressive' ],
		label: mw.message( 'bs-translation-transfer-ui-dialog-action-done' ).text(),
		modes: [ 'transfer' ]
	},
	{
		action: 'translateAnother',
		flags: 'primary',
		label: mw.message( 'bs-translation-transfer-ui-dialog-action-translate-another' ).text(),
		modes: [ 'transfer' ]
	}
];

translationTransfer.ui.TranslateDialog.prototype.initialize = function () {
	translationTransfer.ui.TranslateDialog.parent.prototype.initialize.apply( this );

	this.booklet = new translationTransfer.ui.TranslationBooklet( {
		languages: this.languages,
		outlined: false,
		showMenu: false,
		expanded: false
	} );

	this.$body.append( this.booklet.$element );
};

translationTransfer.ui.TranslateDialog.prototype.getReadyProcess = function ( data ) {
	return translationTransfer.ui.TranslateDialog.parent.prototype.getReadyProcess.call( this, data )
		.next( function () {
			this.switchPanel( 'languageSelection' );
		}, this );
};

translationTransfer.ui.TranslateDialog.prototype.getSetupProcess = function ( data ) {
	return translationTransfer.ui.TranslateDialog.parent.prototype.getSetupProcess.call( this, data )
		.next( function () {
			// Prevent flickering, disable all actions before init is done
			this.actions.setMode( 'INVALID' );
		}, this );
};

translationTransfer.ui.TranslateDialog.prototype.getBodyHeight = function () {
	return this.booklet.$element.outerHeight( true ) + 30;
};

translationTransfer.ui.TranslateDialog.prototype.switchPanel = function ( name ) {
	this.title.setLabel(
		mw.message( 'bs-translation-transfer-ui-dialog-title' ).text()
	);

	const page = this.booklet.getPage( name );
	if ( !page ) {
		return;
	}
	this.actions.setMode( name );
	this.booklet.setPage( name );
	this.updateSize();

	switch ( name ) {
		case 'languageSelection':
			this.popPending();
			this.setSize( 'medium' );
			this.actions.setAbilities( { translate: false } );
			page.connect( this, {
				languageChanged: function ( val ) {
					if ( val ) {
						this.actions.setAbilities( { back: false, translate: true } );
						this.selectedLanguage = val;
					}
				}
			} );
			page.reset();
			break;
		case 'translation':
			this.actions.setAbilities( { back: false, translate: false } );
			this.pushPending();
			page.reset();
			page.setLanguage( this.selectedLanguage );
			page.execute().done( () => {
				this.popPending();

				this.transferData = page.getTransferData();

				// Also store here "source prefixed DB key", it will be passed further
				this.transferData.sourcePrefixedDbKey = this.sourcePrefixedDbKey;

				// If we got target title from the dictionary - do not give user opportunity to change it.
				// So go directly to "Preview".
				// Title dictionary entries should be changed only on the corresponding special page.
				if ( this.transferData.translation.dictionaryUsed === true ) {
					this.switchPanel( 'preview' );
				} else {
					// If title dictionary was not used - user may change target title before transfer.
					this.switchPanel( 'targetTitle' );
				}
			} ).fail( () => {
				this.popPending();
				this.actions.setAbilities( { back: true } );
			} );
			break;
		case 'targetTitle':
			this.actions.setAbilities( { back: true, translate: true } );

			page.setData( this.transferData );

			break;
		case 'preview':
			this.title.setLabel(
				mw.message( 'bs-translation-transfer-ui-dialog-title-preview' ).text()
			);

			this.actions.setAbilities( { back: true, save: true } );
			this.setSize( 'larger' );

			page.setData( this.transferData );
			break;
		case 'transfer':
			this.pushPending();
			this.actions.setAbilities( { translateAnother: false, done: false } );
			page.setData( this.transferData );
			page.execute().then( () => {
				this.setSize( 'medium' );
				if ( Object.keys( this.languages ).length === 1 ) {
					this.actions.setAbilities( { translateAnother: false, done: true } );
				} else {
					this.actions.setAbilities( { translateAnother: true, done: true } );
				}
				this.popPending();

				this.updateSize();
			} );
	}

	this.updateSize();
};

translationTransfer.ui.TranslateDialog.prototype.getActionProcess = function ( action ) {
	const currentPage = this.booklet.getCurrentPage();
	const currentPageName = currentPage.getName();

	switch ( action ) {
		case 'translate':
			if ( currentPageName === 'languageSelection' ) {
				this.switchPanel( 'translation' );
			} else {
				// If previous page was not "languageSelection",
				// and from "translation" page "translate" action is disabled,
				// then previous one is "targetTitle".

				// In that case we need to persist chosen by user translation in the dictionary.
				// It is done by separate request to the backend.
				const newTranslationPrefixedText = currentPage.getTargetTitle();

				// We need just text for the dictionary, without prefix
				let newTranslationText = '';
				if ( newTranslationPrefixedText.indexOf( ':' ) > 0 ) {
					const newTranslationBits = newTranslationPrefixedText.split( ':' );

					// If source title should be pushed to the "Draft" namespace, then we may get smth like that:
					// "Draft:Some_NS:SourceTitleA"
					// Cover such cases.
					if ( newTranslationBits.length > 2 ) {
						newTranslationText = newTranslationBits.slice( 2 ).join( ':' );
					} else {
						newTranslationText = newTranslationBits[ 1 ];
					}
				} else {
					newTranslationText = newTranslationPrefixedText;
				}

				this.insertTranslationIntoDictionary( newTranslationText ).done( () => {
					// But we need prefix further when transferring page and creating link to that page
					this.transferData.translation.title = newTranslationPrefixedText;
					this.transferData.target.targetTitle = newTranslationPrefixedText;

					this.transferData.translation.dictionaryUsed = true;

					this.switchPanel( 'preview' );
				} ).fail( ( error ) => {
					currentPage.showError( error );
				} );
			}

			break;
		case 'back':
			if ( currentPageName === 'preview' && this.transferData.translation.dictionaryUsed === false ) {
				this.switchPanel( 'targetTitle' );
			} else {
				this.switchPanel( 'languageSelection' );
			}

			break;
		case 'cancel':
		case 'done':
			this.close();
			break;
		case 'save':
			this.switchPanel( 'transfer' );
			break;
		case 'translateAnother':
			this.switchPanel( 'languageSelection' );
			break;
		case 'wikitext':
			currentPage.switchPreview();

			const isWikitextCurrently = currentPage.isWikitextCurrently(); // eslint-disable-line no-case-declarations

			const wikitextActionWidget = this.actions.get( { flags: 'secondary' } )[ 0 ]; // eslint-disable-line no-case-declarations

			if ( isWikitextCurrently ) {
				wikitextActionWidget.setLabel(
					mw.message( 'bs-translation-transfer-ui-transfer-preview-html' ).text()
				);
			} else {
				wikitextActionWidget.setLabel(
					mw.message( 'bs-translation-transfer-ui-dialog-action-wikitext' ).text()
				);
			}

			break;
	}

	return translationTransfer.ui.TranslateDialog.super.prototype.getActionProcess.call( this, action );
};

translationTransfer.ui.TranslateDialog.prototype.insertTranslationIntoDictionary = function ( newTranslationText ) {
	const dfd = $.Deferred();

	translationTransfer._internal._getApi().done( ( api ) => { // eslint-disable-line no-underscore-dangle
		api.insertDictionaryEntry(
			this.selectedLanguage,
			{
				sourcePrefixedDbKey: this.sourcePrefixedDbKey,
				newTranslationText: newTranslationText
			}
		).done( ( response ) => { // eslint-disable-line no-unused-vars
			dfd.resolve();
		} ).fail( ( errorText ) => {
			dfd.reject( errorText );
		} );
	} );

	return dfd.promise();
};
