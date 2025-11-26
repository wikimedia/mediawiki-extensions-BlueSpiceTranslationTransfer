bs.util.registerNamespace( 'translationTransfer.ui' );

translationTransfer.ui.TransferPageLayout = function ( cfg ) {
	translationTransfer.ui.TransferPageLayout.parent.call( this, 'transfer', cfg );

	this.label = new OO.ui.LabelWidget();
	this.icon = new OO.ui.IconWidget();

	this.panel = new OO.ui.PanelLayout(
		{
			expanded: false,
			classes: [ 'tt-translate-transfer-panel' ]
		}
	);
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
	this.transfer().done( ( targetTitleHref, transferredResources ) => { // eslint-disable-line es-x/no-arraybuffer-prototype-transfer
		this.$element.children().remove();

		const successMsg = mw.message(
			'bs-translation-transfer-ui-transfer-success',
			mw.config.get( 'wgTitle' ),
			targetTitleHref,
			this.data.target.targetTitle
		).parse();

		const successMessageWidget = new OO.ui.MessageWidget( {
			type: 'success',
			classes: [ 'translate-transfer-title-transfer-success' ]
		} );
		// That's the only way I found to set HTML content for message widget without losing icon
		successMessageWidget.$element.find( '.oo-ui-labelElement-label' ).html( successMsg );

		this.$element.append( successMessageWidget.$element );

		if ( transferredResources ) {
			this.$element.append(
				$( '<p>' ).addClass( 'translate-transfer-transferred-resources-changes' )
					.html( mw.message( 'bs-translation-transfer-ui-related-resources-notice' ).text() )
			);

			const index = new OO.ui.IndexLayout( {
				expanded: false,
				framed: false,
				classes: [ 'translate-transfer-transferred-resources-layout' ]
			} );

			const tabs = [];

			// If there are any transferred related resources
			if ( transferredResources.success ) {
				const transferredTab = new OO.ui.TabPanelLayout( 'transferred', {
					label: mw.message( 'bs-translation-transfer-ui-related-resources-transferred-title' ).text(),
					expanded: false
				} );

				transferredTab.$element.append(
					new OO.ui.LabelWidget( {
						label: mw.message( 'bs-translation-transfer-ui-related-resources-transferred-notice' ).text(),
						classes: [ 'translate-transfer-transferred-resources-notice' ]
					} ).$element
				);

				const transferredAriaLabel = mw.message( 'bs-translation-transfer-ui-related-resources-transferred-title-aria' ).text();

				const $resourcesList = $( '<ul>' ).attr( 'aria-label', transferredAriaLabel );
				for ( const i in transferredResources.success ) {
					const successItem = transferredResources.success[ i ];

					const $item = $( '<li>' ).addClass( 'translate-transfer-transferred-resources-success-item' );

					let label;
					if ( successItem.href ) {
						label = new OO.ui.LabelWidget( {
							label: $( '<a>' ).attr( 'href', successItem.href ).text( successItem.title )
						} );
					} else {
						label = new OO.ui.LabelWidget( {
							label: successItem.title
						} );
					}

					$item.append( label.$element );

					$resourcesList.append( $item );
				}

				transferredTab.$element.append( $resourcesList );

				tabs.push( transferredTab );
			}

			// If there are any related resources which should have been transferred but failed
			if ( transferredResources.fail ) {
				const notTransferredTab = new OO.ui.TabPanelLayout( 'not-transferred', {
					label: mw.message( 'bs-translation-transfer-ui-related-resources-not-transferred-title' ).text(),
					expanded: false
				} );

				notTransferredTab.$element.append(
					new OO.ui.LabelWidget( {
						label: mw.message( 'bs-translation-transfer-ui-related-resources-not-transferred-notice' ).text(),
						classes: [ 'translate-transfer-transferred-resources-notice' ]
					} ).$element
				);

				const notTransferredAriaLabel = mw.message( 'bs-translation-transfer-ui-related-resources-not-transferred-title-aria' ).text();

				const $resourcesList = $( '<ul>' ).attr( 'aria-label', notTransferredAriaLabel );
				for ( const i in transferredResources.fail ) {
					const failItem = transferredResources.fail[ i ];

					const $item = $( '<li>' ).addClass( 'translate-transfer-transferred-resources-fail-item' );

					let label;
					if ( failItem.href ) {
						label = new OO.ui.LabelWidget( {
							label: $( '<a>' ).attr( 'href', failItem.href ).text( failItem.title )
						} );
					} else {
						label = new OO.ui.LabelWidget( {
							label: failItem.title
						} );
					}

					$item.append( label.$element );
					if ( failItem.reason ) {
						const infoIconPopup = new OO.ui.PopupButtonWidget( {
							icon: 'info',
							framed: false,
							label: mw.message( 'bs-translation-transfer-ui-related-resources-fail-reason' ).text(),
							invisibleLabel: true,
							classes: [ 'translate-transfer-transferred-resources-fail-info' ],
							popup: {
								padded: true,
								$content: $( '<p>' ).text( failItem.reason ),
								position: 'after',
								autoClose: true,
								classes: [ 'translate-transfer-transferred-resources-fail-popup' ]
							}
						} );

						$item.append( infoIconPopup.$element );
					}
					$resourcesList.append( $item );
				}

				notTransferredTab.$element.append( $resourcesList );

				tabs.push( notTransferredTab );
			}

			index.addTabPanels( tabs );

			this.$element.append( index.$element );
		}

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

		dfd.resolve( response.payload.targetTitleHref, response.payload.transferredResources );
	} ).fail( () => {
		dfd.reject();
	} );

	return dfd.promise();
};
