bs.util.registerNamespace( 'translationTransfer.ve.ce' );

translationTransfer.ve.ce.NoTranslateNode = function () {
	translationTransfer.ve.ce.NoTranslateNode.super.apply( this, arguments );
};

OO.inheritClass( translationTransfer.ve.ce.NoTranslateNode, ve.ce.MWInlineExtensionNode );

translationTransfer.ve.ce.NoTranslateNode.static.name = 'notranslate';
translationTransfer.ve.ce.NoTranslateNode.static.primaryCommandName = 'notranslate';
translationTransfer.ve.ce.NoTranslateNode.static.iconWhenInvisible = 'translation';

ve.ce.nodeFactory.register( translationTransfer.ve.ce.NoTranslateNode );
