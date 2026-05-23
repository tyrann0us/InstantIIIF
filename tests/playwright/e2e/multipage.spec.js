// @ts-check
const test = require( './fixtures' );
const { expect } = require( '@playwright/test' );

/**
 * Multi-page IIIF document tests.
 */

test.describe( 'Multi-page IIIF documents', () => {

	test( 'article thumbnails for different pages link to file page with ?page=N', async ( { page } ) => {
		await page.goto( '/wiki/Kornhaus_Mehrseitig' );

		// Wait for thumbnails to render (polling assertion avoids flakiness
		// on first page load when MediaWiki is still building caches).
		const thumbLinks = page.locator( 'a.mw-file-description' );
		await expect( thumbLinks.first() ).toBeVisible();

		// Check that page=2 and page=3 links exist.
		const page2Link = page.locator( 'a.mw-file-description[href*="page=2"]' );
		const page3Link = page.locator( 'a.mw-file-description[href*="page=3"]' );
		await expect( page2Link ).toBeVisible();
		await expect( page3Link ).toBeVisible();
	} );

	test( 'each page thumbnail has data-iiif-page attribute', async ( { page } ) => {
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

	test( 'page 2 thumbnail has data-iiif-full-url attribute', async ( { page } ) => {
		await page.goto( '/wiki/Kornhaus_Mehrseitig' );

		const page2Img = page.locator( 'img[data-iiif-page="2"]' ).first();
		await expect( page2Img ).toBeVisible();

		const fullUrl = await page2Img.getAttribute( 'data-iiif-full-url' );
		expect( fullUrl ).toBeTruthy();
		expect( fullUrl ).toContain( '/full/full/0/default.jpg' );
	} );

	test( 'prev/next navigation thumbnails are marked with data-iiif-navigate', async ( { page } ) => {
		await page.goto( '/wiki/File:Df_dk_multipage?page=2' );

		await page.waitForFunction( () =>
			typeof mw !== 'undefined' &&
			mw.loader.getState( 'ext.instantIIIF.mmvPatch' ) === 'ready',
		{ timeout: 10_000 }
		);

		const navigateImgs = page.locator( 'img[data-iiif-navigate="1"]' );
		const count = await navigateImgs.count();

		if ( count > 0 ) {
			const firstParent = navigateImgs.first().locator( 'xpath=..' );
			await expect( firstParent ).not.toHaveClass( /mw-file-description/, { timeout: 10_000 } );

			for ( let i = 1; i < count; i++ ) {
				const parentLink = navigateImgs.nth( i ).locator( 'xpath=..' );
				const className = await parentLink.getAttribute( 'class' ) || '';
				expect( className ).not.toContain( 'mw-file-description' );
			}
		}
	} );

	test( 'file detail page main image links to correct page IIIF URL', async ( { page } ) => {
		// Page 2 of the multipage document.
		await page.goto( '/wiki/File:Df_dk_multipage?page=2' );

		// The main image link (file-link context) should point to the page 2
		// full-resolution IIIF URL, not page 1.
		const mainLink = page.locator( '#file a' ).first();
		if ( await mainLink.count() > 0 ) {
			const href = await mainLink.getAttribute( 'href' );
			expect( href ).toBeTruthy();
			// Should contain the page 2 service ID, not page 1.
			expect( href ).toContain( 'bsb11610364_00002' );
		}
	} );

	test( 'MMV download button URL uses the displayed page', async ( { page } ) => {
		await page.goto( '/wiki/Kornhaus_Mehrseitig' );

		// Wait for the MMV patch module to be ready.
		await page.waitForFunction( () =>
			typeof mw !== 'undefined' &&
			mw.loader.getState( 'ext.instantIIIF.mmvPatch' ) === 'ready',
		{ timeout: 10_000 }
		);

		// Click the page-2 article thumbnail to open MMV at page 2.
		const page2Thumb = page.locator( 'img[data-iiif-page="2"]' ).first();
		await expect( page2Thumb ).toBeVisible( { timeout: 10_000 } );
		await page2Thumb.click();

		// Wait for MMV overlay (the displayed image).
		await expect( page.locator( '.mw-mmv-image' ) ).toBeVisible( { timeout: 10_000 } );

		// The MMV download URL must reflect canvas 2, not the manifest's
		// first canvas — otherwise clicking "Download original" (or the
		// image itself, which uses the same href) opens the wrong page.
		// The button may be CSS-hidden (`.empty` class) but its href is
		// still inspectable.
		const downloadBtn = page.locator( '.mw-mmv-download-button' );
		await expect( downloadBtn ).toHaveCount( 1, { timeout: 10_000 } );
		await expect.poll(
			async () => downloadBtn.getAttribute( 'href' ),
			{ timeout: 10_000 }
		).toContain( 'bsb11610364_00002' );
	} );

	test( 'MMV "More details" button URL includes ?page=N for multi-page', async ( { page } ) => {
		await page.goto( '/wiki/Kornhaus_Mehrseitig' );

		await page.waitForFunction( () =>
			typeof mw !== 'undefined' &&
			mw.loader.getState( 'ext.instantIIIF.mmvPatch' ) === 'ready',
		{ timeout: 10_000 }
		);

		const page3Thumb = page.locator( 'img[data-iiif-page="3"]' ).first();
		await expect( page3Thumb ).toBeVisible( { timeout: 10_000 } );
		await page3Thumb.click();

		await expect( page.locator( '.mw-mmv-image' ) ).toBeVisible( { timeout: 10_000 } );

		// The "More details" stripe button is often CSS-hidden depending on
		// metadata-panel state; we only care that the href is correct.
		const moreBtn = page.locator( '.mw-mmv-description-page-button' );
		await expect( moreBtn ).toHaveCount( 1, { timeout: 10_000 } );

		// The href must (a) include the page param, and (b) NOT carry the
		// spoofed `.jpg` extension that Hooks adds to data-iiif-title —
		// otherwise the local file-page's File usage listing won't
		// recognise the file as used in any article. Accept both URL
		// forms: `/wiki/File:Foo?page=N` (short URLs) and
		// `/index.php?title=File:Foo&page=N` (MW's fallback for query
		// strings).
		await expect.poll(
			async () => moreBtn.getAttribute( 'href' ),
			{ timeout: 10_000 }
		).toMatch(
			/(?:\/wiki\/|title=)File:Df_dk_multipage(?:[?&][^#]*)?[?&]page=3/
		);
	} );

	test( 'MMV share URL is full local URL with ?page=N and #/media/ fragment', async ( { page } ) => {
		await page.goto( '/wiki/Kornhaus_Mehrseitig' );

		await page.waitForFunction( () =>
			typeof mw !== 'undefined' &&
			mw.loader.getState( 'ext.instantIIIF.mmvPatch' ) === 'ready',
		{ timeout: 10_000 }
		);

		await page.locator( 'img[data-iiif-page="2"]' ).first().click();
		await expect( page.locator( '.mw-mmv-image' ) ).toBeVisible( { timeout: 10_000 } );

		// Wait until MMV has populated the metadata panel so its reuse
		// dialog is wired up before we open it.
		await expect.poll(
			async () => await page.locator( '.mw-mmv-credit' ).innerText(),
			{ timeout: 10_000 }
		).not.toBe( '' );

		await page.locator( '.mw-mmv-reuse-button' ).click();

		// Look for the share-URL input (the only one starting with
		// http://...) and verify it has ALL three required pieces:
		// full URL, `?page=2`, and `#/media/File:…` fragment.
		await expect.poll(
			async () => page.evaluate(
				() => Array.from(
					document.querySelectorAll( 'input.cdx-text-input__input' )
				).map( ( el ) => el.value || '' )
					.find( ( v ) => /^https?:\/\//.test( v ) ) || ''
			),
			{ timeout: 10_000, intervals: [ 250 ] }
		).toMatch(
			// No `.jpg` extension: real-world IIIF file names are
			// extension-less; the spoofed `.jpg` that Hooks adds to
			// data-iiif-title must not leak into the local share URL,
			// otherwise the file-page's File usage listing breaks.
			// Accept both short URLs and index.php fallback forms.
			/^https?:\/\/[^/]+\/(?:wiki\/|index\.php\?title=)File:Df_dk_multipage(?:[?&][^#]*)?[?&]page=2#\/media\/File:/
		);
	} );

	test( 'Wikitext embed contains |page=N for multi-page documents', async ( { page } ) => {
		await page.goto( '/wiki/Kornhaus_Mehrseitig' );

		await page.waitForFunction( () =>
			typeof mw !== 'undefined' &&
			mw.loader.getState( 'ext.instantIIIF.mmvPatch' ) === 'ready',
		{ timeout: 10_000 }
		);

		const page2Thumb = page.locator( 'img[data-iiif-page="2"]' ).first();
		await expect( page2Thumb ).toBeVisible( { timeout: 10_000 } );
		await page2Thumb.click();

		await expect( page.locator( '.mw-mmv-image' ) ).toBeVisible( { timeout: 10_000 } );

		// Wait for the MMV credit/title to populate, which signals MMV
		// has finished wiring up its event handlers — clicking the reuse
		// button before that is a silent no-op (the dialog never opens).
		await expect.poll(
			async () => await page.locator( '.mw-mmv-credit' ).innerText(),
			{ timeout: 10_000 }
		).not.toBe( '' );

		await page.locator( '.mw-mmv-reuse-button' ).click();

		// The reuse dialog contains several cdx-text-input fields (share
		// URL, HTML embed, wikitext). The wikitext one must (a) carry
		// `|page=2`, and (b) NOT carry the spoofed `.jpg` extension —
		// copy-pasting `[[File:Foo.jpg|…]]` would break the file link
		// in the target article since the real file is `File:Foo`.
		await expect.poll(
			async () => page.evaluate(
				() => Array.from(
					document.querySelectorAll( 'input.cdx-text-input__input' )
				).map( ( el ) => el.value || '' )
					.find( ( v ) => v.startsWith( '[[' ) ) || ''
			),
			{ timeout: 10_000, intervals: [ 250 ] }
		).toMatch( /^\[\[File:Df[ _]dk[ _]multipage\|page=2\|/ );
	} );

	test( 'download dialog "Original" URL targets the current canvas', async ( { page } ) => {
		await page.goto( '/wiki/Kornhaus_Mehrseitig' );

		await page.waitForFunction( () =>
			typeof mw !== 'undefined' &&
			mw.loader.getState( 'ext.instantIIIF.mmvPatch' ) === 'ready',
		{ timeout: 10_000 }
		);

		await page.locator( 'img[data-iiif-page="2"]' ).first().click();
		await expect( page.locator( '.mw-mmv-image' ) ).toBeVisible( { timeout: 10_000 } );

		// Wait for MMV to finish populating before opening the download dialog.
		await expect.poll(
			async () => await page.locator( '.mw-mmv-credit' ).innerText(),
			{ timeout: 10_000 }
		).not.toBe( '' );

		await page.locator( '.mw-mmv-download-button' ).click();

		// Select the "Original" size: MMV stores the canvas-1 URL it
		// got from its iiurlparam-less initial imageinfo call and wires
		// the download button to it — we override the download pane's
		// local `image.url` to the canvas the user is actually viewing.
		const sizeSelect = page.locator( '.mw-mmv-download-dialog select' );
		await expect( sizeSelect ).toBeVisible( { timeout: 10_000 } );
		await sizeSelect.selectOption( 'original' );

		await expect.poll(
			async () => page.evaluate(
				() => Array.from( document.querySelectorAll(
					'.mw-mmv-download-dialog a[href]'
				) )
					.map( ( a ) => a.getAttribute( 'href' ) || '' )
					.find( ( h ) => h.includes( 'bsb11610364' ) ) || ''
			),
			{ timeout: 10_000 }
		).toContain( 'bsb11610364_00002' );
	} );

	test( 'download dialog attribution includes the original work URL', async ( { page } ) => {
		await page.goto( '/wiki/Kornhaus_Mehrseitig' );

		await page.waitForFunction( () =>
			typeof mw !== 'undefined' &&
			mw.loader.getState( 'ext.instantIIIF.mmvPatch' ) === 'ready',
		{ timeout: 10_000 }
		);

		await page.locator( 'img[data-iiif-page="2"]' ).first().click();
		await expect( page.locator( '.mw-mmv-image' ) ).toBeVisible( { timeout: 10_000 } );

		await expect.poll(
			async () => await page.locator( '.mw-mmv-credit' ).innerText(),
			{ timeout: 10_000 }
		).not.toBe( '' );

		await page.locator( '.mw-mmv-download-button' ).click();

		// Klartext / HTML attribution strings should embed the provider URL
		// (manifest landing page) rather than the local wiki file page so
		// downstream re-users link back to the institution that holds the
		// work. For the BSB multipage fixture this is the
		// mdz-nbn-resolving.de landing URL.
		await expect.poll(
			async () => page.evaluate(
				() => Array.from(
					document.querySelectorAll( 'input.cdx-text-input__input' )
				).map( ( el ) => el.value || '' )
					.find( ( v ) => v.includes( 'Bayerische' ) ) || ''
			),
			{ timeout: 10_000 }
		).toContain( 'mdz-nbn-resolving.de' );
	} );

	test( 'clicking the displayed image in MMV navigates to the current canvas, not page 1', async ( { page } ) => {
		// Intercept the eventual navigation to api.digitale-sammlungen.de
		// (the IIIF canvas URL) so the test doesn't make an external
		// request, and so we can read the URL the patched mmv-viewfile
		// handler chose to navigate to.
		const navigationPromise = page.waitForRequest(
			( req ) => /bsb11610364_\d{5}.*\/full\/full\/0\/default\.jpg/
				.test( req.url() ) && req.isNavigationRequest(),
			{ timeout: 10_000 }
		);

		await page.goto( '/wiki/Kornhaus_Mehrseitig' );

		await page.waitForFunction( () =>
			typeof mw !== 'undefined' &&
			mw.loader.getState( 'ext.instantIIIF.mmvPatch' ) === 'ready',
		{ timeout: 10_000 }
		);

		await page.locator( 'img[data-iiif-page="2"]' ).first().click();
		await expect( page.locator( '.mw-mmv-final-image' ) ).toBeVisible( { timeout: 10_000 } );

		// Click the displayed image — MMV's mmv-viewfile handler would
		// otherwise navigate to the page-1 IIIF URL; our patch should
		// route to canvas 2 instead.
		await page.locator( '.mw-mmv-image' ).click( { force: true } );

		const navRequest = await navigationPromise;
		expect( navRequest.url() ).toContain( 'bsb11610364_00002' );
		expect( navRequest.url() ).not.toContain( 'bsb11610364_00001' );
	} );

	test( 'file detail page main image carries mw-file-description so MMV intercepts clicks', async ( { page } ) => {
		await page.goto( '/wiki/File:Df_dk_multipage?page=2' );

		// The main image link must be tagged so MMV adds its "Open in Media
		// Viewer" stripe button and intercepts clicks. Prev/next
		// thumbnails should still NOT have the class –they navigate to the
		// next page instead of opening MMV).
		const mainLink = page.locator( '#file a' ).first();
		await expect( mainLink ).toHaveClass( /mw-file-description/, { timeout: 10_000 } );

		const navParents = page.locator( 'img[data-iiif-navigate="1"]' )
			.locator( 'xpath=..' );
		const count = await navParents.count();
		for ( let i = 0; i < count; i++ ) {
			const cls = await navParents.nth( i ).getAttribute( 'class' ) || '';
			expect( cls ).not.toContain( 'mw-file-description' );
		}
	} );

	test( 'file detail page shows MMV "Open in Media Viewer" button for extension-less IIIF titles', async ( { page } ) => {
		await page.goto( '/wiki/File:Df_dk_multipage?page=2' );

		// MMV adds `.mw-mmv-view-expanded` inside `.fullMedia` from
		// `processFilePageThumb`. Before our fix, this was skipped
		// because `title.getExtension()` returns "" for extension-less
		// IIIF titles like `Df_dk_multipage`, so MMV's `isValidExtension`
		// rejected the file and silently bailed out. Our patch makes
		// Title.getExtension() report "jpg" for file-namespace titles
		// when the page is an IIIF file detail page, restoring the
		// button.
		await expect( page.locator( '.fullMedia .mw-mmv-view-expanded' ) )
			.toBeVisible( { timeout: 10_000 } );
	} );

	test( '"Original file" link on detail page targets correct canvas', async ( { page } ) => {
		await page.goto( '/wiki/File:Df_dk_multipage?page=2' );

		// MediaWiki core renders "Original file" via $file->getUrl();
		// with our fix that follows lastTransformPage,
		// so the link must point to canvas 2 not canvas 1.
		const origLink = page.locator(
			'a[href*="bsb11610364_00002"], a[href*="bsb11610364_00001"]'
		).first();
		if ( await origLink.count() > 0 ) {
			const href = await origLink.getAttribute( 'href' );
			expect( href ).toContain( 'bsb11610364_00002' );
			expect( href ).not.toContain( 'bsb11610364_00001' );
		}
	} );
} );
