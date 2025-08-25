translationTransfer.ui.panel.Dictionary = function ( cfg ) {
	cfg = Object.assign( {
		padded: true,
		expanded: false
	}, cfg || {} );

	translationTransfer.ui.panel.Dictionary.parent.call( this, cfg );

	this.isLoading = false;

	// Wire up with filter input
	$( '#translate-transfer-dictionary-filter-input' )
		.on( 'change', ( e ) => {
			const currentValue = $( e.target ).val();

			if ( currentValue ) {
				this.store.updateFilter( currentValue );
			} else {
				this.store.clearFilters();
			}
		} );

	this.languages = cfg.languages || {};
	this.mainLanguageLabel = cfg.mainLanguageLabel || '';

	this.selectedLanguage = '';

	this.defaultFilter = cfg.filter || {};

	this.store = new translationTransfer.store.Dictionary( {
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

	const dropdownItems = [];
	for ( const langCode in this.languages ) {
		if ( !this.languages.hasOwnProperty( langCode ) ) {
			continue;
		}

		dropdownItems.push( {
			data: langCode,
			label: this.languages[ langCode ]
		} );
	}

	// Add translation language selector
	this.languageSelectorWidget = new OO.ui.DropdownInputWidget( {
		options: dropdownItems
	} ).on( 'change', ( langCode ) => {
		this.selectedLanguage = langCode;

		// Update "translation language" header
		this.updateTranslationLanguageHeader(
			this.languageSelectorWidget.dropdownWidget.getMenu().findItemFromData( langCode ).getLabel()
		);

		// Reload store
		this.store.setLanguage( langCode );
		this.store.reload();
	} );

	$( '.translate-transfer-dictionary-language-switch' ).append( this.languageSelectorWidget.$element );

	// Select first item from language selector by default
	const firstLangCode = dropdownItems[ 0 ].data;

	this.selectedLanguage = firstLangCode;

	this.languageSelectorWidget.setValue(
		firstLangCode
	);
	// Setting value is language selector in that way above
	// will not trigger "change" event.
	// So set current language in the store manually
	this.store.setLanguage( firstLangCode );

	this.grid = this.makeGrid();
	this.grid.connect( this, {
		datasetChange: function () {
			this.isLoading = false;
			this.emit( 'loaded' );
		}
	} );

	this.$element.append( this.$grid );
};

OO.inheritClass( translationTransfer.ui.panel.Dictionary, OO.ui.PanelLayout );

translationTransfer.ui.panel.Dictionary.prototype.makeGrid = function () {
	this.$grid = $( '<div>' );

	const gridCfg = {
		deletable: false,
		style: 'differentiate-rows',
		border: 'horizontal',
		columns: {
			source_normalized: { // eslint-disable-line camelcase
				headerText: this.mainLanguageLabel,
				type: 'url',
				urlProperty: 'source_page_link',
				sortable: true,
				display: 'source'
			},
			translation_normalized: { // eslint-disable-line camelcase
				headerText: this.languageSelectorWidget.dropdownWidget.getMenu().findFirstSelectedItem().getLabel(),
				type: 'url',
				urlProperty: 'translation_page_link',
				sortable: true,
				display: 'translation'
			},
			edit: {
				width: 40,
				type: 'action',
				visibleOnHover: true,
				title: mw.message( 'bs-translation-transfer-dictionary-action-edit' ).plain(),
				actionId: 'edit',
				icon: 'edit'
			},
			remove: {
				width: 40,
				type: 'action',
				visibleOnHover: true,
				title: mw.message( 'bs-translation-transfer-dictionary-action-remove' ).plain(),
				actionId: 'remove',
				icon: 'trash'
			}
		},
		store: this.store
	};

	const grid = new OOJSPlus.ui.data.GridWidget( gridCfg );

	grid.connect( this, {
		action: function ( action, row ) {
			if ( action === 'edit' ) {
				this.emit( 'edit', {
					nsId: row.ns_id,
					sourceText: row.source,
					translationText: row.translation,
					targetLanguage: this.selectedLanguage,
					// We compose that link on backend because it leads to the target wiki
					// and on backend we have access to "target recognizer" for that
					affectedPagesHref: row.affected_pages_link,

					// For API call, we need prefixed DB keys there
					sourcePrefixedDbKey: row.source_prefixed_db_key,
					targetPrefixedDbKey: row.translation_prefixed_db_key
				} );
				return;
			}
			if ( action === 'remove' ) {
				this.emit( 'remove', {
					sourceText: row.source,
					targetLanguage: this.selectedLanguage,

					// We compose that link on backend because it leads to the target wiki
					// and on backend we have access to "target recognizer" for that
					affectedPagesHref: row.affected_pages_link,

					// For API call, we need prefixed DB keys there
					sourcePrefixedDbKey: row.source_prefixed_db_key
				} );
			}
		}
	} );

	this.$grid.html( grid.$element );

	this.emit( 'gridRendered' );
	return grid;
};

translationTransfer.ui.panel.Dictionary.prototype.updateTranslationLanguageHeader = function ( languageLabel ) {
	const $translationLanguageHeader =
		$( '#translate-transfer-dictionary-grid th.oojsplus-data-gridWidget-column-header[data-field="translation_normalized"]' );

	$translationLanguageHeader.find( '.header-button .oo-ui-labelElement-label' ).text( languageLabel );
};
