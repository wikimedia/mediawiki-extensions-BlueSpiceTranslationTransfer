translationTransfer.ui.panel.Glossary = function ( cfg ) {
	cfg = Object.assign( {
		padded: true,
		expanded: false
	}, cfg || {} );

	translationTransfer.ui.panel.Glossary.parent.call( this, cfg );

	this.isLoading = false;

	this.languages = cfg.languages || {};
	this.mainLanguageLabel = cfg.mainLanguageLabel || '';
	this.mainLanguageCode = cfg.mainLanguageCode || '';

	this.langCodeToLabelMap = {};
	for ( const langCode in this.languages ) {
		if ( !this.languages.hasOwnProperty( langCode ) ) {
			continue;
		}

		this.langCodeToLabelMap[ langCode ] = this.languages[ langCode ];
	}

	this.selectedLanguage = '';

	this.defaultFilter = cfg.filter || {};

	this.store = new translationTransfer.store.Glossary( {
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
			label: this.mainLanguageCode.toUpperCase() + ' -> ' + langCode.toUpperCase()
		} );
	}

	// Block for adding new translation
	const $newTranslationWrapper = $( '<div>' ).addClass( 'translate-transfer-glossary-new-translation-wrapper' );

	this.sourceTextInput = new OO.ui.InputWidget();
	this.sourceTextInput.$input.attr(
		'placeholder',
		mw.message( 'bs-translation-transfer-glossary-source-text-placeholder' ).text()
	);

	this.translationTextInput = new OO.ui.InputWidget();
	this.translationTextInput.$input.attr(
		'placeholder',
		mw.message( 'bs-translation-transfer-glossary-translation-text-placeholder' ).text()
	);

	// Add translation language selector
	this.languageSelectorWidget = new OO.ui.DropdownInputWidget( {
		options: dropdownItems
	} ).on( 'change', ( langCode ) => {
		this.selectedLanguage = langCode;

		// Update "translation language" header
		this.updateTranslationLanguageHeader(
			this.langCodeToLabelMap[ langCode ]
		);

		// Reload store
		this.store.setLanguage( langCode );
		this.store.reload();
	} );

	this.submitNewTranslationButton = new OO.ui.ButtonWidget( {
		icon: 'check',
		flags: [ 'primary', 'progressive' ]
	} ).on( 'click', () => {
		const sourceText = this.sourceTextInput.getValue();
		const translationText = this.translationTextInput.getValue();

		if ( !sourceText || !translationText ) {
			return;
		}

		this.store.addTranslation(
			sourceText,
			this.selectedLanguage,
			translationText
		).done( ( response ) => { // eslint-disable-line no-unused-vars
			this.sourceTextInput.setValue( null );
			this.translationTextInput.setValue( null );

			this.jumpToPageOf( sourceText );
		} ).fail( ( error ) => {
			OO.ui.alert( error );
		} );
	} );

	$newTranslationWrapper.append( this.sourceTextInput.$element );
	$newTranslationWrapper.append( this.languageSelectorWidget.$element );
	$newTranslationWrapper.append( this.translationTextInput.$element );
	$newTranslationWrapper.append( this.submitNewTranslationButton.$element );

	this.$element.append( $newTranslationWrapper );

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

	this.disableNotSupportedLanguages();

	this.$element.append( this.$grid );
};

OO.inheritClass( translationTransfer.ui.panel.Glossary, OO.ui.PanelLayout );

translationTransfer.ui.panel.Glossary.prototype.makeGrid = function () {
	this.$grid = $( '<div>' );

	const firstSelectedLangCode = this.languageSelectorWidget.dropdownWidget.getMenu().findFirstSelectedItem().getData();

	const gridCfg = {
		deletable: false,
		style: 'differentiate-rows',
		border: 'horizontal',
		columns: {
			source_normalized: { // eslint-disable-line camelcase
				headerText: this.mainLanguageLabel,
				type: 'text',
				sortable: true,
				display: 'source',
				filter: {
					type: 'text'
				}
			},
			translation_normalized: { // eslint-disable-line camelcase
				headerText: this.langCodeToLabelMap[ firstSelectedLangCode ],
				type: 'text',
				sortable: true,
				display: 'translation',
				filter: {
					type: 'text'
				}
			},
			edit: {
				width: 40,
				type: 'action',
				visibleOnHover: true,
				title: mw.message( 'bs-translation-transfer-glossary-action-edit' ).text(),
				actionId: 'edit',
				icon: 'edit'
			},
			remove: {
				width: 40,
				type: 'action',
				visibleOnHover: true,
				title: mw.message( 'bs-translation-transfer-glossary-action-remove' ).text(),
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
					sourceText: row.source,
					translationText: row.translation,
					targetLanguage: this.selectedLanguage
				} );
				return;
			}
			if ( action === 'remove' ) {
				this.emit( 'remove', {
					sourceText: row.source,
					targetLanguage: this.selectedLanguage
				} );
			}
		}
	} );

	this.$grid.html( grid.$element );

	this.emit( 'gridRendered' );

	return grid;
};

translationTransfer.ui.panel.Glossary.prototype.updateTranslationLanguageHeader = function ( languageLabel ) {
	const $translationLanguageHeader =
		$( '#translate-transfer-glossary th.oojsplus-data-gridWidget-column-header[data-field="translation_normalized"]' );

	$translationLanguageHeader.find( '.header-button .oo-ui-labelElement-label' ).text( languageLabel );
};

/**
 * Finds the index (0-based, in alphabetical order) that the given source text currently has
 * among all glossary entries for the selected language. Used to figure out which page an entry
 * is/will be on, since entries are sorted alphabetically rather than by insertion order.
 *
 * @param {string} sourceText
 * @return {jQuery.Promise} Resolves with the 0-based index, or -1 if not found/on error.
 */
translationTransfer.ui.panel.Glossary.prototype.findIndexOf = function ( sourceText ) {
	const dfd = $.Deferred();
	const normalized = sourceText.trim().toLowerCase();
	const sortedByAllSourceTexts = {
		filter: {},
		sort: { source_normalized: { direction: 'ASC' } } // eslint-disable-line camelcase
	};

	translationTransfer._internal._getApi().done( ( api ) => { // eslint-disable-line no-underscore-dangle
		// First find out how many entries there are in total, so the follow-up request can ask
		// for exactly that many, instead of guessing at an upper bound.
		api.getGlossaryEntries(
			this.selectedLanguage,
			Object.assign( { start: 0, limit: 1 }, sortedByAllSourceTexts )
		).done( ( countResponse ) => {
			const total = countResponse.total || 0;
			if ( total === 0 ) {
				dfd.resolve( -1 );
				return;
			}

			api.getGlossaryEntries(
				this.selectedLanguage,
				Object.assign( { start: 0, limit: total }, sortedByAllSourceTexts )
			).done( ( response ) => {
				if ( !response.hasOwnProperty( 'results' ) ) {
					dfd.resolve( -1 );
					return;
				}
				dfd.resolve( response.results.findIndex(
					( row ) => row.source_normalized === normalized
				) );
			} ).fail( () => {
				dfd.resolve( -1 );
			} );
		} ).fail( () => {
			dfd.resolve( -1 );
		} );
	} );

	return dfd.promise();
};

/**
 * Reloads the grid and jumps to the given (0-based) page index.
 *
 * @param {number} pageIndex
 * @return {jQuery.Promise}
 */
translationTransfer.ui.panel.Glossary.prototype.jumpToPage = function ( pageIndex ) {
	const dfd = $.Deferred();

	this.store.reload().done( () => {
		this.advanceToPage( pageIndex ).done( () => {
			dfd.resolve();
		} );
	} );

	return dfd.promise();
};

/**
 * Repeatedly triggers the grid's paginator "next" action until the given (0-based) page index
 * is reached.
 *
 * @param {number} pageIndex
 * @return {jQuery.Promise}
 */
translationTransfer.ui.panel.Glossary.prototype.advanceToPage = function ( pageIndex ) {
	const dfd = $.Deferred();

	if ( !this.grid.paginator || pageIndex <= 0 ) {
		dfd.resolve();
		return dfd.promise();
	}

	$.when( this.grid.paginator.next() ).done( () => {
		this.advanceToPage( pageIndex - 1 ).done( () => {
			dfd.resolve();
		} );
	} );

	return dfd.promise();
};

/**
 * Reloads the grid and jumps to whichever page the given source text ends up on. Entries are
 * sorted alphabetically, so a newly added/edited entry can land on a different page than the one
 * currently shown - simply staying on (or returning to) the previous page is not correct.
 *
 * @param {string} sourceText
 * @return {jQuery.Promise}
 */
translationTransfer.ui.panel.Glossary.prototype.jumpToPageOf = function ( sourceText ) {
	const dfd = $.Deferred();

	this.findIndexOf( sourceText ).done( ( index ) => {
		const targetPageIndex = index >= 0 ? Math.floor( index / this.store.limit ) : 0;
		this.jumpToPage( targetPageIndex ).done( () => dfd.resolve() );
	} );

	return dfd.promise();
};

translationTransfer.ui.panel.Glossary.prototype.disableNotSupportedLanguages = function () {
	this.store.getSupportedLanguages().done( ( supportedTargetLangs ) => {

		const langItems = this.languageSelectorWidget.dropdownWidget.getMenu().items;
		for ( const i in langItems ) {
			const langCode = langItems[ i ].getData();

			// If language is not supported - disable corresponding dropdown option
			if ( !supportedTargetLangs.includes( langCode ) ) {
				langItems[ i ].setDisabled( true );
			}
		}

	} );
};
