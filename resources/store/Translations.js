translationTransfer.store.Translations = function ( cfg ) {
	this.total = 0;

	cfg.remoteSort = true;
	cfg.remoteFilter = true;

	translationTransfer.store.Translations.parent.call( this, cfg );
};

OO.inheritClass( translationTransfer.store.Translations, OOJSPlus.ui.data.store.Store );

translationTransfer.store.Translations.prototype.doLoadData = function () {
	const dfd = $.Deferred();

	translationTransfer._internal._getApi().done( ( api ) => { // eslint-disable-line no-underscore-dangle
		api.getTranslations( {
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

translationTransfer.store.Translations.prototype.getTotal = function () {
	return this.total;
};
