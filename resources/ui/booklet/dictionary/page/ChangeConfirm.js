translationTransfer.ui.booklet.dictionary.page.ChangeConfirm = function ( cfg ) {
	translationTransfer.ui.booklet.dictionary.page.ChangeConfirm.parent.call(
		this, 'changeConfirm', cfg
	);
	OO.EventEmitter.call( this );

	this.targetLanguage = cfg.targetLanguage;

	this.translationText = cfg.translationText;
	this.affectedPagesHref = cfg.affectedPagesHref;

	// For API call, we need prefixed DB keys there
	this.sourcePrefixedDbKey = cfg.sourcePrefixedDbKey;
	this.targetPrefixedDbKey = cfg.targetPrefixedDbKey;

	this.makeConfirmationMessage();
	this.makeWarningMessage();

	// Get amount of affected pages with dedicated web request
	// when user opens dialog and tries to edit translation (on demand)
	this.getAffectedPagesCount().done( ( affectedPagesCount ) => {
		this.affectedPagesCount = affectedPagesCount;

		this.makeAffectedPagesMessage();
	} );

	this.newTranslationText = '';
};

OO.inheritClass( translationTransfer.ui.booklet.dictionary.page.ChangeConfirm, OO.ui.PageLayout );
OO.mixinClass( translationTransfer.ui.booklet.dictionary.page.ChangeConfirm, OO.EventEmitter );

translationTransfer.ui.booklet.dictionary.page.ChangeConfirm.prototype.getAffectedPagesCount = function () {
	const dfd = $.Deferred();

	translationTransfer._internal._getApi().done( ( api ) => { // eslint-disable-line no-underscore-dangle
		api.getAffectedPagesCount(
			this.targetLanguage,
			{
				targetPrefixedDbKey: this.targetPrefixedDbKey
			} ).done( ( response ) => {
			dfd.resolve( response.affectedPagesCount );
		} ).fail( ( jqXHR, statusText, error ) => {
			console.dir( jqXHR ); // eslint-disable-line no-console
			console.dir( statusText ); // eslint-disable-line no-console
			console.dir( error ); // eslint-disable-line no-console
		} );
	} );

	return dfd.promise();
};

translationTransfer.ui.booklet.dictionary.page.ChangeConfirm.prototype.makeWarningMessage = function () {
	const messageWidget = new OO.ui.MessageWidget( {
		classes: [ 'translate-transfer-dictionary-edit-dialog-message' ],
		type: 'warning',
		label: mw.message( 'bs-translation-transfer-dictionary-edit-dialog-message' ).text()
	} );

	this.$element.append( messageWidget.$element );
};

translationTransfer.ui.booklet.dictionary.page.ChangeConfirm.prototype.makeAffectedPagesMessage = function () {
	const affectedPagesMsg = mw.message(
		'bs-translation-transfer-dictionary-edit-dialog-affected-pages',
		this.affectedPagesCount,
		this.affectedPagesHref
	).parse();

	const affectedPagesWidget = new OO.ui.LabelWidget( {
		classes: [ 'translate-transfer-dictionary-edit-dialog-affected-pages' ],
		$element: $( '<p>' )
	} );
	affectedPagesWidget.$element.html( affectedPagesMsg );

	this.$element.append( affectedPagesWidget.$element );
};

translationTransfer.ui.booklet.dictionary.page.ChangeConfirm.prototype.setNewTranslation = function ( newTranslationText ) {
	this.newTranslationText = newTranslationText;

	this.updateConfirmationMessage();
};

translationTransfer.ui.booklet.dictionary.page.ChangeConfirm.prototype.updateConfirmationMessage = function () {
	// We needed to "cut off" namespace for new translation input
	// Still, it would make sense to display here prefixed translation, so user won't forget about NS part at all
	let newTranslationText = this.newTranslationText;
	if ( this.translationText.indexOf( ':' ) > 0 ) {
		const translationBits = this.translationText.split( ':', 2 );
		newTranslationText = translationBits[ 0 ] + ':' + this.newTranslationText;
	}

	const confirmationMsg = mw.message(
		'bs-translation-transfer-dictionary-edit-dialog-confirmation-label',
		this.translationText,
		newTranslationText
	).parse();

	this.confirmationLabelWidget.$element.html( confirmationMsg );
};

translationTransfer.ui.booklet.dictionary.page.ChangeConfirm.prototype.makeConfirmationMessage = function () {
	this.confirmationLabelWidget = new OO.ui.LabelWidget( {
		classes: [ 'translate-transfer-dictionary-edit-dialog-confirmation' ],
		$element: $( '<p>' )
	} );

	this.$element.append( this.confirmationLabelWidget.$element );
};

translationTransfer.ui.booklet.dictionary.page.ChangeConfirm.prototype.execute = function () {
	const dfd = $.Deferred();

	translationTransfer._internal._getApi().done( ( api ) => { // eslint-disable-line no-underscore-dangle
		api.updateDictionaryEntry(
			this.targetLanguage,
			{
				sourcePrefixedDbKey: this.sourcePrefixedDbKey,
				targetPrefixedDbKey: this.targetPrefixedDbKey,
				newTranslationText: this.newTranslationText
			} ).done( ( response ) => { // eslint-disable-line no-unused-vars
			dfd.resolve();
		} ).fail( ( jqXHR, statusText, error ) => {
			console.dir( jqXHR ); // eslint-disable-line no-console
			console.dir( statusText ); // eslint-disable-line no-console
			console.dir( error ); // eslint-disable-line no-console

			dfd.reject( statusText );
		} );
	} );

	return dfd.promise();
};
