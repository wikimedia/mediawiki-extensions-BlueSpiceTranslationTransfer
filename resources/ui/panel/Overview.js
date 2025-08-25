bs.util.registerNamespace( 'translationTransfer.ui.panel' );

translationTransfer.ui.panel.Overview = function ( cfg ) {
	cfg = Object.assign( {
		padded: true,
		expanded: false
	}, cfg || {} );

	translationTransfer.ui.panel.Overview.parent.call( this, cfg );

	this.isLoading = false;

	this.defaultFilter = cfg.filter || {};

	this.store = new translationTransfer.store.Translations( {
		pageSize: 20,
		filter: this.defaultFilter
	} );
	this.store.connect( this, {
		loadFailed: function () {
			this.emit( 'loadFailed' );
		},
		loading: function () {
			if ( this.isLoading ) {
				return;
			}
			this.isLoading = true;
			this.emit( 'loadStarted' );
		}
	} );

	this.makeGrid().done( ( grid ) => { // eslint-disable-line no-unused-vars
		this.grid.connect( this, {
			datasetChange: function () {
				this.isLoading = false;
				this.emit( 'loaded' );
			}
		} );

		this.$element.append( this.$grid );
	} );
};

OO.inheritClass( translationTransfer.ui.panel.Overview, OO.ui.PanelLayout );

translationTransfer.ui.panel.Overview.prototype.makeGrid = function () {
	const dfd = $.Deferred();

	this.$grid = $( '<div>' );

	const pluginModules = require( '../../pluginModules.json' );

	mw.loader.using( pluginModules ).done( () => {

		// Default columns configuration
		const columns = {
			/* eslint-disable camelcase */
			source_page_normalized_title: {
				headerText: mw.message( 'bs-translation-transfer-overview-source-page-column' ).text(),
				type: 'url',
				urlProperty: 'source_page_link',
				valueParser: function ( val ) {
					// Change title DB key to look nicer
					val = val.replaceAll( '_', ' ' );
					// Truncate long titles
					return val.length > 35 ? val.slice( 0, 34 ) + '...' : val;
				},
				sortable: true,
				display: 'source_page_prefixed_title_key',
				filter: {
					type: 'text'
				}
			},
			source_lang: {
				headerText: mw.message( 'bs-translation-transfer-overview-source-lang-column' ).text(),
				type: 'text',
				sortable: true
			},
			target_page_normalized_title: {
				headerText: mw.message( 'bs-translation-transfer-overview-target-page-column' ).text(),
				type: 'url',
				urlProperty: 'target_page_link',
				valueParser: function ( val ) {
					// Change title DB key to look nicer
					val = val.replaceAll( '_', ' ' );
					// Truncate long titles
					return val.length > 35 ? val.slice( 0, 34 ) + '...' : val;
				},
				sortable: true,
				display: 'target_page_prefixed_title_key',
				filter: {
					type: 'text'
				}
			},
			target_lang: {
				headerText: mw.message( 'bs-translation-transfer-overview-target-lang-column' ).text(),
				type: 'text',
				sortable: true
			},
			release_ts: {
				headerText: mw.message( 'bs-translation-transfer-overview-release-timestamp-column' ).text(),
				type: 'date',
				display: 'release_formatted',
				sortable: true
			},
			source_last_change_ts: {
				headerText: mw.message( 'bs-translation-transfer-overview-source-last-change-timestamp-column' ).text(),
				type: 'date',
				display: 'source_last_change_formatted',
				sortable: true
			},
			target_last_change_ts: {
				headerText: mw.message( 'bs-translation-transfer-overview-target-last-change-timestamp-column' ).text(),
				type: 'date',
				display: 'target_last_change_formatted',
				sortable: true,
				hidden: true
			}
			/* eslint-enable camelcase */
		};

		// Add integrations from other extensions
		mw.hook( 'bs.translationtransfer.overview.columns' ).fire( columns );

		const gridCfg = {
			deletable: false,
			style: 'differentiate-rows',
			border: 'horizontal',
			columns: columns,
			store: this.store
		};

		this.grid = new OOJSPlus.ui.data.GridWidget( gridCfg );
		this.$grid.html( this.grid.$element );

		this.emit( 'gridRendered' );

		dfd.resolve( this.grid );
	} );

	return dfd.promise();
};
