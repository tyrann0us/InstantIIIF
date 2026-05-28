/**
 * Client-side mirror of src/IIIFTitle.php.
 *
 * Single source of truth for the spoofed image-extension regex and the
 * spoof/unspoof helpers consumed by mmv-patch.js (share URLs, embed
 * wikitext) and media-search.js (VE media-dialog query rewriting).
 *
 * Only ".jpg" is ever appended, so only ".jpg" needs to be recognised on
 * the way back. Keep `SPOOF_EXTENSION` in sync with src/IIIFTitle.php.
 */
( function () {
	'use strict';

	const SPOOF_EXTENSION = 'jpg';
	const PATTERN = new RegExp( '\\.' + SPOOF_EXTENSION + '$', 'i' );

	window.iiifTitle = {
		SPOOF_EXTENSION,
		IMAGE_EXTENSION_PATTERN: PATTERN,

		/**
		 * Append the spoofed extension when the dbkey doesn't already
		 * end with it. Idempotent.
		 * @param {string} dbKey
		 * @return {string} dbkey with the spoofed extension guaranteed
		 */
		spoof( dbKey ) {
			return PATTERN.test( dbKey )
				? dbKey
				: dbKey + '.' + SPOOF_EXTENSION;
		},

		/**
		 * Strip a trailing ".jpg" (case-insensitive). Idempotent.
		 * @param {string} dbKey
		 * @return {string} dbkey with any trailing spoofed extension removed
		 */
		unspoof( dbKey ) {
			return dbKey.replace( PATTERN, '' );
		},

		/**
		 * @param {string} dbKey
		 * @return {boolean} true when dbkey ends in the spoofed extension
		 */
		isSpoofed( dbKey ) {
			return PATTERN.test( dbKey );
		},
	};
} )();
