/**
 * Tests for resources/ve-media-insert.js — the patch that strips the spoofed
 * `.jpg` from IIIF files when VisualEditor's media dialog turns a chosen
 * search result into the inserted node (ve.ui.MWMediaDialog#confirmSelectedImage).
 *
 * The module is loaded as a VisualEditorPluginModules attribute, so its IIFE
 * runs while VE initialises: it resolves ext.visualEditor.mwimage and then
 * patches the dialog prototype. The tests load the module and flush microtasks
 * to let that mw.loader.using().then( patch ) chain settle.
 */

'use strict';

const { createMwEnv, loadVeMediaInsert } = require( './mw-mock' );

// Build a fake ve.ui.MWMediaDialog whose confirmSelectedImage records the
// title/canonicaltitle visible to it at call time (that's what the real method
// bakes into the inserted node's resource/href).
function installFakeVe( win ) {
	const seen = [];
	function MWMediaDialog() {}
	MWMediaDialog.static = { name: 'media' };
	MWMediaDialog.prototype.confirmSelectedImage = function () {
		const info = this.selectedImageInfo;
		seen.push( {
			title: info && info.title,
			canonicaltitle: info && info.canonicaltitle,
		} );
		return 'inserted';
	};
	win.ve = { ui: { MWMediaDialog } };
	return { MWMediaDialog, seen };
}

// Flush the microtask queue so mw.loader.using().then( patch ) runs.
function flush() {
	return new Promise( ( r ) => setTimeout( r, 0 ) );
}

let env;

beforeEach( () => {
	env = createMwEnv( window );
} );

afterEach( () => {
	delete window.ve;
} );

describe( 've-media-insert.js — confirmSelectedImage patch', () => {
	test( 'un-spoofs title + canonicaltitle for IIIF results, then restores them', async () => {
		const { MWMediaDialog, seen } = installFakeVe( window );

		loadVeMediaInsert( window );
		await flush();

		const dialog = new MWMediaDialog();
		const info = {
			isInstantIIIF: true,
			title: 'File:Df_dk_0007450.jpg',
			canonicaltitle: 'File:Df_dk_0007450.jpg',
		};
		dialog.selectedImageInfo = info;

		const ret = dialog.confirmSelectedImage();

		// The original ran with the un-spoofed title (what gets baked into
		// the inserted node) …
		expect( seen ).toHaveLength( 1 );
		expect( seen[ 0 ].title ).toBe( 'File:Df_dk_0007450' );
		expect( seen[ 0 ].canonicaltitle ).toBe( 'File:Df_dk_0007450' );
		// … the original return value is preserved …
		expect( ret ).toBe( 'inserted' );
		// … and the cached search-result object is restored to the spoofed
		// title VE needs to keep rendering it in the grid.
		expect( info.title ).toBe( 'File:Df_dk_0007450.jpg' );
		expect( info.canonicaltitle ).toBe( 'File:Df_dk_0007450.jpg' );
	} );

	test( 'leaves non-IIIF results untouched', async () => {
		const { MWMediaDialog, seen } = installFakeVe( window );

		loadVeMediaInsert( window );
		await flush();

		const dialog = new MWMediaDialog();
		dialog.selectedImageInfo = { title: 'File:Real_photo.jpg' };

		dialog.confirmSelectedImage();

		// A Commons/local `.jpg` keeps its extension.
		expect( seen[ 0 ].title ).toBe( 'File:Real_photo.jpg' );
	} );

	test( 'tolerates a missing selectedImageInfo', async () => {
		const { MWMediaDialog, seen } = installFakeVe( window );

		loadVeMediaInsert( window );
		await flush();

		const dialog = new MWMediaDialog();
		dialog.selectedImageInfo = null;

		expect( () => dialog.confirmSelectedImage() ).not.toThrow();
		expect( seen[ 0 ] ).toEqual( { title: null, canonicaltitle: null } );
	} );

	test( 'un-spoofs title even when canonicaltitle is absent', async () => {
		const { MWMediaDialog, seen } = installFakeVe( window );

		loadVeMediaInsert( window );
		await flush();

		const dialog = new MWMediaDialog();
		const info = { isInstantIIIF: true, title: 'File:Bsb11610364.jpg' };
		dialog.selectedImageInfo = info;

		dialog.confirmSelectedImage();

		expect( seen[ 0 ].title ).toBe( 'File:Bsb11610364' );
		expect( seen[ 0 ].canonicaltitle ).toBeUndefined();
		expect( info.title ).toBe( 'File:Bsb11610364.jpg' );
	} );

	test( 'un-spoofs canonicaltitle even when title is absent', async () => {
		const { MWMediaDialog, seen } = installFakeVe( window );

		loadVeMediaInsert( window );
		await flush();

		const dialog = new MWMediaDialog();
		const info = {
			isInstantIIIF: true,
			canonicaltitle: 'File:Bsb11610364.jpg',
		};
		dialog.selectedImageInfo = info;

		dialog.confirmSelectedImage();

		expect( seen[ 0 ].title ).toBeUndefined();
		expect( seen[ 0 ].canonicaltitle ).toBe( 'File:Bsb11610364' );
	} );

	test( 'does not re-patch the prototype when loaded twice', async () => {
		const { MWMediaDialog } = installFakeVe( window );

		loadVeMediaInsert( window );
		await flush();
		const patched = MWMediaDialog.prototype.confirmSelectedImage;

		// A second module load (e.g. VE re-initialised) must short-circuit on
		// the _instantIIIFPatched guard rather than wrap the wrapper.
		loadVeMediaInsert( window );
		await flush();

		expect( MWMediaDialog.prototype.confirmSelectedImage ).toBe( patched );
	} );

	test( 'does nothing when ve.ui.MWMediaDialog is unavailable', async () => {
		// ve present but no dialog class (e.g. a stripped editor bundle).
		window.ve = { ui: {} };

		loadVeMediaInsert( window );
		await flush();

		expect( window.ve.ui.MWMediaDialog ).toBeUndefined();
	} );

	test( 'does nothing when ve is absent entirely', async () => {
		// No window.ve at all — patch guard returns without throwing.
		loadVeMediaInsert( window );
		await flush();

		expect( window.ve ).toBeUndefined();
	} );

	test( 'swallows a failed mwimage module load', async () => {
		installFakeVe( window );
		// Simulate the editor-image module failing to load.
		env.mw.loader.using = () =>
			Promise.reject( new Error( 'load failed' ) );

		loadVeMediaInsert( window );
		await flush();

		// No throw / unhandled rejection; prototype stays unpatched.
		expect(
			window.ve.ui.MWMediaDialog.prototype._instantIIIFPatched
		).toBeUndefined();
	} );
} );
