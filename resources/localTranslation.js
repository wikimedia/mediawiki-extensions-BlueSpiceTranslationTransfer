( function ( $ ) {
	$( () => {
		$( '#bs-translation-transfer-local' ).each( ( k, el ) => {
			$( '#bodyContent' ).prepend( new translationTransfer.ui.LocalTranslation( $( el ).data() ).$element );
			$( el ).remove();
		} );
	} );
}( jQuery ) );
