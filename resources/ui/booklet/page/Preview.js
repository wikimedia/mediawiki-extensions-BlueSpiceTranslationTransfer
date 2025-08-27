bs.util.registerNamespace( 'translationTransfer.ui' );

translationTransfer.ui.PreviewPageLayout = function ( cfg ) {
	cfg = cfg || {};
	cfg.expanded = false;
	translationTransfer.ui.PreviewPageLayout.parent.call( this, 'preview', cfg );
	OO.EventEmitter.call( this );

	this.isWikitext = false;
};

OO.inheritClass( translationTransfer.ui.PreviewPageLayout, OO.ui.PageLayout );
OO.mixinClass( translationTransfer.ui.PreviewPageLayout, OO.EventEmitter );

translationTransfer.ui.PreviewPageLayout.prototype.setData = function ( data ) {
	this.data = data;

	this.generatePreview();
};

translationTransfer.ui.PreviewPageLayout.prototype.generatePreview = function () {
	const targetDetails = this.data.target;
	const targetTitle = targetDetails.targetTitle;

	this.$element.empty();

	const previewPanel = new OO.ui.PanelLayout( {
		expanded: false,
		padded: false,
		framed: false
	} );
	const previewLayout = new OO.ui.FieldsetLayout( {
		expanded: false,
		padded: true,
		label: targetTitle,
		classes: [ 'tt-translate-preview-layout' ]
	} );

	this.indexLayout = new OO.ui.IndexLayout( { framed: false, expanded: false } );

	const wtTab = new OO.ui.TabPanelLayout( 'wikitext', {
			expanded: false,
			padded: true,
			label: mw.message( 'bs-translation-transfer-ui-transfer-preview-wt' ).text()
		} ),
		htmlTab = new OO.ui.TabPanelLayout( 'html', {
			expanded: false,
			padded: true,
			label: mw.message( 'bs-translation-transfer-ui-transfer-preview-html' ).text()
		} ),
		wikitext = this.data.translation.wikitext;

	const previewHtmlWrapper = document.createElement( 'div' );
	const previewHtml = $( previewHtmlWrapper ).html( this.data.translation.html ); // eslint-disable-line no-jquery/variable-pattern

	htmlTab.$element.append( previewHtml );
	wtTab.$element.append( $( '<span>' ).addClass( 'tt-translate-wt-preview' ).text( wikitext ) );

	this.indexLayout.addTabPanels( [ htmlTab, wtTab ] );
	this.indexLayout.clearMenuPanel();

	this.indexLayout.$element.addClass( 'tt-translate-preview-index-layout' );

	previewPanel.$element.append( this.indexLayout.$element );
	previewLayout.addItems( [ previewPanel ] );

	this.$element.append( previewLayout.$element );
};

translationTransfer.ui.PreviewPageLayout.prototype.isWikitextCurrently = function () {
	return this.isWikitext;
};

translationTransfer.ui.PreviewPageLayout.prototype.switchPreview = function () {
	if ( this.isWikitext === false ) {
		this.indexLayout.setTabPanel( 'wikitext' );

		this.isWikitext = true;
	} else {
		this.indexLayout.setTabPanel( 'html' );

		this.isWikitext = false;
	}
};
