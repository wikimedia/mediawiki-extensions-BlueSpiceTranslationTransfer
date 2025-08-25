window.translationTransfer = window.translationTransfer || {};
window.translationTransfer.api = window.translationTransfer.api || {};

$( () => {
	$( '#bs-tt-banner-dismiss' ).on( 'click', () => {
		mw.loader.using( [ 'ext.translate.transfer.api' ] ).done( () => {
			const api = new translationTransfer.api.Api();
			api.ackTranslation( mw.config.get( 'wgArticleId' ) ).done( ( result ) => {
				if ( result.success === true ) {
					$( '[data-mwstake-alert-id="translationtransfer-translation-banner"]' ).remove();
				} else {
					console.log( result.error ); // eslint-disable-line no-console
				}
			} ).fail( ( resp1, resp2, resp3 ) => {
				console.dir( resp1 ); // eslint-disable-line no-console
				console.dir( resp2 ); // eslint-disable-line no-console
				console.dir( resp3 ); // eslint-disable-line no-console
			} );
		} );
	} );
} );
