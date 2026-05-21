/**
 * Tests for resources/mmv-patch.js — the client-side MMV patching module.
 *
 * Covers:
 *   AC 3:  Title spoofing (mw.Title.newFromImg override)
 *   AC 5:  Navigate-link class removal (data-iiif-navigate)
 *   AC 7:  ThumbnailInfo page fragment (#pageN-Wpx)
 *   AC 9:  Shared-upload link replacement (wgIIIFProviderUrl)
 *   AC 10: MMV image link fix (data-iiif-full-url)
 *   AC 11: Share URL fix (local wiki URL)
 *   AC 13: Non-IIIF images pass through unchanged
 */

'use strict';

const { createMwEnv, loadMmvPatch } = require( './mw-mock' );

// Fresh JSDOM for each test
function buildDom( bodyHtml ) {
	document.body.innerHTML = bodyHtml || '';
}

let env;

beforeEach( () => {
	document.body.innerHTML = '';
	env = createMwEnv( window );
} );

// ─── AC 5: Navigate-link class removal ─────────────────────────

describe( 'data-iiif-navigate class removal', () => {
	test( 'removes mw-file-description class from parent link of navigate-marked img', () => {
		buildDom( `
			<a class="mw-file-description" href="/wiki/File:Test.jpg?page=2">
				<img src="/thumb/test.jpg" data-iiif-navigate="1" />
			</a>
			<a class="mw-file-description" href="/wiki/File:Other.jpg">
				<img src="/thumb/other.jpg" />
			</a>
		` );

		loadMmvPatch( window );
		env.mw.hook( 'wikipage.content' ).fire();

		const navigateLink = document.querySelector( 'a[href*="page=2"]' );
		const otherLink = document.querySelector( 'a[href*="Other"]' );

		expect( navigateLink.classList.contains( 'mw-file-description' ) ).toBe( false );
		expect( otherLink.classList.contains( 'mw-file-description' ) ).toBe( true );
	} );

	test( 'does nothing when no navigate-marked images exist', () => {
		buildDom( `
			<a class="mw-file-description" href="/wiki/File:Normal.jpg">
				<img src="/thumb/normal.jpg" />
			</a>
		` );

		loadMmvPatch( window );
		env.mw.hook( 'wikipage.content' ).fire();

		const link = document.querySelector( 'a' );
		expect( link.classList.contains( 'mw-file-description' ) ).toBe( true );
	} );
} );

// ─── AC 9: Shared-upload link replacement ───────────────────────

describe( 'shared-upload notice link fix', () => {
	test( 'replaces local wiki link with provider URL', () => {
		buildDom( `
			<div class="sharedUploadNotice">
				<a href="/wiki/Datei:Df_dk_0007450.jpg">More info</a>
			</div>
		` );

		env.config.set( 'wgIIIFProviderUrl', 'https://www.deutschefotothek.de/documents/obj/12345' );
		loadMmvPatch( window );
		env.mw.hook( 'wikipage.content' ).fire();

		const link = document.querySelector( '.sharedUploadNotice a' );
		expect( link.href ).toBe( 'https://www.deutschefotothek.de/documents/obj/12345' );
	} );

	test( 'replaces absolute local link with provider URL', () => {
		buildDom( `
			<div class="sharedUploadNotice">
				<a href="${ window.location.origin }/wiki/Datei:Test.jpg">More info</a>
			</div>
		` );

		env.config.set( 'wgIIIFProviderUrl', 'https://example.org/object/999' );
		loadMmvPatch( window );
		env.mw.hook( 'wikipage.content' ).fire();

		const link = document.querySelector( '.sharedUploadNotice a' );
		expect( link.href ).toBe( 'https://example.org/object/999' );
	} );

	test( 'does not replace external links', () => {
		buildDom( `
			<div class="sharedUploadNotice">
				<a href="https://external.example.org/page">External</a>
			</div>
		` );

		env.config.set( 'wgIIIFProviderUrl', 'https://provider.example/obj/1' );
		loadMmvPatch( window );
		env.mw.hook( 'wikipage.content' ).fire();

		const link = document.querySelector( '.sharedUploadNotice a' );
		expect( link.href ).toBe( 'https://external.example.org/page' );
	} );

	test( 'does nothing when wgIIIFProviderUrl is not set', () => {
		buildDom( `
			<div class="sharedUploadNotice">
				<a href="/wiki/Datei:Test.jpg">More info</a>
			</div>
		` );

		// wgIIIFProviderUrl not set → mw.config.get returns null
		loadMmvPatch( window );
		env.mw.hook( 'wikipage.content' ).fire();

		const link = document.querySelector( '.sharedUploadNotice a' );
		expect( link.getAttribute( 'href' ) ).toBe( '/wiki/Datei:Test.jpg' );
	} );
} );

// ─── AC 3: Title spoofing (mw.Title.newFromImg) ────────────────

describe( 'mw.Title.newFromImg override', () => {
	test( 'returns title from data-iiif-title attribute', async () => {
		buildDom( '<img id="t" data-iiif-title="Datei:Df_dk_0007450.jpg" src="/thumb.jpg" />' );

		loadMmvPatch( window );
		// The Title override is set inside mw.loader.using('mediawiki.Title').then(...)
		// which resolves immediately in our mock, but still async.
		await new Promise( ( r ) => setTimeout( r, 0 ) );

		const img = document.getElementById( 't' );
		const title = env.mw.Title.newFromImg( img );

		expect( title ).not.toBeNull();
		expect( title._text ).toBe( 'Datei:Df_dk_0007450.jpg' );
	} );

	test( 'falls back to data-mwtitle attribute', async () => {
		buildDom( '<img id="t" data-mwtitle="File:Regular.jpg" src="/thumb.jpg" />' );

		loadMmvPatch( window );
		await new Promise( ( r ) => setTimeout( r, 0 ) );

		const img = document.getElementById( 't' );
		const title = env.mw.Title.newFromImg( img );

		expect( title ).not.toBeNull();
		expect( title._text ).toBe( 'File:Regular.jpg' );
	} );

	test( 'falls through to original for images without IIIF attributes', async () => {
		buildDom( '<img id="t" src="/thumb.jpg" />' );

		const origResult = { _text: 'original' };
		const origFn = env.mw.Title.newFromImg;
		env.mw.Title.newFromImg = () => origResult;

		loadMmvPatch( window );
		await new Promise( ( r ) => setTimeout( r, 0 ) );

		// After patch, calling with a plain img should reach the original.
		const img = document.getElementById( 't' );
		const title = env.mw.Title.newFromImg( img );

		// The patched function calls orig() which is our origFn captured at load time.
		// Since we replaced newFromImg BEFORE loadMmvPatch, orig captured our origResult.
		expect( title ).toBe( origResult );
	} );

	test( 'handles jQuery-wrapped img elements', async () => {
		buildDom( '<img id="t" data-iiif-title="Datei:Test.jpg" src="/thumb.jpg" />' );

		loadMmvPatch( window );
		await new Promise( ( r ) => setTimeout( r, 0 ) );

		// Simulate jQuery wrapper: { jquery: true, 0: element, length: 1 }
		const el = document.getElementById( 't' );
		const jqImg = { jquery: '3.7.1', 0: el, length: 1 };

		const title = env.mw.Title.newFromImg( jqImg );
		expect( title._text ).toBe( 'Datei:Test.jpg' );
	} );
} );

// ─── AC 7: ThumbnailInfo page fragment ──────────────────────────

describe( 'ThumbnailInfo page patch', () => {
	test( 'appends #pageN-Wpx fragment for IIIF pages > 1', async () => {
		buildDom( `
			<img src="https://iiif.example/img001/full/800,/0/default.jpg"
			     data-iiif-page="1" data-iiif-title="File:Test.jpg" />
			<img src="https://iiif.example/img002/full/800,/0/default.jpg"
			     data-iiif-page="2" data-iiif-title="File:Test.jpg" />
		` );

		// Set up fake ThumbnailInfo module.
		const getCalls = [];
		function FakeThumbnailInfo() {}
		FakeThumbnailInfo.prototype.get = function ( file, sampleUrl, width, height ) {
			getCalls.push( { file, sampleUrl, width, height } );
			return { then: () => {} };
		};

		env.registerModule( 'mmv', { ThumbnailInfo: FakeThumbnailInfo } );

		loadMmvPatch( window );

		// Wait for all async operations (mw.loader.using promises).
		await new Promise( ( r ) => setTimeout( r, 10 ) );

		// Call the patched ThumbnailInfo.get with the page-2 URL.
		const instance = new FakeThumbnailInfo();
		instance.get( 'File:Test.jpg', 'https://iiif.example/img002/full/800,/0/default.jpg', 600, 400 );

		expect( getCalls.length ).toBe( 1 );
		expect( getCalls[ 0 ].sampleUrl ).toContain( '#page2-600px' );
	} );

	test( 'does not modify sampleUrl for page 1', async () => {
		buildDom( `
			<img src="https://iiif.example/img001/full/800,/0/default.jpg"
			     data-iiif-page="1" data-iiif-title="File:Test.jpg" />
		` );

		const getCalls = [];
		function FakeThumbnailInfo() {}
		FakeThumbnailInfo.prototype.get = function ( file, sampleUrl, width, height ) {
			getCalls.push( { file, sampleUrl, width, height } );
			return { then: () => {} };
		};

		env.registerModule( 'mmv', { ThumbnailInfo: FakeThumbnailInfo } );

		loadMmvPatch( window );
		await new Promise( ( r ) => setTimeout( r, 10 ) );

		const instance = new FakeThumbnailInfo();
		const page1Url = 'https://iiif.example/img001/full/800,/0/default.jpg';
		instance.get( 'File:Test.jpg', page1Url, 600, 400 );

		expect( getCalls.length ).toBe( 1 );
		// Page 1 is not in the map (only page > 1 are stored), so URL passes through.
		expect( getCalls[ 0 ].sampleUrl ).toBe( page1Url );
	} );

	test( 'uses default width 300 when width is falsy', async () => {
		buildDom( `
			<img src="https://iiif.example/img002/full/800,/0/default.jpg"
			     data-iiif-page="2" data-iiif-title="File:Test.jpg" />
		` );

		const getCalls = [];
		function FakeThumbnailInfo() {}
		FakeThumbnailInfo.prototype.get = function ( file, sampleUrl, width, height ) {
			getCalls.push( { file, sampleUrl, width, height } );
			return { then: () => {} };
		};

		env.registerModule( 'mmv', { ThumbnailInfo: FakeThumbnailInfo } );

		loadMmvPatch( window );
		await new Promise( ( r ) => setTimeout( r, 10 ) );

		const instance = new FakeThumbnailInfo();
		instance.get( 'File:Test.jpg', 'https://iiif.example/img002/full/800,/0/default.jpg', 0, 0 );

		expect( getCalls[ 0 ].sampleUrl ).toContain( '#page2-300px' );
	} );
} );

// ─── AC 10: MMV image link fix (mmv-metadata) ──────────────────

describe( 'MMV image link fix via mmv-metadata', () => {
	test( 'replaces MMV overlay image link with data-iiif-full-url', async () => {
		const thumbnailEl = document.createElement( 'img' );
		thumbnailEl.setAttribute( 'data-iiif-title', 'Datei:Test.jpg' );
		thumbnailEl.setAttribute( 'data-iiif-full-url', 'https://iiif.example/page2/full/full/0/default.jpg' );

		buildDom( '<div class="mw-mmv-image"><a href="https://iiif.example/page1/full/full/0/default.jpg">image</a></div>' );

		env.registerModule( 'mmv', { ThumbnailInfo: function () {} } );
		env.registerModule( 'mmv.ui.reuse', {
			Share: ( function () {
				function S() {}
				S.prototype.set = function () {};
				return S;
			}() )
		} );

		loadMmvPatch( window );
		await new Promise( ( r ) => setTimeout( r, 10 ) );

		// Fire mmv-metadata event with our thumbnail.
		env.triggerJqEvent( 'mmv-metadata', {
			image: { thumbnail: thumbnailEl, src: 'https://iiif.example/thumb.jpg' }
		} );

		const mmvLink = document.querySelector( '.mw-mmv-image a' );
		expect( mmvLink.href ).toBe( 'https://iiif.example/page2/full/full/0/default.jpg' );
	} );

	test( 'does not touch MMV link when data-iiif-full-url is absent', async () => {
		const thumbnailEl = document.createElement( 'img' );
		thumbnailEl.setAttribute( 'data-iiif-title', 'Datei:Test.jpg' );
		// No data-iiif-full-url — page 1 scenario.

		const originalHref = 'https://iiif.example/page1/full/full/0/default.jpg';
		buildDom( `<div class="mw-mmv-image"><a href="${ originalHref }">image</a></div>` );

		env.registerModule( 'mmv', { ThumbnailInfo: function () {} } );
		env.registerModule( 'mmv.ui.reuse', {
			Share: ( function () {
				function S() {}
				S.prototype.set = function () {};
				return S;
			}() )
		} );

		loadMmvPatch( window );
		await new Promise( ( r ) => setTimeout( r, 10 ) );

		env.triggerJqEvent( 'mmv-metadata', {
			image: { thumbnail: thumbnailEl, src: 'https://iiif.example/thumb.jpg' }
		} );

		const mmvLink = document.querySelector( '.mw-mmv-image a' );
		expect( mmvLink.href ).toBe( originalHref );
	} );
} );

// ─── AC 13: Non-IIIF images pass through unchanged ─────────────

describe( 'non-IIIF image passthrough', () => {
	test( 'mmv-metadata handler returns early for non-IIIF images', async () => {
		const thumbnailEl = document.createElement( 'img' );
		// No data-iiif-title → not an IIIF image.

		const originalHref = 'https://upload.wikimedia.org/wikipedia/commons/test.jpg';
		buildDom( `<div class="mw-mmv-image"><a href="${ originalHref }">image</a></div>` );

		env.registerModule( 'mmv', { ThumbnailInfo: function () {} } );

		loadMmvPatch( window );
		await new Promise( ( r ) => setTimeout( r, 10 ) );

		env.triggerJqEvent( 'mmv-metadata', {
			image: { thumbnail: thumbnailEl }
		} );

		const mmvLink = document.querySelector( '.mw-mmv-image a' );
		expect( mmvLink.href ).toBe( originalHref );
	} );
} );

// ─── AC 11: Share URL fix ───────────────────────────────────────

describe( 'Share URL fix', () => {
	test( 'Share.set patches $pageInput to local wiki URL', async () => {
		const thumbnailEl = document.createElement( 'img' );
		thumbnailEl.setAttribute( 'data-iiif-title', 'Datei:Df_dk_0007450.jpg' );

		buildDom( '<div class="mw-mmv-image"><a href="#">img</a></div>' );

		let capturedPageInputValue = null;

		function FakeShare() {
			this.$pageInput = {
				val( v ) {
					if ( v !== undefined ) {
						capturedPageInputValue = v;
					}
				}
			};
		}
		FakeShare.prototype.set = function () {};

		env.registerModule( 'mmv', { ThumbnailInfo: function () {} } );
		env.registerModule( 'mmv.ui.reuse', { Share: FakeShare } );

		loadMmvPatch( window );
		await new Promise( ( r ) => setTimeout( r, 10 ) );

		// Trigger mmv-metadata to install the Share patch.
		env.triggerJqEvent( 'mmv-metadata', {
			image: { thumbnail: thumbnailEl, src: 'https://iiif.example/thumb.jpg' }
		} );

		// Wait for mmv.ui.reuse module to load (promise resolution).
		await new Promise( ( r ) => setTimeout( r, 10 ) );

		// Now call the patched Share.set.
		const shareInstance = new FakeShare();
		shareInstance.set( { thumbnail: thumbnailEl } );

		expect( capturedPageInputValue ).toBe( '/wiki/Datei:Df_dk_0007450.jpg' );
	} );
} );
