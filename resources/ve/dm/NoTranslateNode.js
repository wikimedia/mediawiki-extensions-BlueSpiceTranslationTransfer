bs.util.registerNamespace( 'translationTransfer.ve.dm' );

translationTransfer.ve.dm.NoTranslateNode = function () {
	translationTransfer.ve.dm.NoTranslateNode.super.apply( this, arguments );
};

OO.inheritClass( translationTransfer.ve.dm.NoTranslateNode, ve.dm.MWInlineExtensionNode );

translationTransfer.ve.dm.NoTranslateNode.static.name = 'notranslate';
translationTransfer.ve.dm.NoTranslateNode.static.tagName = 'translation:ignore';
translationTransfer.ve.dm.NoTranslateNode.static.extensionName = 'translation:ignore';
translationTransfer.ve.dm.NoTranslateNode.static.matchTagNames = [ 'translation:ignore' ];

ve.dm.modelRegistry.register( translationTransfer.ve.dm.NoTranslateNode );
