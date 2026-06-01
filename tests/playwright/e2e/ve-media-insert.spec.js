// @ts-check
/* global ve */
const test = require( './fixtures' );
const { expect } = require( '@playwright/test' );

/**
 * VisualEditor "Insert media" dialog must insert IIIF files WITHOUT the
 * spoofed `.jpg` extension, so VE-inserted embeds match hand-written
 * `[[File:<id>]]` wikitext.
 *
 * The dialog still needs the spoofed title to *display* the search result
 * (VE filters media results by file extension — see media-search.js), so the
 * fix strips `.jpg` only when the chosen result is turned into the inserted
 * node (ve.ui.MWMediaDialog#confirmSelectedImage). This drives the real
 * search → choose → confirm → insert → serialize path and asserts the
 * serialized wikitext carries no `.jpg`.
 */
test( 'VE media dialog inserts IIIF files without the spoofed .jpg', async ( {
	page,
} ) => {
	// Defaults to the Docker test page; overridable so the same spec can run
	// against another wiki (e.g. the staging server's Kornhaus article).
	const editPage = process.env.VE_TEST_PAGE || 'Mei%C3%9Fen%20Rathaus';
	await page.goto( '/wiki/' + editPage + '?veaction=edit' );

	await page.waitForFunction(
		() =>
			window.ve &&
			ve.init &&
			ve.init.target &&
			ve.init.target.getSurface &&
			ve.init.target.getSurface() &&
			ve.ui.MWMediaDialog,
		{ timeout: 25_000 }
	);

	const outcome = await page.evaluate( async ( searchId ) => {
		const surface = ve.init.target.getSurface();
		const surfaceModel = surface.getModel();

		// Dismiss any dialog already open — e.g. VE's beta-welcome dialog,
		// which a freshly-configured wiki shows on first edit and which would
		// otherwise sit in front of the media dialog. Harmless when none is
		// open (getCurrentWindow() is null).
		await new Promise( ( r ) => setTimeout( r, 500 ) );
		const dialogs = surface.getDialogs();
		const existing = dialogs.getCurrentWindow();
		if ( existing && existing.constructor.static.name !== 'media' ) {
			await dialogs.closeWindow( existing ).closed;
		}

		// Open the real media dialog the way the toolbar command does.
		surface.execute( 'window', 'open', 'media', {
			surface: surfaceModel,
			fragment: surfaceModel.getFragment(),
		} );
		const dialog = await new Promise( ( resolve, reject ) => {
			const deadline = Date.now() + 10_000;
			( function poll() {
				const d = surface.getDialogs().getCurrentWindow();
				if ( d && d.constructor.static.name === 'media' ) {
					resolve( d );
				} else if ( Date.now() > deadline ) {
					reject( new Error( 'media dialog did not open' ) );
				} else {
					setTimeout( poll, 150 );
				}
			} )();
		} );

		// Drive the dialog's own search (exercises media-search.js).
		dialog.search.getQuery().setValue( searchId );
		dialog.search.queryMediaQueue();

		// Wait for a result tile to appear.
		const items = await new Promise( ( resolve, reject ) => {
			const deadline = Date.now() + 15_000;
			( function poll() {
				const got = dialog.search.getResults().getItems();
				if ( got.length ) {
					resolve( got );
				} else if ( Date.now() > deadline ) {
					reject( new Error( 'no media results' ) );
				} else {
					setTimeout( poll, 200 );
				}
			} )();
		} );

		const data = items[ 0 ].getData();
		// Captured before confirm: this is the title VE actually displayed in
		// the results grid (must stay spoofed for VE to show it at all).
		const displayedTitle = data.title;

		// Choose → confirm → insert exactly as the dialog's UI would.
		dialog.chooseImageInfo( data );
		dialog.confirmSelectedImage();
		dialog.imageModel.insertImageNode( dialog.getFragment() );

		const wikitext = await ve.init.target.getWikitextFragment(
			surfaceModel.getDocument(),
			false
		);

		return {
			resultTitle: displayedTitle, // what VE displayed (stays spoofed)
			restoredTitle: data.title, // patch must restore the spoofed title
			resourceName: dialog.imageModel.getResourceName(), // inserted target
			wikitext,
		};
	}, 'df_dk_0007450' );

	// The search result VE displayed kept its spoofed `.jpg` (required for VE
	// to show it at all) — confirms we exercised the real, working search.
	expect( outcome.resultTitle ).toMatch( /\.jpg$/i );
	// And the patch restored that spoofed title afterwards, leaving VE's
	// cached search-result object untouched for re-rendering the grid.
	expect( outcome.restoredTitle ).toMatch( /\.jpg$/i );

	// The inserted resource and the serialized wikitext must be extension-less.
	expect( outcome.resourceName ).not.toMatch( /\.jpg$/i );
	expect( outcome.wikitext ).toMatch( /\[\[File:Df[ _]dk[ _]0007450/i );
	expect( outcome.wikitext ).not.toMatch( /Df[ _]dk[ _]0007450\.jpg/i );
} );
