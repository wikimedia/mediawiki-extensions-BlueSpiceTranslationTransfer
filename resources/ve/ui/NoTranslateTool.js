bs.util.registerNamespace( 'translationTransfer.ve.ui' );

translationTransfer.ve.ui.NoTranslateTool = function ( toolGroup, config ) {
	translationTransfer.ve.ui.NoTranslateTool.super.call( this, toolGroup, config );
};

OO.inheritClass( translationTransfer.ve.ui.NoTranslateTool, ve.ui.FragmentWindowTool );

translationTransfer.ve.ui.NoTranslateTool.static.name = 'notranslateTool';
translationTransfer.ve.ui.NoTranslateTool.static.group = 'insert';
translationTransfer.ve.ui.NoTranslateTool.static.icon = 'no-translation';
translationTransfer.ve.ui.NoTranslateTool.static.title = mw.message( 'bs-translation-transfer-no-translate-tool-label' ).text();

translationTransfer.ve.ui.NoTranslateTool.static.modelClasses = [ translationTransfer.ve.dm.NoTranslateNode ];
translationTransfer.ve.ui.NoTranslateTool.static.commandName = 'notranslate';

ve.ui.toolFactory.register( translationTransfer.ve.ui.NoTranslateTool );

ve.ui.commandRegistry.register(
	new ve.ui.Command(
		'notranslate', 'window', 'open',
		{ args: [ 'notranslateInspector' ], supportedSelections: [ 'linear' ] }
	)
);
