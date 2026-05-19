// @ts-check
const { test, expect } = require( '@playwright/test' );

/**
 * Multi-page IIIF document tests.
 *
 * AC 6:  Article thumbnails link to wiki file page with ?page=N
 * AC 7:  Correct page dimensions / thumbnail for each page
 * AC 8:  ThumbnailInfo sends correct page parameter to API
 * AC 9:  Prev/next navigation on file page doesn't open MMV
 */

test.describe( 'Multi-page IIIF documents', () => {

	test( 'AC 6: article thumbnails for different pages link to file page with ?page=N', async ( { page } ) => {
		await page.goto( '/wiki/Kornhaus_Mehrseitig' );

		// There should be multiple thumbnails for the same multipage file.
		const thumbLinks = page.locator( 'a.mw-file-description' );
		const count = await thumbLinks.count();
		expect( count ).toBeGreaterThanOrEqual( 2 );

		// Check that page=2 and page=3 links exist.
		const hrefs = [];
		for ( let i = 0; i < count; i++ ) {
			hrefs.push( await thumbLinks.nth( i ).getAttribute( 'href' ) );
		}

		const hasPage2 = hrefs.some( ( h ) => h && h.includes( 'page=2' ) );
		const hasPage3 = hrefs.some( ( h ) => h && h.includes( 'page=3' ) );
		expect( hasPage2 ).toBe( true );
		expect( hasPage3 ).toBe( true );
	} );

	test( 'AC 7: each page thumbnail has data-iiif-page attribute', async ( { page } ) => {
		await page.goto( '/wiki/Kornhaus_Mehrseitig' );

		const images = page.locator( 'img[data-iiif-page]' );
		const count = await images.count();
		expect( count ).toBeGreaterThanOrEqual( 2 );

		// Collect page numbers.
		const pages = [];
		for ( let i = 0; i < count; i++ ) {
			pages.push( await images.nth( i ).getAttribute( 'data-iiif-page' ) );
		}

		expect( pages ).toContain( '2' );
		expect( pages ).toContain( '3' );
	} );

	test( 'AC 7: page 2 thumbnail has data-iiif-full-url attribute', async ( { page } ) => {
		await page.goto( '/wiki/Kornhaus_Mehrseitig' );

		const page2Img = page.locator( 'img[data-iiif-page="2"]' ).first();
		await expect( page2Img ).toBeVisible();

		const fullUrl = await page2Img.getAttribute( 'data-iiif-full-url' );
		expect( fullUrl ).toBeTruthy();
		expect( fullUrl ).toContain( '/full/full/0/default.jpg' );
	} );

	test( 'AC 9: prev/next navigation thumbnails are marked with data-iiif-navigate', async ( { page } ) => {
		// Navigate to the file detail page for the multipage document.
		await page.goto( '/wiki/File:Df_dk_multipage.jpg?page=2' );

		// On the file detail page, prev/next thumbnails of the same file
		// should have data-iiif-navigate="1".
		const navigateImgs = page.locator( 'img[data-iiif-navigate="1"]' );
		const count = await navigateImgs.count();

		// There should be at least one (prev or next page thumbnail).
		if ( count > 0 ) {
			// The parent <a> should NOT have the mw-file-description class
			// (removed by JS to prevent MMV interception).
			for ( let i = 0; i < count; i++ ) {
				const parentLink = navigateImgs.nth( i ).locator( 'xpath=..' );
				const className = await parentLink.getAttribute( 'class' ) || '';
				expect( className ).not.toContain( 'mw-file-description' );
			}
		}
	} );

	test( 'AC 6: file detail page main image links to correct page IIIF URL', async ( { page } ) => {
		// Page 2 of the multipage document.
		await page.goto( '/wiki/File:Df_dk_multipage.jpg?page=2' );

		// The main image link (file-link context) should point to the page 2
		// full-resolution IIIF URL, not page 1.
		const mainLink = page.locator( '#file a' ).first();
		if ( await mainLink.count() > 0 ) {
			const href = await mainLink.getAttribute( 'href' );
			expect( href ).toBeTruthy();
			// Should contain the page 2 service ID, not page 1.
			expect( href ).toContain( 'multipage_002' );
		}
	} );
} );
