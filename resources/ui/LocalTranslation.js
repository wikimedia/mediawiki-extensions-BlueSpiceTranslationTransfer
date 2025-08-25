bs.util.registerNamespace( 'translationTransfer.ui' );

translationTransfer.ui.LocalTranslation = function ( cfg ) {
	translationTransfer.ui.LocalTranslation.parent.call( this, {} );
	this.languages = cfg.languages || {};

	if ( $.isEmptyObject( this.languages ) ) {
		return this.noLanguages();
	}

	this.original = null;
	this.$poConteiner = $( '#mw-content-text>.mw-parser-output' );
	this.$messageContainer = $( '<div>' ).attr( 'id', 'localTranslation-message' );
	this.$pickerContainer = this.makePicker();

	const layout = new OO.ui.HorizontalLayout();
	layout.$element.append( this.$messageContainer, this.$pickerContainer );
	this.$element.append( layout.$element );
};

OO.inheritClass( translationTransfer.ui.LocalTranslation, OO.ui.Widget );

translationTransfer.ui.LocalTranslation.prototype.noLanguages = function () {
	this.$element.append(
		new OO.ui.MessageWidget( {
			label: mw.message( 'bs-translation-transfer-local-no-languages' ).text()
		} ).$element
	);
};

translationTransfer.ui.LocalTranslation.prototype.makePicker = function () {
	this.buttons = {};
	for ( const key in this.languages ) {
		if ( !this.languages.hasOwnProperty( key ) ) {
			continue;
		}
		this.buttons[ key ] = new OO.ui.ButtonOptionWidget( {
			label: this.languages[ key ], data: key
		} );
	}
	this.picker = new OO.ui.ButtonSelectWidget( {
		items: Object.values( this.buttons )
	} );
	this.picker.connect( this, {
		choose: 'translate'
	} );

	const $pickerContainer = $( '<div>' ).addClass( 'localTranslation-picker' ).append(
		new OO.ui.FieldLayout( this.picker, {
			align: 'top',
			label: mw.message( 'bs-translation-transfer-local-layout-label' ).text(),
			classes: [ 'picker-inner' ]
		} ).$element
	);

	return $pickerContainer;
};

translationTransfer.ui.LocalTranslation.prototype.translate = function ( item ) {
	const lang = item.getData();
	this.ensureOriginal();

	this.setLoading( true );
	this.translateToLang( lang ).done( ( content ) => {
		this.processSuccess( content );
	} ).fail( () => {
		this.handleFailure();
	} );

};

translationTransfer.ui.LocalTranslation.prototype.ensureOriginal = function () {
	if ( !this.original ) {
		this.original = this.$poConteiner.html();
	}
};

translationTransfer.ui.LocalTranslation.prototype.translateToLang = function ( lang ) {
	const dfd = $.Deferred();

	const taskData = {
		lang: lang
	};
	bs.api.tasks.execSilent( 'translation-transfer', 'translate', taskData )
		.done( ( response ) => {
			if (
				!response.hasOwnProperty( 'success' ) ||
				response.success === false ||
				!response.hasOwnProperty( 'payload' ) ||
				!response.payload.hasOwnProperty( lang )
			) {
				return dfd.reject();
			}
			dfd.resolve( response.payload[ lang ].html );
		} )
		.fail( () => {
			dfd.reject();
		} );

	return dfd.promise();
};

translationTransfer.ui.LocalTranslation.prototype.processSuccess = function ( content ) {
	const widget = new OO.ui.MessageWidget( {
		type: 'success',
		label: new OO.ui.HtmlSnippet( mw.message( 'bs-translation-transfer-local-success' ).text() )
	} );
	const reset = new OO.ui.ButtonWidget( {
		framed: false,
		label: 'Reset to original',
		classes: [ 'reset-to-original' ]
	} );
	reset.connect( this, {
		click: 'reset'
	} );
	widget.$element.append( reset.$element );
	this.$messageContainer.html( widget.$element );
	this.$poConteiner.html( content );
	this.setLoading( false );
};

translationTransfer.ui.LocalTranslation.prototype.handleFailure = function () {
	this.$messageContainer.html( new OO.ui.MessageWidget( {
		type: 'warning',
		label: mw.message( 'bs-translation-transfer-local-failed' ).text()
	} ).$element );
	this.$poConteiner.html( this.original );
	this.setLoading( false );
};

translationTransfer.ui.LocalTranslation.prototype.setLoading = function ( loading ) {
	if ( loading ) {
		this.picker.setDisabled( true );
		this.$messageContainer.html( new OO.ui.MessageWidget( {
			type: 'notice',
			label: mw.message( 'bs-translation-transfer-local-progress' ).text()
		} ).$element );
	} else {
		this.picker.setDisabled( false );
	}
};

translationTransfer.ui.LocalTranslation.prototype.reset = function () {
	this.$messageContainer.html( '' );
	this.$poConteiner.html( this.original );
	this.picker.selectItem( null );
};
