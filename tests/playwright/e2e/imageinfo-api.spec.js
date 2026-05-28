// @ts-check
const test = require( './fixtures' );
const { expect } = require( '@playwright/test' );

/**
 * API-level guard for the upload-date suppression.
 *
 * VisualEditor's "Insert media" dialog renders an "Uploaded …" row from
 * the `timestamp` field of action=query&prop=imageinfo. IIIF files have no
 * upload timestamp, so without intervention ApiQueryImageInfo falls back to
 * wfTimestamp("now") and the dialog shows a misleading "uploaded a few
 * seconds ago".
 *
 * Suppression lives in PHP (IIIFFile::getTimestamp returns a non-date
 * sentinel so wfTimestamp() yields false and the API emits an empty
 * timestamp). This is the exact data VisualEditor consumes, so asserting
 * on the API response is a robust, UI-independent regression guard — and
 * the only layer that exercises the real wfTimestamp() conversion (the
 * standalone PHPUnit suite stubs wfTimestamp).
 */
test.describe( 'imageinfo API — IIIF upload timestamp', () => {
	/**
	 * @param {import('@playwright/test').APIRequestContext} request
	 * @param {string}                                       title
	 */
	async function imageInfo( request, title ) {
		const res = await request.get( '/api.php', {
			params: {
				action: 'query',
				format: 'json',
				titles: title,
				prop: 'imageinfo',
				iiprop: 'timestamp|url|mediatype',
			},
		} );
		expect( res.ok() ).toBeTruthy();
		const data = await res.json();
		const pages = data.query.pages;
		return pages[ Object.keys( pages )[ 0 ] ];
	}

	test( 'single-page IIIF file reports no upload timestamp', async ( {
		request,
	} ) => {
		const page = await imageInfo( request, 'File:Df_dk_0007450.jpg' );

		// Sanity: the file resolves through the IIIF repo.
		expect( page.imagerepository ).toBe( 'iiif-test' );

		const info = page.imageinfo[ 0 ];
		// The misleading "uploaded just now" timestamp must not be emitted.
		// MediaWiki serialises the unparseable sentinel as an empty/false
		// value, so VisualEditor skips the "Uploaded" row entirely.
		expect( info.timestamp ).toBeFalsy();
	} );

	test( 'multi-page IIIF file reports no upload timestamp', async ( {
		request,
	} ) => {
		const page = await imageInfo( request, 'File:Df_dk_multipage.jpg' );

		expect( page.imagerepository ).toBe( 'iiif-test' );

		const info = page.imageinfo[ 0 ];
		expect( info.timestamp ).toBeFalsy();
	} );
} );
