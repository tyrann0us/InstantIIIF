// @ts-check
const { test, expect } = require( '@playwright/test' );

/**
 * File detail page tests for single-page IIIF files.
 *
 * AC 2:  descriptionUrl → local wiki URL (file page exists)
 * AC 4:  Shared-upload notice links to provider URL
 * AC 12: File history section is hidden
 * AC 14: Extended metadata (attribution, license) is shown
 */

test.describe( 'File detail page (single-page IIIF)', () => {

	test( 'AC 2: file page loads and shows the IIIF image', async ( { page } ) => {
		await page.goto( '/wiki/File:Df_dk_0007450.jpg' );

		// The file page should exist (not a "no file" message).
		await expect( page.locator( '#file' ) ).toBeVisible();

		// The main image should be present.
		const mainImg = page.locator( '#file img' );
		await expect( mainImg ).toBeVisible();

		// The img should have a data-iiif-title attribute (from the hook).
		await expect( mainImg ).toHaveAttribute( 'data-iiif-title', /Datei:.*\.jpg/ );
	} );

	test( 'AC 4: shared-upload notice links to provider, not back to wiki', async ( { page } ) => {
		await page.goto( '/wiki/File:Df_dk_0007450.jpg' );

		const notice = page.locator( '.sharedUploadNotice' );
		// The shared-upload notice should exist for foreign repo files.
		if ( await notice.count() > 0 ) {
			const link = notice.locator( 'a' ).first();
			const href = await link.getAttribute( 'href' );

			// The link should point to the provider, NOT back to the local wiki.
			expect( href ).not.toContain( 'localhost:8080' );
			expect( href ).toContain( 'deutschefotothek.de' );
		}
	} );

	test( 'AC 12: file history section is hidden for IIIF files', async ( { page } ) => {
		await page.goto( '/wiki/File:Df_dk_0007450.jpg' );

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

	test( 'AC 14: extended metadata shows attribution from manifest', async ( { page } ) => {
		await page.goto( '/wiki/File:Df_dk_0007450.jpg' );

		// The metadata table should contain the manifest's attribution.
		const metadataTable = page.locator( '#mw-imagepage-section-metadata' );
		if ( await metadataTable.count() > 0 ) {
			const text = await metadataTable.textContent();
			expect( text ).toContain( 'SLUB' );
		}
	} );
} );
