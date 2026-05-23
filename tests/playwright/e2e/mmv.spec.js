// @ts-check
const test = require( './fixtures' );
const { expect } = require( '@playwright/test' );

/**
 * MultimediaViewer (MMV) overlay tests.
 */

async function waitForMmvPatch( page ) {
	await page.waitForFunction( () =>
		typeof mw !== 'undefined' &&
		mw.loader.getState( 'ext.instantIIIF.mmvPatch' ) === 'ready',
	{ timeout: 10_000 }
	);
}

async function openMmv( page ) {
	const thumb = page.locator( 'a.mw-file-description img' ).first();
	await expect( thumb ).toBeVisible( { timeout: 10_000 } );
	await waitForMmvPatch( page );
	await thumb.click();
	await expect( page.locator( '.mw-mmv-image' ) ).toBeVisible( { timeout: 10_000 } );
}

test.describe( 'MultimediaViewer overlay', () => {

	test( 'clicking an IIIF thumbnail opens MMV', async ( { page } ) => {
		await page.goto( '/wiki/Mei%C3%9Fen_Rathaus' );
		await openMmv( page );
	} );

	test( '"More details" button links to local wiki file page', async ( { page } ) => {
		await page.goto( '/wiki/Mei%C3%9Fen_Rathaus' );
		await openMmv( page );

		const detailsLink = page.locator( '.mw-mmv-title a' ).first();
		if ( await detailsLink.count() > 0 ) {
			const href = await detailsLink.getAttribute( 'href' );
			expect( href ).toMatch( /\/wiki\/.*:.*\.jpg/i );
			expect( href ).not.toContain( 'iiif-mock' );
		}
	} );

	test( 'share URL is the full local file page URL with #/media/ fragment', async ( { page } ) => {
		await page.goto( '/wiki/Mei%C3%9Fen_Rathaus' );
		await openMmv( page );

		// Wait for the MMV credit/title to populate so the reuse dialog
		// is wired up before we click it.
		await expect.poll(
			async () => await page.locator( '.mw-mmv-credit' ).innerText(),
			{ timeout: 10_000 }
		).not.toBe( '' );

		await page.locator( '.mw-mmv-reuse-button' ).click();

		// Find the share-URL input (starts with http:// or https://) among
		// the cdx-text-input fields in the reuse dialog.
		await expect.poll(
			async () => page.evaluate(
				() => Array.from(
					document.querySelectorAll( 'input.cdx-text-input__input' )
				).map( ( el ) => el.value || '' )
					.find( ( v ) => /^https?:\/\//.test( v ) ) || ''
			),
			{ timeout: 10_000, intervals: [ 250 ] }
		).toMatch( /^https?:\/\/[^/]+\/wiki\/[^#]+#\/media\/File:/ );
	} );
} );
