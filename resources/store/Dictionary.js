translationTransfer.store.Dictionary = function ( cfg ) {
	this.total = 0;

	cfg.remoteSort = true;
	cfg.remoteFilter = true;

	this.selectedLanguage = '';

	translationTransfer.store.Dictionary.parent.call( this, cfg );
};

OO.inheritClass( translationTransfer.store.Dictionary, OOJSPlus.ui.data.store.Store );

translationTransfer.store.Dictionary.prototype.doLoadData = function () {
	const dfd = $.Deferred();

	translationTransfer._internal._getApi().done( ( api ) => { // eslint-disable-line no-underscore-dangle
		api.getDictionaryEntries(
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

translationTransfer.store.Dictionary.prototype.getTotal = function () {
	return this.total;
};

translationTransfer.store.Dictionary.prototype.setLanguage = function ( langCode ) {
	this.selectedLanguage = langCode;
};

translationTransfer.store.Dictionary.prototype.updateFilter = function ( filterString ) {
	const filterData = {
		type: 'string',
		value: filterString
	};

	const filterFactory = new OOJSPlus.ui.data.FilterFactory();

	// In the dictionary we filter both source and translation, with OR condition (handled on the backend)
	this.filter(
		filterFactory.makeFilter( filterData ),
		'source_normalized'
	);

	this.filter(
		filterFactory.makeFilter( filterData ),
		'translation_normalized'
	);
};
