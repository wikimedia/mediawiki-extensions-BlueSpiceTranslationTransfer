<?php

namespace BlueSpice\TranslationTransfer;

interface IDictionary {

	/**
	 * Returns translation of specified source to the specified language.
	 *
	 * @param string $source
	 * @param string $targetLang
	 * @return string|null Translation string or <tt>null</tt> if there is no translation yet
	 */
	public function get( string $source, string $targetLang ): ?string;

	/**
	 * Inserts translation of specified source to the specified language.
	 *
	 * @param string $source
	 * @param string $targetLang
	 * @param string $translation
	 * @return bool <tt>true</tt> if dictionary was successfully updated, <tt>false</tt> otherwise
	 */
	public function insert( string $source, string $targetLang, string $translation ): bool;

	/**
	 * Updates translation of specified source to the specified language.
	 *
	 * @param string $source
	 * @param string $targetLang
	 * @param string $translation
	 * @return bool <tt>true</tt> if dictionary was successfully updated, <tt>false</tt> otherwise
	 */
	public function update( string $source, string $targetLang, string $translation ): bool;

	/**
	 * Removes translation of specified source to the specified language.
	 *
	 * @param string $source
	 * @param string $targetLang
	 */
	public function remove( string $source, string $targetLang ): void;
}
