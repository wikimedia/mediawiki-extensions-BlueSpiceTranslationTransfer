window.translationTransfer = {
	api: {},
	store: {},
	ui: {
		dialog: {},
		panel: {},
		booklet: {
			dictionary: {
				page: {}
			}
		}
	},
	_internal: {
		_api: {
			promise: null,
			api: null
		},
		_getApi: function () {
			/* eslint-disable no-underscore-dangle */
			// Get API Singleton
			if ( translationTransfer._internal._api.promise ) {
				return translationTransfer._internal._api.promise;
			}

			const dfd = $.Deferred();
			if ( !translationTransfer._internal._api.api ) {
				mw.loader.using( [ 'ext.translate.transfer.api' ], () => {
					translationTransfer._internal._api.api = new translationTransfer.api.Api();
					translationTransfer._internal._api.promise = null;
					dfd.resolve( translationTransfer._internal._api.api );
				} );
				translationTransfer._internal._api.promise = dfd.promise();
				return translationTransfer._internal._api.promise;
			} else {
				dfd.resolve( translationTransfer._internal._api.api );
			}
			return dfd.promise();

		}
	}
};
