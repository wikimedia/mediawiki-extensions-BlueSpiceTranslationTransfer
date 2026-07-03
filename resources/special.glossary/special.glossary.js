$( function () {
	const glossaryPanel = new translationTransfer.ui.panel.Glossary( {
		languages: mw.config.get( 'wgTranslationTransferAvailableTranslateLanguages' ),
		mainLanguageLabel: mw.config.get( 'wgTranslationTransferMainLanguageLabel' ),
		mainLanguageCode: mw.config.get( 'wgTranslationTransferMainLanguageCode' )
	} );

	$( '#translate-transfer-glossary' ).append( glossaryPanel.$element );

	const windowManager = new OO.ui.WindowManager();
	$( document.body ).append( windowManager.$element );

	glossaryPanel.connect( this, {
		edit: function ( data ) {
			const dialog = new translationTransfer.ui.dialog.GlossaryEditEntryDialog( {
				sourceText: data.sourceText,
				translationText: data.translationText,
				targetLanguage: data.targetLanguage
			} );
			dialog.connect( glossaryPanel, {
				translationUpdated: function () {
					this.jumpToPageOf( data.sourceText );

					translationTransfer._internal._getApi().done( ( api ) => { // eslint-disable-line no-underscore-dangle
						api.syncRemoteGlossary( data.targetLanguage ).fail( ( error ) => {
							OO.ui.alert( error );
						} );
					} );
				}
			} );

			windowManager.addWindows( [ dialog ] );
			windowManager.openWindow( dialog );
		},
		remove: function ( data ) {
			// Look up the entry's page before removing it - afterwards it won't be found
			// in the list anymore.
			glossaryPanel.findIndexOf( data.sourceText ).done( ( index ) => {
				const targetPageIndex = index >= 0 ? Math.floor( index / glossaryPanel.store.limit ) : 0;

				translationTransfer._internal._getApi().done( ( api ) => { // eslint-disable-line no-underscore-dangle
					api.removeGlossaryEntry(
						data.targetLanguage,
						{
							sourceText: data.sourceText
						}
					).done( ( response ) => { // eslint-disable-line no-unused-vars
						glossaryPanel.jumpToPage( targetPageIndex );

						api.syncRemoteGlossary( data.targetLanguage ).fail( ( error ) => {
							OO.ui.alert( error );
						} );
					} ).fail( ( error ) => {
						console.dir( error ); // eslint-disable-line no-console
					} );
				} );
			} );
		}
	} );
} );
