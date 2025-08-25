translationTransfer.api.Api = function () {
	this.currentRequests = {};
};

OO.initClass( translationTransfer.api.Api );

translationTransfer.api.Api.prototype.getTranslations = function ( params ) {
	params.filter = this.serializeStoreParams( params.filter );
	params.sort = this.serializeStoreParams( params.sort, 'property' );

	if ( params.hasOwnProperty( 'filter' ) ) {
		params.filter = JSON.stringify( params.filter );
	}
	if ( params.hasOwnProperty( 'sort' ) ) {
		params.sort = JSON.stringify( params.sort );
	}
	return this.get( 'list', params );
};

translationTransfer.api.Api.prototype.ackTranslation = function ( targetTitleId ) {
	return this.post( 'ack/' + targetTitleId );
};

translationTransfer.api.Api.prototype.getDictionaryEntries = function ( langCodeTo, queryParams ) {
	queryParams.filter = this.serializeStoreParams( queryParams.filter );
	queryParams.sort = this.serializeStoreParams( queryParams.sort, 'property' );

	if ( queryParams.hasOwnProperty( 'filter' ) ) {
		queryParams.filter = JSON.stringify( queryParams.filter );
	}
	if ( queryParams.hasOwnProperty( 'sort' ) ) {
		queryParams.sort = JSON.stringify( queryParams.sort );
	}

	return this.get( '/dictionary/' + langCodeTo, queryParams );
};

translationTransfer.api.Api.prototype.insertDictionaryEntry = function ( targetLang, queryParams ) {
	return this.post( '/dictionary/insert/' + targetLang, queryParams );
};

translationTransfer.api.Api.prototype.updateDictionaryEntry = function ( targetLang, queryParams ) {
	return this.post( '/dictionary/update/' + targetLang, queryParams );
};

translationTransfer.api.Api.prototype.removeDictionaryEntry = function ( targetLang, queryParams ) {
	return this.post( '/dictionary/remove/' + targetLang, queryParams );
};

translationTransfer.api.Api.prototype.getAffectedPagesCount = function ( targetLang, queryParams ) {
	return this.get( '/dictionary/affected_pages/' + targetLang, queryParams );
};

translationTransfer.api.Api.prototype.getGlossaryEntries = function ( langCodeTo, queryParams ) {
	queryParams.filter = this.serializeStoreParams( queryParams.filter );
	queryParams.sort = this.serializeStoreParams( queryParams.sort, 'property' );

	if ( queryParams.hasOwnProperty( 'filter' ) ) {
		queryParams.filter = JSON.stringify( queryParams.filter );
	}
	if ( queryParams.hasOwnProperty( 'sort' ) ) {
		queryParams.sort = JSON.stringify( queryParams.sort );
	}

	return this.get( '/glossary/list/' + langCodeTo, queryParams );
};

translationTransfer.api.Api.prototype.addGlossaryEntry = function ( targetLang, queryParams ) {
	return this.post( '/glossary/insert/' + targetLang, queryParams );
};

translationTransfer.api.Api.prototype.updateGlossaryEntry = function ( targetLang, queryParams ) {
	return this.post( '/glossary/update/' + targetLang, queryParams );
};

translationTransfer.api.Api.prototype.removeGlossaryEntry = function ( targetLang, queryParams ) {
	return this.post( '/glossary/remove/' + targetLang, queryParams );
};

translationTransfer.api.Api.prototype.syncRemoteGlossary = function ( targetLang ) {
	return this.post( '/glossary/sync/' + targetLang );
};

translationTransfer.api.Api.prototype.getGlossaryLanguages = function () {
	return this.get( '/glossary/languages' );
};

translationTransfer.api.Api.prototype.get = function ( path, params ) {
	params = params || {};
	return this.ajax( path, params, 'GET' );
};

translationTransfer.api.Api.prototype.post = function ( path, params ) {
	params = params || {};
	return this.ajax( path, JSON.stringify( { data: params } ), 'POST' );
};

translationTransfer.api.Api.prototype.put = function ( path, params ) {
	params = params || {};
	return this.ajax( path, JSON.stringify( { data: params } ), 'PUT' );
};

translationTransfer.api.Api.prototype.delete = function ( path, params ) {
	params = params || {};
	return this.ajax( path, JSON.stringify( { data: params } ), 'DELETE' );
};

translationTransfer.api.Api.prototype.ajax = function ( path, data, method ) {
	data = data || {};
	const dfd = $.Deferred();

	this.currentRequests[ path ] = $.ajax( {
		method: method,
		url: this.makeUrl( path ),
		data: data,
		contentType: 'application/json',
		dataType: 'json',
		beforeSend: function () {
			if ( this.currentRequests.hasOwnProperty( path ) ) {
				this.currentRequests[ path ].abort();
			}
		}.bind( this )
	} ).done( ( response ) => {
		delete ( this.currentRequests[ path ] );
		if ( response.success === false ) {
			if ( response.hasOwnProperty( 'error' ) ) {
				dfd.reject( response.error );
			} else {
				dfd.reject();
			}
			return;
		}
		dfd.resolve( response );
	} ).fail( ( jgXHR, type, status ) => {
		delete ( this.currentRequests[ path ] );
		if ( type === 'error' ) {
			dfd.reject( {
				error: jgXHR.responseJSON || jgXHR.responseText
			} );
		}
		dfd.reject( { type: type, status: status } );
	} );

	return dfd.promise();
};

translationTransfer.api.Api.prototype.makeUrl = function ( path ) {
	if ( path.charAt( 0 ) === '/' ) {
		path = path.slice( 1 );
	}
	return mw.util.wikiScript( 'rest' ) + '/translations/' + path;
};

/**
 * @param {Object} data
 * @param {string} fieldProperty
 * @return {Array}
 * @private
 */
translationTransfer.api.Api.prototype.serializeStoreParams = function ( data, fieldProperty ) {
	fieldProperty = fieldProperty || 'field';
	const res = [];
	for ( const key in data ) {
		if ( !data.hasOwnProperty( key ) ) {
			continue;
		}
		if ( data[ key ] ) {
			const objectData = typeof data[ key ].getValue === 'function' ? data[ key ].getValue() : data[ key ];
			const serialized = {};
			serialized[ fieldProperty ] = key;
			res.push( $.extend( serialized, objectData ) ); // eslint-disable-line no-jquery/no-extend
		}
	}

	return res;
};
