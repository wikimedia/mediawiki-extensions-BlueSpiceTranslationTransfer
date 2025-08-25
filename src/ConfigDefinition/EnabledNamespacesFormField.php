<?php

namespace BlueSpice\TranslationTransfer\ConfigDefinition;

use HTMLMultiSelectEx;

class EnabledNamespacesFormField extends HTMLMultiSelectEx {

	/**
	 * @inheritDoc
	 */
	public function validate( $value, $alldata ) {
		// From JS we may get "stdObject"
		if ( is_object( $value ) ) {
			$value = (array)$value;
		}

		if ( !is_array( $value ) ) {
			return false;
		}

		$currentLang = $this->mParams['data']['currentLang'];
		if ( !isset( $value[$currentLang] ) || !is_array( $value[$currentLang] ) ) {
			return false;
		}

		// Extract namespaces only for current language
		$value = $value[$currentLang];

		$options = $this->getOptions();
		if ( array_keys( $options ) !== range( 0, count( $options ) - 1 ) ) {
			// associative array
			$options = array_keys( $options );
			return empty( array_diff( $value, $options ) );
		}
		return parent::validate( $value, $alldata );
	}

	/**
	 * @inheritDoc
	 */
	public function getInputOOUI( $value ) {
		$this->mParent->getOutput()->addModules( 'oojs-ui-widgets' );

		$attr = $this->getOOUIAttributes();
		$attr['selected'] = $this->convertValueForWidget( $value );

		// If options hold just a list of already set values, disable it
		if ( $value == $this->getOptions() ) {
			$attr['options'] = [];
		}

		// Remove selected items form options to avoid double entry's
		// See ERM24998, ERM30577
		if ( !empty( $attr['selected'] ) && !empty( $attr['options'] ) ) {
			$attr['options'] = $this->deduplicateOptions( $attr['selected'], $attr['options' ] );
		}

		$attr['data']['allValues'] = $this->mParams['data']['allValues'];
		$attr['data']['currentLang'] = $this->mParams['data']['currentLang'];

		// As soon as there are always some "content namespaces" (at least NS_MAIN),
		// there will always be menu options to choose.
		return new EnabledNamespacesWidget( $attr );
	}

	/**
	 * @param array $value
	 * @return array
	 */
	private function convertValueForWidget( array $value ) {
		// OO.ui.MenuTagMultiselectWidget expects an array of objects with 'data' and 'label' keys
		// If option is listed in the options array, label from the option will be used, and this one
		// set here will be ignored
		$converted = [];

		// Also get and show to user only enabled namespaces for current language
		$currentLang = $this->mParams['data']['currentLang'];

		foreach ( $value as $lang => $namespaces ) {
			if ( $lang !== $currentLang ) {
				continue;
			}

			foreach ( $namespaces as $nsId ) {
				$converted[] = [
					'data' => $nsId,
					'label' => (string)$nsId
				];
			}
		}
		return $converted;
	}

	/**
	 * @param array $selected
	 * @param array $options
	 *
	 * @return array
	 */
	private function deduplicateOptions( array $selected, array $options ) {
		$deduplicated = [];
		foreach ( $options as $option ) {
			if ( !in_array( $option['data'], $selected ) ) {
				$deduplicated[] = $option;
			}
		}

		return $deduplicated;
	}
}
