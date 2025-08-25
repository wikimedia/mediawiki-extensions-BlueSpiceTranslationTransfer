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

			this.store.reload();
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
				title: mw.message( 'bs-translation-transfer-glossary-action-edit' ).plain(),
				actionId: 'edit',
				icon: 'edit'
			},
			remove: {
				width: 40,
				type: 'action',
				visibleOnHover: true,
				title: mw.message( 'bs-translation-transfer-glossary-action-remove' ).plain(),
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
