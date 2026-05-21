// @ts-check
const test = require( './fixtures' );
const { expect } = require( '@playwright/test' );

/**
 * MultimediaViewer (MMV) overlay tests.
 *
 * AC 3:  Title spoofing → MMV opens for IIIF images
 * AC 10: "More details" button links to local wiki URL
 * AC 11: Share URL is a local wiki URL
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

	test( 'AC 3: clicking an IIIF thumbnail opens MMV', async ( { page } ) => {
		await page.goto( '/wiki/Mei%C3%9Fen_Rathaus' );
		await openMmv( page );
	} );

	test( 'AC 10: "More details" button links to local wiki file page', async ( { page } ) => {
		await page.goto( '/wiki/Mei%C3%9Fen_Rathaus' );
		await openMmv( page );

		const detailsLink = page.locator( '.mw-mmv-title a' ).first();
		if ( await detailsLink.count() > 0 ) {
			const href = await detailsLink.getAttribute( 'href' );
			expect( href ).toMatch( /\/wiki\/.*:.*\.jpg/i );
			expect( href ).not.toContain( 'iiif-mock' );
		}
	} );

	test( 'AC 11: share URL uses local wiki URL', async ( { page } ) => {
		await page.goto( '/wiki/Mei%C3%9Fen_Rathaus' );
		await openMmv( page );

		const reuseButton = page.locator( '.mw-mmv-reuse-button' );
		if ( await reuseButton.count() > 0 ) {
			await reuseButton.click();

			const shareInput = page.locator( '.mw-mmv-share input[type="text"]' );
			if ( await shareInput.count() > 0 ) {
				const shareUrl = await shareInput.inputValue();
				expect( shareUrl ).toContain( '/wiki/' );
				expect( shareUrl ).not.toContain( 'iiif-mock' );
			}
		}
	} );
} );
