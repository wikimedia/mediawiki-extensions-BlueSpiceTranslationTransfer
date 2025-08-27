bs.util.registerNamespace( 'translationTransfer.ui' );

translationTransfer.ui.LanguageSelectionPageLayout = function ( cfg ) {
	translationTransfer.ui.LanguageSelectionPageLayout.parent.call( this, 'languageSelection', cfg );
	OO.EventEmitter.call( this );

	this.languages = cfg.languages;
	this.makeLabel();
	this.makeSelection();
};

OO.inheritClass( translationTransfer.ui.LanguageSelectionPageLayout, OO.ui.PageLayout );
OO.mixinClass( translationTransfer.ui.LanguageSelectionPageLayout, OO.EventEmitter );

translationTransfer.ui.LanguageSelectionPageLayout.prototype.makeLabel = function () {
	const label = new OO.ui.LabelWidget( {
		label: mw.message( 'bs-translation-transfer-ui-language-selection-label' ).text()
	} );
	this.$element.append( label.$element );
};

translationTransfer.ui.LanguageSelectionPageLayout.prototype.reset = function () {
	if ( Object.keys( this.languages ).length === 1 ) {
		this.languageSelector.selectItem( this.languageSelector.findFirstSelectableItem() );
		this.emit( 'languageChanged', this.languageSelector.findFirstSelectableItem().getData() );
	} else {
		this.languageSelector.selectItem( null );
	}
};

translationTransfer.ui.LanguageSelectionPageLayout.prototype.makeSelection = function () {
	const items = [];
	let code;
	for ( code in this.languages ) {
		if ( !this.languages.hasOwnProperty( code ) ) {
			continue;
		}
		items.push( new OO.ui.RadioOptionWidget( {
			data: code,
			label: this.languages[ code ]
		} ) );
	}
	this.languageSelector = new OO.ui.RadioSelectWidget( {
		items: items,
		classes: [ 'tt-translate-language-selection-selector' ]
	} );

	this.languageSelector.connect( this, {
		select: function ( item ) {
			if ( item ) {
				this.emit( 'languageChanged', item.getData() );
			}
		}
	} );

	this.$element.append( this.languageSelector.$element );
};
