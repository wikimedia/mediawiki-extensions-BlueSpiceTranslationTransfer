bs.util.registerNamespace( 'translationTransfer.ui' );

translationTransfer.ui.TransferPageLayout = function ( cfg ) {
	translationTransfer.ui.TransferPageLayout.parent.call( this, 'transfer', cfg );

	this.label = new OO.ui.LabelWidget();
	this.icon = new OO.ui.IconWidget();

	this.panel = new OO.ui.PanelLayout( { classes: [ 'tt-translate-transfer-panel' ] } );
	this.panel.$element.append( this.icon.$element, this.label.$element );

	this.$element.append( this.panel.$element );
};

OO.inheritClass( translationTransfer.ui.TransferPageLayout, OO.ui.PageLayout );

translationTransfer.ui.TransferPageLayout.prototype.reset = function () {
	this.panel.$element.addClass( 'working' );
	this.label.setLabel( mw.message( 'bs-translation-transfer-ui-transfer-working' ).text() );
	this.icon.setIcon( 'INVALID' );
};

translationTransfer.ui.TransferPageLayout.prototype.setData = function ( data ) {
	this.data = data;
};

translationTransfer.ui.TransferPageLayout.prototype.execute = function () {
	const dfd = $.Deferred();

	this.reset();
	this.transfer().done( ( targetTitleHref ) => { // eslint-disable-line es-x/no-arraybuffer-prototype-transfer
		this.$element.children().remove();

		const successMsg = mw.message(
			'bs-translation-transfer-ui-transfer-success',
			mw.config.get( 'wgTitle' ),
			targetTitleHref,
			this.data.target.targetTitle
		).parse();

		const successLabelWidget = new OO.ui.LabelWidget( {
			classes: [ 'translate-transfer-title-transfer-success' ],
			$element: $( '<p>' )
		} );
		successLabelWidget.$element.html( successMsg );

		this.$element.append( successLabelWidget.$element );

		this.panel.$element.removeClass( 'working' );
		dfd.resolve();
	} ).fail( () => {
		this.label.setLabel( mw.message( 'bs-translation-transfer-ui-transfer-failed' ).text() );
		this.icon.setIcon( 'close' );
		this.panel.$element.removeClass( 'working' );
		dfd.resolve();
	} );

	return dfd.promise();
};

translationTransfer.ui.TransferPageLayout.prototype.transfer = function () { // eslint-disable-line es-x/no-arraybuffer-prototype-transfer
	const dfd = $.Deferred();

	const taskData = {
		targetTitlePrefixedText: this.data.target.targetTitle,
		target: JSON.stringify( this.data.target.target ),
		content: this.data.translation.wikitext
	};

	// Stopgap solution to allow pages with a lot of related pages to transfer
	const api = new mw.Api( { ajax: { timeout: 300 * 1000 } } );
	api.postWithToken( 'csrf', {
		action: 'bs-translation-transfer-foreign-page-tasks',
		task: 'push',
		taskData: JSON.stringify( taskData ),
		context: JSON.stringify( {
			wgAction: mw.config.get( 'wgAction' ),
			wgArticleId: mw.config.get( 'wgArticleId' ),
			wgCanonicalNamespace: mw.config.get( 'wgCanonicalNamespace' ),
			wgCanonicalSpecialPageName: mw.config.get( 'wgCanonicalSpecialPageName' ),
			wgRevisionId: mw.config.get( 'wgRevisionId' ),
			wgNamespaceNumber: mw.config.get( 'wgNamespaceNumber' ),
			wgPageName: mw.config.get( 'wgPageName' ),
			wgRedirectedFrom: mw.config.get( 'wgRedirectedFrom' ),
			wgRelevantPageName: mw.config.get( 'wgRelevantPageName' ),
			wgTitle: mw.config.get( 'wgTitle' )
		} )
	} ).done( ( response ) => {
		if (
			!response.hasOwnProperty( 'success' ) ||
			response.success === false
		) {
			return dfd.reject();
		}

		dfd.resolve( response.payload.targetTitleHref );
	} ).fail( () => {
		dfd.reject();
	} );

	return dfd.promise();
};
