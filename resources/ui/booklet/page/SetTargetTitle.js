bs.util.registerNamespace( 'translationTransfer.ui' );

translationTransfer.ui.TargetTitlePageLayout = function ( cfg ) {
	translationTransfer.ui.TargetTitlePageLayout.parent.call( this, 'targetTitle', cfg );
	OO.EventEmitter.call( this );

	// In "target title" field we will not output NS,
	// as soon as user cannot change NS translation in title dictionary.
	// Still, we should persist it to pass further for transferring of page.
	this.targetNsText = '';

	this.makeTargetTitleInput();
};

OO.inheritClass( translationTransfer.ui.TargetTitlePageLayout, OO.ui.PageLayout );
OO.mixinClass( translationTransfer.ui.TargetTitlePageLayout, OO.EventEmitter );

translationTransfer.ui.TargetTitlePageLayout.prototype.reset = function () {
	this.setData( this.data );
};

translationTransfer.ui.TargetTitlePageLayout.prototype.setData = function ( data ) {
	this.data = data;

	// Set target title from DeepL as default value to "target title" input

	// We should "cut off" namespace for new translation input,
	// as soon user cannot change namespace during translation
	let targetTitleValue = this.data.target.targetTitle;
	if ( targetTitleValue.indexOf( ':' ) > 0 ) {
		const inputTextBits = targetTitleValue.split( ':' );
		this.targetNsText = inputTextBits[ 0 ];

		// If source title should be pushed to the "Draft" namespace, then we may get smth like that:
		// "Draft:Some_NS:SourceTitleA"
		// Cover such cases.
		if ( inputTextBits.length > 2 ) {
			this.targetNsText = this.targetNsText + ':' + inputTextBits[ 1 ];

			targetTitleValue = inputTextBits.slice( 2 ).join( ':' );
		} else {
			targetTitleValue = inputTextBits[ 1 ];
		}
	}

	this.targetTitleInput.setValue( targetTitleValue );
};

translationTransfer.ui.TargetTitlePageLayout.prototype.makeTargetTitleInput = function () {
	this.targetTitleInput = new OO.ui.InputWidget( {
		name: 'translation',
		classes: [ 'tt-translate-target-title-input' ]
	} );
	// TODO: Check after change if title is valid to be target one?

	this.targetTitleInputField = new OO.ui.FieldLayout( this.targetTitleInput, {
		label: mw.message( 'bs-translation-transfer-ui-dialog-target-title-input-label' ).text(),
		align: 'top'
	} );

	this.targetTitleInput.connect( this, {
		change: function ( value ) { // eslint-disable-line no-unused-vars
			this.targetTitleInputField.setErrors( [] );
		}
	} );

	const fieldSetLayout = new OO.ui.FieldsetLayout( {
		items: [
			this.targetTitleInputField
		]
	} );

	this.$element.append( fieldSetLayout.$element );
};

translationTransfer.ui.TargetTitlePageLayout.prototype.getTargetTitle = function () {
	if ( this.targetNsText !== '' ) {
		return this.targetNsText + ':' + this.targetTitleInput.getValue();
	} else {
		return this.targetTitleInput.getValue();
	}
};

translationTransfer.ui.TargetTitlePageLayout.prototype.showError = function ( errorText ) {
	this.targetTitleInputField.setErrors( [ errorText ] );
};
