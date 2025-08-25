( function ( mw, $, d ) {
	$( d ).on( 'click', '#ca-translate-transfer-action-translate', ( e ) => {
		e.stopPropagation();
		mw.loader.using( 'ext.translate.transfer' ).done( () => {
			const dialog = new translationTransfer.ui.TranslateDialog( {
					languages: mw.config.get( 'wgTranslationTransferAvailableTranslateLanguages' ),
					sourcePrefixedDbKey: mw.config.get( 'wgPageName' ),
					size: 'medium'
				} ),
				windowManager = OO.ui.getWindowManager();

			windowManager.addWindows( [ dialog ] );
			windowManager.openWindow( dialog );
		} );

		return false;
	} );

}( mediaWiki, jQuery, document ) );
