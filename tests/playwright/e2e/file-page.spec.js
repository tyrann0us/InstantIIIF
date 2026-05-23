// @ts-check
const test = require( './fixtures' );
const { expect } = require( '@playwright/test' );

/**
 * File detail page tests for single-page IIIF files.
 */

test.describe( 'File detail page (single-page IIIF)', () => {

	test( 'file page loads and shows the IIIF image', async ( { page } ) => {
		await page.goto( '/wiki/File:Df_dk_0007450' );

		// The file page should exist (not a "no file" message).
		await expect( page.locator( '#file' ) ).toBeVisible();

		// The main image should be present.
		const mainImg = page.locator( '#file img' );
		await expect( mainImg ).toBeVisible();

		// The img should have a data-iiif-title attribute (from the hook).
		await expect( mainImg ).toHaveAttribute( 'data-iiif-title', /File:.*\.jpg/ );
	} );

	test( 'shared-upload notice links to provider, not back to wiki', async ( { page } ) => {
		await page.goto( '/wiki/File:Df_dk_0007450' );

		const notice = page.locator( '.sharedUploadNotice' );
		if ( await notice.count() > 0 ) {
			// The JS module replaces this link asynchronously via
			// mw.hook('wikipage.content') — use a polling assertion.
			const link = notice.locator( 'a' ).first();
			await expect( link ).toHaveAttribute( 'href', /deutschefotothek\.de/ );
		}
	} );

	test( 'file history section is hidden for IIIF files', async ( { page } ) => {
		await page.goto( '/wiki/File:Df_dk_0007450' );

		// The file history heading should be hidden (via inline CSS from the hook).
		const historyHeading = page.locator( '#filehistory' );
		if ( await historyHeading.count() > 0 ) {
			await expect( historyHeading ).toBeHidden();
		}

		// The file info (size, etc.) should also be hidden.
		const fileInfo = page.locator( 'span.fileInfo' );
		if ( await fileInfo.count() > 0 ) {
			await expect( fileInfo ).toBeHidden();
		}
	} );

	test( 'extended metadata shows attribution from manifest', async ( { page } ) => {
		await page.goto( '/wiki/File:Df_dk_0007450' );

		// The metadata table should contain the manifest's attribution.
		const metadataTable = page.locator( '#mw-imagepage-section-metadata' );
		if ( await metadataTable.count() > 0 ) {
			const text = await metadataTable.textContent();
			expect( text ).toContain( 'SLUB' );
		}
	} );
} );
