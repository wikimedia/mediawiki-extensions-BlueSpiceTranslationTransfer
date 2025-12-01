bs.util.registerNamespace( 'translationTransfer.ui' );

translationTransfer.ui.NamespaceMapWidget = function ( cfg ) {
	let value = cfg.data.value || {};
	delete cfg.data.value;
	translationTransfer.ui.NamespaceMapWidget.parent.call( this, cfg );
	this.$input.remove();

	this.allowedTargets = this.getData().allowedTargets || [];
	this.sourceNamespaces = this.getData().sourceNamespaces || [];
	if ( typeof value === 'object' && Object.keys( value ).length === 0 ) {
		value = {};
	}
	this.value = value;

	this.$valueCnt = $( '<div>' ).addClass( 'translationTransfer-namespaceMapWidget-value' );
	this.render();
	this.$element.append( this.$valueCnt );
	this.addNewForm();
};

OO.inheritClass( translationTransfer.ui.NamespaceMapWidget, OO.ui.InputWidget );

translationTransfer.ui.NamespaceMapWidget.prototype.getValue = function () {
	return this.value;
};

translationTransfer.ui.NamespaceMapWidget.prototype.setValue = function ( value ) {
	if ( !value ) {
		return;
	}
	this.value = value;
	this.render();
	this.emit( 'change', this );
};

translationTransfer.ui.NamespaceMapWidget.prototype.render = function () {
	this.$valueCnt.empty();
	for ( const ns in this.value ) {
		if ( !this.value.hasOwnProperty( ns ) ) {
			continue;
		}
		this.addNamespace( ns, this.value[ ns ] );
	}
};

translationTransfer.ui.NamespaceMapWidget.prototype.addNamespace = function ( ns, target ) {
	const nsLabel = new OO.ui.LabelWidget( {
		label: mw.msg( 'bs-translation-transfer-ui-source-namespace' ) + ': ' + ns,
		classes: [ 'translationTransfer-namespace' ]
	} );
	const data = [];
	for ( const lang in target ) {
		data.push( { ns: ns, lang: lang, target: target[ lang ] } );
	}

	const store = new OOJSPlus.ui.data.store.Store( {
		data: data
	} );
	const grid = new OOJSPlus.ui.data.GridWidget( {
		noHeader: false,
		orderable: false,
		resizable: false,
		toolbar: null,
		paginator: null,
		border: 'all',
		columns: {
			lang: {
				headerText: mw.msg( 'bs-translation-transfer-config-column-target-language' ),
				type: 'text',
				width: 100
			},
			target: {
				headerText: mw.msg( 'bs-translation-transfer-config-column-target-namespace-label' ),
				type: 'text'
			},
			delete: {
				title: mw.msg( 'bs-translation-transfer-config-column-delete-title' ),
				type: 'action',
				actionId: 'delete',
				icon: 'trash',
				width: 30
			}
		},
		store: store
	} );
	grid.connect( this, {
		action: function ( action, row ) {
			if ( action !== 'delete' ) {
				return;
			}
			this.delete( row );
		}
	} );

	this.$valueCnt.append( nsLabel.$element, grid.$element );
};

translationTransfer.ui.NamespaceMapWidget.prototype.delete = function ( row ) {
	if ( !this.value.hasOwnProperty( row.ns ) ) {
		return;
	}
	if ( !this.value[ row.ns ].hasOwnProperty( row.lang ) ) {
		return;
	}
	delete this.value[ row.ns ][ row.lang ];
	if ( Object.keys( this.value[ row.ns ] ).length === 0 ) {
		delete this.value[ row.ns ];
	}
	this.setValue( this.value );
};

translationTransfer.ui.NamespaceMapWidget.prototype.addNewForm = function () {
	const panel = new OO.ui.PanelLayout( {
		expanded: false,
		padded: false
	} );
	const nsOptions = [];
	for ( const id in this.sourceNamespaces ) {
		if ( !this.sourceNamespaces.hasOwnProperty( id ) ) {
			continue;
		}
		nsOptions.push( new OO.ui.MenuOptionWidget( {
			data: this.sourceNamespaces[ id ],
			label: this.sourceNamespaces[ id ]
		} ) );
	}

	const nsField = new OO.ui.DropdownWidget( {
		menu: { items: nsOptions }
	} );
	nsField.menu.selectItem( nsField.menu.findFirstSelectableItem() );

	const langOptions = this.allowedTargets.map( ( lang ) => new OO.ui.MenuOptionWidget( {
		data: lang,
		label: lang
	} ) );

	const langField = new OO.ui.DropdownWidget( {
		menu: { items: langOptions }
	} );
	langField.menu.selectItem( langField.menu.findFirstSelectableItem() );

	const targetField = new OO.ui.TextInputWidget( { required: true } );
	targetField.connect( this, {
		change: function ( val ) {
			this.onChange( val );
		}
	} );

	const addButton = new OO.ui.ButtonWidget( {
		label: mw.msg( 'bs-translation-transfer-ui-add' ),
		flags: [ 'primary', 'progressive' ],
		disabled: true
	} );
	addButton.connect( this, {
		click: function () {
			this.onAdd( nsField, langField, targetField );
		}
	} );
	panel.$element.append( new OO.ui.FieldsetLayout(
		{
			label: mw.msg( 'bs-translation-transfer-ui-add-namespace-mapping' ),
			items: [
				new OO.ui.FieldLayout( nsField, {
					label: mw.msg( 'bs-translation-transfer-ui-source-namespace' ),
					help: mw.msg( 'bs-translation-transfer-ui-source-namespace-help' ),
					helpInline: true,
					align: 'top'
				} ),
				new OO.ui.FieldLayout( langField, {
					label: mw.msg( 'bs-translation-transfer-ui-target-lang' ),
					help: mw.msg( 'bs-translation-transfer-ui-target-lang-help' ),
					helpInline: true,
					align: 'top'
				} ),
				new OO.ui.FieldLayout( targetField, {
					label: mw.msg( 'bs-translation-transfer-ui-target-namespace' ),
					help: mw.msg( 'bs-translation-transfer-ui-target-namespace-help' ),
					helpInline: true,
					align: 'top'
				} ),
				new OO.ui.FieldLayout( addButton )
			]
		}
	).$element );
	this.$element.append( panel.$element );

	this.addButton = addButton;
};

translationTransfer.ui.NamespaceMapWidget.prototype.onAdd = function ( nsField, langField, targetField ) {
	targetField.getValidity().done( () => {
		const ns = nsField.getMenu().findSelectedItem().getData();
		const lang = langField.getMenu().findSelectedItem().getData();
		this.value[ ns ] = this.value[ ns ] || {};
		this.value[ ns ][ lang ] = targetField.getValue();
		this.setValue( this.value );
		nsField.menu.selectItem( nsField.menu.findFirstSelectableItem() );
		langField.menu.selectItem( langField.menu.findFirstSelectableItem() );
		targetField.setValue( '' );
		targetField.setValidityFlag( true );
	} );
};

/**
 * Disable "Add" button if text field is empty.
 *
 * @param {string} val
 */
translationTransfer.ui.NamespaceMapWidget.prototype.onChange = function ( val ) {
	if ( val !== '' ) {
		this.addButton.setDisabled( false );
	} else {
		this.addButton.setDisabled( true );
	}
};
