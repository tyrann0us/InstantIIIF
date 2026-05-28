/**
 * Tests for resources/iiif-title.js — the shared spoof/unspoof helpers
 * (mirror of src/IIIFTitle.php) consumed by mmv-patch.js and
 * media-search.js. Only ".jpg" is recognised on the way back, matching
 * the only extension we ever append on the way out.
 */

'use strict';

beforeEach( () => {
	jest.isolateModules( () => {
		require( '../../resources/iiif-title.js' );
	} );
} );

afterEach( () => {
	delete window.iiifTitle;
} );

describe( 'iiif-title.js', () => {
	test( 'exposes window.iiifTitle with the public surface', () => {
		expect( window.iiifTitle ).toBeDefined();
		expect( window.iiifTitle.SPOOF_EXTENSION ).toBe( 'jpg' );
		expect( typeof window.iiifTitle.spoof ).toBe( 'function' );
		expect( typeof window.iiifTitle.unspoof ).toBe( 'function' );
		expect( typeof window.iiifTitle.isSpoofed ).toBe( 'function' );
		expect( window.iiifTitle.IMAGE_EXTENSION_PATTERN ).toBeInstanceOf(
			RegExp
		);
	} );

	test.each( [
		[ 'Bsb11610364', 'Bsb11610364.jpg' ],
		[ 'Df_dk_0007450', 'Df_dk_0007450.jpg' ],
		// already .jpg — idempotent
		[ 'Foo.jpg', 'Foo.jpg' ],
		[ 'Foo.JPG', 'Foo.JPG' ],
		// shelfmark with dash
		[ '1741646995-18800000', '1741646995-18800000.jpg' ],
		// only .jpg is recognised — a .png suffix is treated as part of the id
		[ 'foo.png', 'foo.png.jpg' ],
	] )( 'spoof(%j) === %j', ( input, expected ) => {
		expect( window.iiifTitle.spoof( input ) ).toBe( expected );
	} );

	test.each( [
		[ 'Foo.jpg', 'Foo' ],
		[ 'Foo.JPG', 'Foo' ],
		// no extension — idempotent
		[ 'Bsb11610364', 'Bsb11610364' ],
		// only strips one trailing .jpg
		[ 'Foo.tif.jpg', 'Foo.tif' ],
		// unrecognised extensions pass through
		[ 'Foo.png', 'Foo.png' ],
		[ 'Foo.tif', 'Foo.tif' ],
	] )( 'unspoof(%j) === %j', ( input, expected ) => {
		expect( window.iiifTitle.unspoof( input ) ).toBe( expected );
	} );

	test( 'spoof / unspoof round-trip for extension-less IDs', () => {
		const id = 'Bsb11610364';
		expect( window.iiifTitle.unspoof( window.iiifTitle.spoof( id ) ) ).toBe(
			id
		);
	} );

	test.each( [
		[ 'Foo.jpg', true ],
		[ 'Foo.JPG', true ],
		[ 'Bsb11610364', false ],
		[ 'Foo.png', false ],
		[ 'Foo.tif', false ],
		[ '', false ],
	] )( 'isSpoofed(%j) === %j', ( input, expected ) => {
		expect( window.iiifTitle.isSpoofed( input ) ).toBe( expected );
	} );

	test( 'IMAGE_EXTENSION_PATTERN matches only end-anchored .jpg', () => {
		const pat = window.iiifTitle.IMAGE_EXTENSION_PATTERN;
		expect( pat.test( '.jpg' ) ).toBe( true );
		expect( pat.test( '.JPG' ) ).toBe( true );
		// Anchored at end-of-string — interior matches don't count.
		expect( pat.test( '.jpg.' ) ).toBe( false );
		// Other extensions don't match.
		expect( pat.test( '.png' ) ).toBe( false );
		expect( pat.test( '.tif' ) ).toBe( false );
	} );
} );
