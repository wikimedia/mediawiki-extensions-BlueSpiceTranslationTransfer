$( function () {
	const translationDictionaryPanel = new translationTransfer.ui.panel.Dictionary( {
		languages: mw.config.get( 'wgTranslationTransferAvailableTranslateLanguages' ),
		mainLanguageLabel: mw.config.get( 'wgTranslationTransferMainLanguageLabel' )
	} );

	$( '#translate-transfer-dictionary-grid' ).append( translationDictionaryPanel.$element );

	const windowManager = new OO.ui.WindowManager();
	$( document.body ).append( windowManager.$element );

	translationDictionaryPanel.connect( this, {
		edit: function ( data ) {
			const dialog = new translationTransfer.ui.dialog.DictionaryEditEntryDialog( {
				nsId: data.nsId,
				sourceText: data.sourceText,
				translationText: data.translationText,
				targetLanguage: data.targetLanguage,
				affectedPagesHref: data.affectedPagesHref,

				sourcePrefixedDbKey: data.sourcePrefixedDbKey,
				targetPrefixedDbKey: data.targetPrefixedDbKey
			} );
			dialog.connect( translationDictionaryPanel, {
				translationUpdated: function () {
					this.store.reload();
				}
			} );

			windowManager.addWindows( [ dialog ] );
			windowManager.openWindow( dialog );
		},
		remove: function ( data ) {
			const dialog = new translationTransfer.ui.dialog.DictionaryRemoveEntryDialog( {
				sourceText: data.sourceText,
				targetLanguage: data.targetLanguage,
				affectedPagesHref: data.affectedPagesHref,

				sourcePrefixedDbKey: data.sourcePrefixedDbKey
			} );
			dialog.connect( translationDictionaryPanel, {
				translationRemoved: function () {
					this.store.reload();
				}
			} );

			windowManager.addWindows( [ dialog ] );
			windowManager.openWindow( dialog );
		}
	} );
} );
