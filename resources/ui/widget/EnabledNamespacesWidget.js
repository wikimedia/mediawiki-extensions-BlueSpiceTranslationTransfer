bs.util.registerNamespace( 'translationTransfer.ui' );

translationTransfer.ui.EnabledNamespacesWidget = function ( cfg ) {
	this.allValues = cfg.data.allValues;
	this.currentLang = cfg.data.currentLang;

	translationTransfer.ui.EnabledNamespacesWidget.parent.call( this, cfg );
};

OO.inheritClass( translationTransfer.ui.EnabledNamespacesWidget, OO.ui.MenuTagMultiselectWidget );

translationTransfer.ui.EnabledNamespacesWidget.prototype.getValue = function () {
	// Get selected values from input
	const val = translationTransfer.ui.EnabledNamespacesWidget.super.prototype.getValue.call( this );

	// User selects only namespaces enabled for current language,
	// but still on backend we should update whole global variable
	// (which contains enabled namespaces for all languages).

	// So before returning value to backend (for persisting in DB)
	// get whole variable data with all namespaces for all languages,
	// update enabled namespaces for current language, and return whole variable data.
	this.allValues[ this.currentLang ] = val;

	return this.allValues;
};
