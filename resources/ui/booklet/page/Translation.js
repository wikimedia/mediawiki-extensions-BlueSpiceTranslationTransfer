bs.util.registerNamespace( 'translationTransfer.ui' );

translationTransfer.ui.TranslationPageLayout = function ( cfg ) {
	translationTransfer.ui.TranslationPageLayout.parent.call( this, 'translation', cfg );
	OO.EventEmitter.call( this );

	this.language = false;
	const defaultIcons = {
		working: 'INVALID',
		done: 'check',
		failed: 'close'
	};
	this.executionComplete = false;
	this.stepStateWidgets = {};
	this.steps = {
		translate: {
			icons: defaultIcons,
			labels: {
				working: mw.message( 'bs-translation-transfer-ui-translate-working' ).text(),
				done: mw.message( 'bs-translation-transfer-ui-translate-success' ).text(),
				failed: mw.message( 'bs-translation-transfer-ui-translate-fail' ).text()
			},
			callback: this.translate
		},
		checkTarget: {
			icons: defaultIcons,
			labels: {
				working: mw.message( 'bs-translation-transfer-ui-check-target-working' ).text(),
				done: mw.message( 'bs-translation-transfer-ui-check-target-success' ).text(),
				failed: mw.message( 'bs-translation-transfer-ui-check-target-fail' ).text()
			},
			callback: this.checkTarget
		}
	};
};

OO.inheritClass( translationTransfer.ui.TranslationPageLayout, OO.ui.PageLayout );
OO.mixinClass( translationTransfer.ui.TranslationPageLayout, OO.EventEmitter );

translationTransfer.ui.TranslationPageLayout.prototype.setLanguage = function ( lang ) {
	this.language = lang;
};

translationTransfer.ui.TranslationPageLayout.prototype.getTransferData = function () {
	if ( !this.executionComplete ) {
		return null;
	}

	return {
		target: this.steps.checkTarget.data,
		translation: this.steps.translate.data
	};
};

translationTransfer.ui.TranslationPageLayout.prototype.execute = function () {
	const dfd = $.Deferred(),
		steps = Object.keys( this.steps );

	this.executeSteps( steps, dfd );

	return dfd.promise();
};

translationTransfer.ui.TranslationPageLayout.prototype.reset = function () {
	this.$element.children().remove();
	this.stepStateWidgets = {};
	this.language = false;
};

translationTransfer.ui.TranslationPageLayout.prototype.executeSteps = function ( steps, dfd ) {
	if ( steps.length === 0 ) {
		this.stepsComplete();
		return dfd.resolve();
	}

	const currentStepName = steps.shift(),
		stepData = this.steps[ currentStepName ];

	this.setStepState( currentStepName, 'working' );
	stepData.callback.call( this ).done( () => {
		this.setStepState( currentStepName, 'done' );
		this.executeSteps( steps, dfd );
	} ).fail( () => {
		this.setStepState( currentStepName, 'failed' );
		dfd.reject();
	} );
};

translationTransfer.ui.TranslationPageLayout.prototype.stepsComplete = function () {
	this.executionComplete = true;
};

translationTransfer.ui.TranslationPageLayout.prototype.setStepState = function ( step, state ) {
	if ( !this.stepStateWidgets.hasOwnProperty( step ) ) {
		this.stepStateWidgets[ step ] = {
			label: new OO.ui.LabelWidget( {
				classes: [ 'tt-translate-state-label' ]
			} ),
			icon: new OO.ui.IconWidget()
		};

		this.$element.append( new OO.ui.HorizontalLayout( {
			items: [ this.stepStateWidgets[ step ].icon, this.stepStateWidgets[ step ].label ]
		} ).$element );
	}

	this.stepStateWidgets[ step ].icon.setIcon( this.steps[ step ].icons[ state ] );
	this.stepStateWidgets[ step ].label.setLabel( this.steps[ step ].labels[ state ] );
};

translationTransfer.ui.TranslationPageLayout.prototype.translate = function () {
	const dfd = $.Deferred();

	const taskData = {
		lang: this.language
	};
	bs.api.tasks.execSilent( 'translation-transfer', 'translate', taskData )
		.done( ( response ) => {
			if (
				!response.hasOwnProperty( 'success' ) ||
				response.success === false ||
				!response.hasOwnProperty( 'payload' ) ||
				!response.payload.hasOwnProperty( this.language )
			) {
				return dfd.reject();
			}
			this.steps.translate.data = response.payload[ this.language ];

			dfd.resolve();
		} )
		.fail( () => {
			dfd.reject();
		} );

	return dfd.promise();
};

translationTransfer.ui.TranslationPageLayout.prototype.checkTarget = function () {
	const dfd = $.Deferred();

	const targets = mw.config.get( 'wgTranslationTransferTargets' );
	if ( !targets.hasOwnProperty( this.language ) ) {
		return dfd.reject();
	}

	const target = targets[ this.language ]; // $.extend( targets[this.language], { id: this.language } );
	this.steps.checkTarget.data = {
		target: target
	};

	const targetTitle = this.getPrefixedTargetTitle();

	this.checkTitleExists( targetTitle, target )
		.done( ( exists ) => {
			this.steps.checkTarget.data.targetTitle = targetTitle;
			if ( !exists && !this.isAlwaysDraft( target ) ) {
				if ( this.isDraftTarget( target ) ) {
					// If intended to push to draft, and it is not configured to always push to draft -
					// - notify that will be pushing directly
					this.steps.checkTarget.data.warning = { directPush: true };
					return dfd.resolve();
				}
				return dfd.resolve();
			}
			if ( this.isDraftTarget( target ) ) {
				// If title exists (or it is configured to always push to draft) and it's intended to push to draft,
				// change to draft title
				const draftTitle = target.draftNamespace + ':' + targetTitle;
				this.checkTitleExists( draftTitle, target )
					.done( ( exists ) => { // eslint-disable-line no-shadow
						this.steps.checkTarget.data.targetTitle = draftTitle;
						if ( exists ) {
							this.steps.checkTarget.data.warning = { existsDraft: true };
						}
						dfd.resolve();
					} )
					.fail( () => {
						dfd.reject();
					} );
			} else {
				// otherwise, just notify that target exists
				this.steps.checkTarget.data.warning = { exists: true };
				return dfd.resolve();
			}
		} )
		.fail( () => {
			dfd.reject();
		} );

	return dfd.promise();
};

/**
 * Get the prefixed title of the target page with namespace
 * Namespace derives from mapping configuration
 *
 * @return {string}
 */
translationTransfer.ui.TranslationPageLayout.prototype.getPrefixedTargetTitle = function () {
	const prefixedSourceTitle = this.steps.translate.data.title;
	const namespaceMapping = mw.config.get( 'wgTranslateTransferTargetNamespaceMapping' );
	const splittedSourceTitle = prefixedSourceTitle.split( ':' );

	if ( splittedSourceTitle.length === 1 ) {
		return prefixedSourceTitle;
	}

	if ( !namespaceMapping.hasOwnProperty( splittedSourceTitle[ 0 ] ) ) {
		return prefixedSourceTitle;
	}

	if ( !namespaceMapping[ splittedSourceTitle[ 0 ] ].hasOwnProperty( this.language ) ) {
		return prefixedSourceTitle;
	}

	return namespaceMapping[ splittedSourceTitle[ 0 ] ][ this.language ] + ':' + splittedSourceTitle[ 1 ];
};

translationTransfer.ui.TranslationPageLayout.prototype.isDraftTarget = function ( target ) {
	return (
		( target.hasOwnProperty( 'pushToDraft' ) && ( target.pushToDraft === 'regular' || target.pushToDraft === 'always' ) ) &&
		target.hasOwnProperty( 'draftNamespace' ) &&
		( target.draftNamespace !== '' )
	);
};

translationTransfer.ui.TranslationPageLayout.prototype.isAlwaysDraft = function ( target ) {
	return target.pushToDraft === 'always';
};

translationTransfer.ui.TranslationPageLayout.prototype.checkTitleExists = function ( title, againstTarget ) {
	const dfd = $.Deferred();

	bs.api.tasks.execSilent( 'translation-transfer-foreign-page', 'getPageInfo', {
		title: title,
		target: JSON.stringify( againstTarget )
	} ).done( ( data ) => {
		if ( data.success === false ) {
			return dfd.reject();
		}

		if ( !data.payload.hasOwnProperty( 'page_id' ) && data.payload.hasOwnProperty( 'missing' ) ) {
			return dfd.resolve( false );
		}
		dfd.resolve( true );
	} ).fail( () => {
		dfd.reject();
	} );

	return dfd.promise();
};
