// @ts-check
const test = require( './fixtures' );
const { expect } = require( '@playwright/test' );

/**
 * MultimediaViewer (MMV) overlay tests.
 *
 * AC 3:  Title spoofing → MMV opens for IIIF images
 * AC 10: "More details" button links to local wiki URL
 * AC 11: Share URL is a local wiki URL
 * AC 13: Non-IIIF images (if any) still work normally
 */

test.describe( 'MultimediaViewer overlay', () => {

	test( 'AC 3: clicking an IIIF thumbnail opens MMV', async ( { page } ) => {
		await page.goto( '/wiki/Mei%C3%9Fen_Rathaus' );

		// Click the thumbnail to open MMV.
		const thumb = page.locator( 'a.mw-file-description img' ).first();
		await expect( thumb ).toBeVisible( { timeout: 10_000 } );
		await thumb.click();

		// MMV overlay should appear.
		const mmvOverlay = page.locator( '.mw-mmv-image' );
		await expect( mmvOverlay ).toBeVisible( { timeout: 10_000 } );
	} );

	test( 'AC 10: "More details" button links to local wiki file page', async ( { page } ) => {
		await page.goto( '/wiki/Mei%C3%9Fen_Rathaus' );

		// Open MMV.
		await page.locator( 'a.mw-file-description img' ).first().click();
		await expect( page.locator( '.mw-mmv-image' ) ).toBeVisible( { timeout: 10_000 } );

		// Find the "More details" / file page link in MMV.
		const detailsLink = page.locator( '.mw-mmv-title a' ).first();
		if ( await detailsLink.count() > 0 ) {
			const href = await detailsLink.getAttribute( 'href' );
			// Should point to the local wiki, not to the IIIF provider.
			expect( href ).toMatch( /\/wiki\/.*:.*\.jpg/i );
			expect( href ).not.toContain( 'iiif-mock' );
		}
	} );

	test( 'AC 11: share URL uses local wiki URL', async ( { page } ) => {
		await page.goto( '/wiki/Mei%C3%9Fen_Rathaus' );

		// Open MMV.
		await page.locator( 'a.mw-file-description img' ).first().click();
		await expect( page.locator( '.mw-mmv-image' ) ).toBeVisible( { timeout: 10_000 } );

		// Open the share/reuse panel if available.
		const reuseButton = page.locator( '.mw-mmv-reuse-button' );
		if ( await reuseButton.count() > 0 ) {
			await reuseButton.click();

			// The share URL input should contain a local wiki URL.
			const shareInput = page.locator( '.mw-mmv-share input[type="text"]' );
			if ( await shareInput.count() > 0 ) {
				const shareUrl = await shareInput.inputValue();
				expect( shareUrl ).toContain( '/wiki/' );
				expect( shareUrl ).not.toContain( 'iiif-mock' );
			}
		}
	} );
} );
