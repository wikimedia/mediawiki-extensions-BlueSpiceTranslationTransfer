bs.util.registerNamespace( 'translationTransfer.store' );

translationTransfer.store.Glossary = function ( cfg ) {
	this.total = 0;

	cfg.remoteSort = true;
	cfg.remoteFilter = true;

	this.selectedLanguage = '';

	translationTransfer.store.Glossary.parent.call( this, cfg );
};

OO.inheritClass( translationTransfer.store.Glossary, OOJSPlus.ui.data.store.Store );

translationTransfer.store.Glossary.prototype.doLoadData = function () {
	const dfd = $.Deferred();

	translationTransfer._internal._getApi().done( ( api ) => { // eslint-disable-line no-underscore-dangle
		api.getGlossaryEntries(
			this.selectedLanguage,
			{
				filter: this.filters || {},
				sort: this.sorters || {},
				start: this.offset,
				limit: this.limit
			} ).done( ( response ) => {
			if ( !response.hasOwnProperty( 'results' ) ) {
				return;
			}

			this.total = response.total;
			dfd.resolve( this.indexData( response.results ) );
		} ).fail( ( jqXHR, statusText, error ) => {
			console.dir( jqXHR ); // eslint-disable-line no-console
			console.dir( statusText ); // eslint-disable-line no-console
			console.dir( error ); // eslint-disable-line no-console
		} );
	} );

	return dfd.promise();
};

translationTransfer.store.Glossary.prototype.getTotal = function () {
	return this.total;
};

translationTransfer.store.Glossary.prototype.setLanguage = function ( langCode ) {
	this.selectedLanguage = langCode;
};

translationTransfer.store.Glossary.prototype.addTranslation = function ( sourceText, langCode, translationText ) {
	const dfd = $.Deferred();

	translationTransfer._internal._getApi().done( ( api ) => { // eslint-disable-line no-underscore-dangle
		api.addGlossaryEntry(
			this.selectedLanguage,
			{
				sourceText: sourceText,
				translationText: translationText
			}
		).done( ( response ) => { // eslint-disable-line no-unused-vars
			api.syncRemoteGlossary( this.selectedLanguage ).done( ( response ) => { // eslint-disable-line no-shadow, no-unused-vars
				dfd.resolve();
			} ).fail( ( error ) => {
				dfd.reject( error );
			} );
		} ).fail( ( error ) => {
			dfd.reject( error );
		} );
	} );

	return dfd.promise();
};

/**
 * Gets list of supported by DeepL glossary language pairs.
 *
 * @return {Promise}
 */
translationTransfer.store.Glossary.prototype.getSupportedLanguages = function () {
	const dfd = $.Deferred();

	translationTransfer._internal._getApi().done( ( api ) => { // eslint-disable-line no-underscore-dangle
		api.getGlossaryLanguages().done( ( response ) => {
			dfd.resolve( response.supported_target_langs );
		} ).fail( ( error ) => {
			dfd.reject( error );
		} );
	} );

	return dfd.promise();
};
