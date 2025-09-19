bs.util.registerNamespace( 'translationTransfer.ve.ui' );

translationTransfer.ve.ui.NoTranslateInspector = function ( config ) {
	translationTransfer.ve.ui.NoTranslateInspector.super.call(
		this, ve.extendObject( { padded: true }, config )
	);
};

OO.inheritClass( translationTransfer.ve.ui.NoTranslateInspector, ve.ui.MWLiveExtensionInspector );

translationTransfer.ve.ui.NoTranslateInspector.static.name = 'notranslateInspector';
translationTransfer.ve.ui.NoTranslateInspector.static.title = mw.message( 'bs-translation-transfer-no-translate-tool-label' ).text();
translationTransfer.ve.ui.NoTranslateInspector.static.modelClasses = [ translationTransfer.ve.dm.NoTranslateNode ];
translationTransfer.ve.ui.NoTranslateInspector.static.dir = 'ltr';

translationTransfer.ve.ui.NoTranslateInspector.static.allowedEmpty = true;
translationTransfer.ve.ui.NoTranslateInspector.static.selfCloseEmptyBody = false;

ve.ui.windowFactory.register( translationTransfer.ve.ui.NoTranslateInspector );
