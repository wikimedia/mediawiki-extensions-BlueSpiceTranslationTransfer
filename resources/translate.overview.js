$( () => {
	const translationOverviewPanel = new translationTransfer.ui.panel.Overview( {} );

	$( '#translate-transfer-overview' ).append( translationOverviewPanel.$element );
} );

require( './ui/panel/Overview.js' );
require( './store/Translations.js' );
