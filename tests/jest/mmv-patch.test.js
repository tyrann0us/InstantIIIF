/**
 * Tests for resources/mmv-patch.js — the client-side MMV patching module.
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

// ─── Navigate-link class removal ─────────────────────────

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

		expect( navigateLink.classList.contains( 'mw-file-description' ) ).toBe(
			false
		);
		expect( otherLink.classList.contains( 'mw-file-description' ) ).toBe(
			true
		);
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

// ─── Shared-upload link replacement ───────────────────────

describe( 'shared-upload notice link fix', () => {
	test( 'replaces local wiki link with provider URL', () => {
		buildDom( `
			<div class="sharedUploadNotice">
				<a href="/wiki/File:Df_dk_0007450.jpg">More info</a>
			</div>
		` );

		env.config.set(
			'wgIIIFProviderUrl',
			'https://www.deutschefotothek.de/documents/obj/12345'
		);
		loadMmvPatch( window );
		env.mw.hook( 'wikipage.content' ).fire();

		const link = document.querySelector( '.sharedUploadNotice a' );
		expect( link.href ).toBe(
			'https://www.deutschefotothek.de/documents/obj/12345'
		);
	} );

	test( 'replaces absolute local link with provider URL', () => {
		buildDom( `
			<div class="sharedUploadNotice">
				<a href="${ window.location.origin }/wiki/File:Test.jpg">More info</a>
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
				<a href="/wiki/File:Test.jpg">More info</a>
			</div>
		` );

		// wgIIIFProviderUrl not set → mw.config.get returns null
		loadMmvPatch( window );
		env.mw.hook( 'wikipage.content' ).fire();

		const link = document.querySelector( '.sharedUploadNotice a' );
		expect( link.getAttribute( 'href' ) ).toBe( '/wiki/File:Test.jpg' );
	} );
} );

// ─── Title spoofing (mw.Title.newFromImg) ────────────────

describe( 'mw.Title.newFromImg override', () => {
	test( 'returns title from data-iiif-title attribute', async () => {
		buildDom(
			'<img id="t" data-iiif-title="File:Df_dk_0007450.jpg" src="/thumb.jpg" />'
		);

		loadMmvPatch( window );
		// The Title override is set inside mw.loader.using('mediawiki.Title').then(...)
		// which resolves immediately in our mock, but still async.
		await new Promise( ( r ) => setTimeout( r, 0 ) );

		const img = document.getElementById( 't' );
		const title = env.mw.Title.newFromImg( img );

		expect( title ).not.toBeNull();
		expect( title._text ).toBe( 'File:Df_dk_0007450.jpg' );
	} );

	test( 'falls back to data-mwtitle attribute', async () => {
		buildDom(
			'<img id="t" data-mwtitle="File:Regular.jpg" src="/thumb.jpg" />'
		);

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
		buildDom(
			'<img id="t" data-iiif-title="File:Test.jpg" src="/thumb.jpg" />'
		);

		loadMmvPatch( window );
		await new Promise( ( r ) => setTimeout( r, 0 ) );

		// Simulate jQuery wrapper: { jquery: true, 0: element, length: 1 }
		const el = document.getElementById( 't' );
		const jqImg = { jquery: '3.7.1', 0: el, length: 1 };

		const title = env.mw.Title.newFromImg( jqImg );
		expect( title._text ).toBe( 'File:Test.jpg' );
	} );
} );

// ─── ThumbnailInfo page fragment ──────────────────────────

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
		FakeThumbnailInfo.prototype.get = function (
			file,
			sampleUrl,
			width,
			height
		) {
			getCalls.push( { file, sampleUrl, width, height } );
			return { then: () => {} };
		};

		env.registerModule( 'mmv', { ThumbnailInfo: FakeThumbnailInfo } );

		loadMmvPatch( window );

		// Wait for all async operations (mw.loader.using promises).
		await new Promise( ( r ) => setTimeout( r, 10 ) );

		// Call the patched ThumbnailInfo.get with the page-2 URL.
		const instance = new FakeThumbnailInfo();
		instance.get(
			'File:Test.jpg',
			'https://iiif.example/img002/full/800,/0/default.jpg',
			600,
			400
		);

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
		FakeThumbnailInfo.prototype.get = function (
			file,
			sampleUrl,
			width,
			height
		) {
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
		FakeThumbnailInfo.prototype.get = function (
			file,
			sampleUrl,
			width,
			height
		) {
			getCalls.push( { file, sampleUrl, width, height } );
			return { then: () => {} };
		};

		env.registerModule( 'mmv', { ThumbnailInfo: FakeThumbnailInfo } );

		loadMmvPatch( window );
		await new Promise( ( r ) => setTimeout( r, 10 ) );

		const instance = new FakeThumbnailInfo();
		instance.get(
			'File:Test.jpg',
			'https://iiif.example/img002/full/800,/0/default.jpg',
			0,
			0
		);

		expect( getCalls[ 0 ].sampleUrl ).toContain( '#page2-300px' );
	} );
} );

// ─── MMV image link fix (mmv-metadata) ──────────────────

describe( 'MMV image link fix via mmv-metadata', () => {
	test( 'replaces MMV overlay image link with data-iiif-full-url', async () => {
		const thumbnailEl = document.createElement( 'img' );
		thumbnailEl.setAttribute( 'data-iiif-title', 'File:Test.jpg' );
		thumbnailEl.setAttribute(
			'data-iiif-full-url',
			'https://iiif.example/page2/full/full/0/default.jpg'
		);

		buildDom(
			'<div class="mw-mmv-image"><a href="https://iiif.example/page1/full/full/0/default.jpg">image</a></div>'
		);

		env.registerModule( 'mmv', { ThumbnailInfo: class {} } );
		env.registerModule( 'mmv.ui.reuse', {
			Share: ( function () {
				function S() {}
				S.prototype.set = function () {};
				return S;
			} )(),
		} );

		loadMmvPatch( window );
		await new Promise( ( r ) => setTimeout( r, 10 ) );

		// Fire mmv-metadata event with our thumbnail.
		env.triggerJqEvent( 'mmv-metadata', {
			image: {
				thumbnail: thumbnailEl,
				src: 'https://iiif.example/thumb.jpg',
			},
		} );

		const mmvLink = document.querySelector( '.mw-mmv-image a' );
		expect( mmvLink.href ).toBe(
			'https://iiif.example/page2/full/full/0/default.jpg'
		);
	} );

	test( 'does not touch MMV link when data-iiif-full-url is absent', async () => {
		const thumbnailEl = document.createElement( 'img' );
		thumbnailEl.setAttribute( 'data-iiif-title', 'File:Test.jpg' );
		// No data-iiif-full-url — page 1 scenario.

		const originalHref =
			'https://iiif.example/page1/full/full/0/default.jpg';
		buildDom(
			`<div class="mw-mmv-image"><a href="${ originalHref }">image</a></div>`
		);

		env.registerModule( 'mmv', { ThumbnailInfo: class {} } );
		env.registerModule( 'mmv.ui.reuse', {
			Share: ( function () {
				function S() {}
				S.prototype.set = function () {};
				return S;
			} )(),
		} );

		loadMmvPatch( window );
		await new Promise( ( r ) => setTimeout( r, 10 ) );

		env.triggerJqEvent( 'mmv-metadata', {
			image: {
				thumbnail: thumbnailEl,
				src: 'https://iiif.example/thumb.jpg',
			},
		} );

		const mmvLink = document.querySelector( '.mw-mmv-image a' );
		expect( mmvLink.href ).toBe( originalHref );
	} );
} );

// ─── Non-IIIF images pass through unchanged ─────────────

describe( 'non-IIIF image passthrough', () => {
	test( 'mmv-metadata handler returns early for non-IIIF images', async () => {
		const thumbnailEl = document.createElement( 'img' );
		// No data-iiif-title → not an IIIF image.

		const originalHref =
			'https://upload.wikimedia.org/wikipedia/commons/test.jpg';
		buildDom(
			`<div class="mw-mmv-image"><a href="${ originalHref }">image</a></div>`
		);

		env.registerModule( 'mmv', { ThumbnailInfo: class {} } );

		loadMmvPatch( window );
		await new Promise( ( r ) => setTimeout( r, 10 ) );

		env.triggerJqEvent( 'mmv-metadata', {
			image: { thumbnail: thumbnailEl },
		} );

		const mmvLink = document.querySelector( '.mw-mmv-image a' );
		expect( mmvLink.href ).toBe( originalHref );
	} );
} );

// ─── Edge case: invalid data-iiif-page values ──────────────────

describe( 'click capture: invalid page numbers fall back to page 1', () => {
	test.each( [ [ '0' ], [ '-3' ], [ 'abc' ], [ '' ], [ '3.5' ] ] )(
		'data-iiif-page=%j is treated as page 1 (no fragment / no |page=)',
		async ( badPage ) => {
			// If `data-iiif-page` parses to <= 0 or NaN, our share URL
			// must NOT carry a `?page=` / `|page=` argument — otherwise
			// a misconfigured wikitext entry would persistently send
			// readers to a non-existent canvas.
			buildDom(
				'<img data-iiif-title="File:Bsb11610364.jpg" data-iiif-page="' +
					badPage +
					'" />'
			);

			const initial =
				'https://wiki.example/wiki/File:Bsb11610364#/media/File:Bsb11610364.jpg';
			let captured = initial;
			function FakeShare() {
				this.$pageInput = {
					val( v ) {
						if ( v === undefined ) {
							return captured;
						}
						captured = v;
					},
				};
			}
			FakeShare.prototype.set = function () {
				this.$pageInput.val( initial );
			};

			env.registerModule( 'mmv', { ThumbnailInfo: class {} } );
			env.registerModule( 'mmv.ui.reuse', { Share: FakeShare } );

			loadMmvPatch( window );
			await new Promise( ( r ) => setTimeout( r, 10 ) );

			// Capture click on the bad thumbnail.
			document
				.querySelector( 'img' )
				.dispatchEvent( new Event( 'click', { bubbles: true } ) );
			await new Promise( ( r ) => setTimeout( r, 10 ) );

			new FakeShare().set( {} );

			expect( captured ).not.toMatch( /[?&|]page=/ );
		}
	);
} );

// ─── Wikitext embed cleanup ────────────────────────────────────

describe( 'Wikitext embed: strip spoofed .jpg from title', () => {
	test( 'EmbedFileFormatter.getThumbnailWikitext drops the spoofed .jpg', async () => {
		// Hooks appends `.jpg` to the data-iiif-title that MMV picks up
		// for imageInfo.title. When MMV builds a `[[File:Foo.jpg|…]]`
		// snippet from that title, copy-pasting it into another article
		// would yield a broken file link, because the real wiki file
		// lives at `File:Foo` (the IIIF object ID). The patched
		// formatter must rewrite the title to the un-spoofed form.

		buildDom(
			'<img data-iiif-title="File:Bsb11610364.jpg" data-iiif-page="6" />'
		);

		function FakeEmbedFileFormatter() {}
		FakeEmbedFileFormatter.prototype.getThumbnailWikitext = function (
			title,
			width,
			caption
		) {
			return (
				'[[' +
				title.getPrefixedText() +
				'|thumb|' +
				( caption || '' ) +
				']]'
			);
		};

		env.registerModule( 'mmv', { ThumbnailInfo: class {} } );
		env.registerModule( 'mmv.ui.reuse', {
			Share: ( function () {
				function S() {}
				S.prototype.set = function () {};
				return S;
			} )(),
			EmbedFileFormatter: FakeEmbedFileFormatter,
		} );

		loadMmvPatch( window );
		await new Promise( ( r ) => setTimeout( r, 10 ) );

		const thumbnailEl = document.querySelector( 'img[data-iiif-page="6"]' );
		env.triggerJqEvent( 'mmv-metadata', {
			image: {
				thumbnail: thumbnailEl,
				src: 'https://iiif.example/thumb.jpg',
			},
		} );
		await new Promise( ( r ) => setTimeout( r, 10 ) );

		const fmt = new FakeEmbedFileFormatter();
		const out = fmt.getThumbnailWikitext(
			{ getPrefixedText: () => 'File:Bsb11610364.jpg' },
			800,
			'Bsb11610364'
		);

		expect( out ).toContain( 'File:Bsb11610364' );
		expect( out ).not.toMatch( /File:Bsb11610364\.jpg/ );
		expect( out ).toMatch( /\|page=6/ );
	} );
} );

// ─── Share URL preserves #/media/ fragment ─────

describe( 'Share URL patch (preserves #/media/ fragment)', () => {
	test( "single-page IIIF: passes MMV's share URL through unchanged", async () => {
		// MMV produces `descriptionUrl + #/media/Title`; for single-page
		// images we have nothing to add, so the value must be untouched.

		const thumbnailEl = document.createElement( 'img' );
		thumbnailEl.setAttribute( 'data-iiif-title', 'File:Df_dk_0007450.jpg' );

		buildDom( '<img data-iiif-title="File:Df_dk_0007450.jpg" />' );

		const initial =
			'https://wiki.example/wiki/File:Df_dk_0007450.jpg#/media/File:Df_dk_0007450';
		let captured = initial;

		function FakeShare() {
			this.$pageInput = {
				val( v ) {
					if ( v === undefined ) {
						return captured;
					}
					captured = v;
				},
			};
		}
		// Original Share.set seeds the input with MMV's natural value.
		FakeShare.prototype.set = function () {
			this.$pageInput.val( initial );
		};

		env.registerModule( 'mmv', { ThumbnailInfo: class {} } );
		env.registerModule( 'mmv.ui.reuse', { Share: FakeShare } );

		loadMmvPatch( window );
		await new Promise( ( r ) => setTimeout( r, 10 ) );

		env.triggerJqEvent( 'mmv-metadata', {
			image: {
				thumbnail: thumbnailEl,
				src: 'https://iiif.example/thumb.jpg',
			},
		} );
		await new Promise( ( r ) => setTimeout( r, 10 ) );

		const shareInstance = new FakeShare();
		shareInstance.set( { thumbnail: thumbnailEl } );

		expect( captured ).toBe( initial );
	} );

	test( 'multi-page IIIF: inserts ?page=N before the #/media/ fragment', async () => {
		const thumbnailEl = document.createElement( 'img' );
		thumbnailEl.setAttribute( 'data-iiif-title', 'File:Bsb11610364.jpg' );
		thumbnailEl.setAttribute( 'data-iiif-page', '6' );

		buildDom(
			'<img data-iiif-title="File:Bsb11610364.jpg" data-iiif-page="6" />'
		);

		const initial =
			'https://wiki.example/wiki/File:Bsb11610364.jpg#/media/File:Bsb11610364.jpg';
		let captured = initial;

		function FakeShare() {
			this.$pageInput = {
				val( v ) {
					if ( v === undefined ) {
						return captured;
					}
					captured = v;
				},
			};
		}
		FakeShare.prototype.set = function () {
			this.$pageInput.val( initial );
		};

		env.registerModule( 'mmv', { ThumbnailInfo: class {} } );
		env.registerModule( 'mmv.ui.reuse', { Share: FakeShare } );

		loadMmvPatch( window );
		await new Promise( ( r ) => setTimeout( r, 10 ) );

		env.triggerJqEvent( 'mmv-metadata', {
			image: {
				thumbnail: thumbnailEl,
				src: 'https://iiif.example/thumb.jpg',
			},
		} );
		await new Promise( ( r ) => setTimeout( r, 10 ) );

		const shareInstance = new FakeShare();
		shareInstance.set( { thumbnail: thumbnailEl } );

		expect( captured ).toBe(
			'https://wiki.example/wiki/File:Bsb11610364.jpg?page=6#/media/File:Bsb11610364'
		);
	} );

	test( 'strips spoofed .jpg from #/media/<title>.jpg fragment', async () => {
		// Sharing a URL whose hash carries the spoofed `.jpg` breaks the
		// landing-page MMV lookup: the file detail page's thumb is keyed
		// off the un-spoofed wgTitle, so MMV's hashchange handler errors
		// with "the file … is not present on the current page". The
		// rewriter must drop the `.jpg` from the fragment without
		// touching the URL's path before `#`.

		const thumbnailEl = document.createElement( 'img' );
		thumbnailEl.setAttribute( 'data-iiif-title', 'File:Df_dk_0007450.jpg' );

		buildDom( '<img data-iiif-title="File:Df_dk_0007450.jpg" />' );

		const initial =
			'https://wiki.example/wiki/File:Df_dk_0007450#/media/File:Df_dk_0007450.jpg';
		let captured = initial;
		function FakeShare() {
			this.$pageInput = {
				val( v ) {
					if ( v === undefined ) {
						return captured;
					}
					captured = v;
				},
			};
		}
		FakeShare.prototype.set = function () {
			this.$pageInput.val( initial );
		};

		env.registerModule( 'mmv', { ThumbnailInfo: class {} } );
		env.registerModule( 'mmv.ui.reuse', { Share: FakeShare } );

		loadMmvPatch( window );
		await new Promise( ( r ) => setTimeout( r, 10 ) );

		env.triggerJqEvent( 'mmv-metadata', {
			image: {
				thumbnail: thumbnailEl,
				src: 'https://iiif.example/thumb.jpg',
			},
		} );
		await new Promise( ( r ) => setTimeout( r, 10 ) );

		const shareInstance = new FakeShare();
		shareInstance.set( { thumbnail: thumbnailEl } );

		expect( captured ).toBe(
			'https://wiki.example/wiki/File:Df_dk_0007450#/media/File:Df_dk_0007450'
		);
	} );

	test( 'multi-page IIIF: leaves existing page= parameter alone', async () => {
		// When MMV's descriptionUrl already carries `?page=N` (because the
		// imageinfo API call did include iiurlparam), don't tack on a
		// second one.
		const thumbnailEl = document.createElement( 'img' );
		thumbnailEl.setAttribute( 'data-iiif-title', 'File:Bsb11610364.jpg' );
		thumbnailEl.setAttribute( 'data-iiif-page', '6' );

		buildDom(
			'<img data-iiif-title="File:Bsb11610364.jpg" data-iiif-page="6" />'
		);

		const initial =
			'https://wiki.example/wiki/File:Bsb11610364.jpg?page=6#/media/File:Bsb11610364';
		let captured = initial;

		function FakeShare() {
			this.$pageInput = {
				val( v ) {
					if ( v === undefined ) {
						return captured;
					}
					captured = v;
				},
			};
		}
		FakeShare.prototype.set = function () {
			this.$pageInput.val( initial );
		};

		env.registerModule( 'mmv', { ThumbnailInfo: class {} } );
		env.registerModule( 'mmv.ui.reuse', { Share: FakeShare } );

		loadMmvPatch( window );
		await new Promise( ( r ) => setTimeout( r, 10 ) );

		env.triggerJqEvent( 'mmv-metadata', {
			image: {
				thumbnail: thumbnailEl,
				src: 'https://iiif.example/thumb.jpg',
			},
		} );
		await new Promise( ( r ) => setTimeout( r, 10 ) );

		const shareInstance = new FakeShare();
		shareInstance.set( { thumbnail: thumbnailEl } );

		expect( captured ).toBe( initial );
	} );
} );

// ─── Static MMV overlay button patches ─────

describe( 'MMV overlay button patches (multi-page)', () => {
	test( 'patches .mw-mmv-description-page-button href with ?page=N', async () => {
		const thumbnailEl = document.createElement( 'img' );
		thumbnailEl.setAttribute( 'data-iiif-title', 'File:Bsb11610364.jpg' );
		thumbnailEl.setAttribute( 'data-iiif-page', '6' );
		thumbnailEl.setAttribute(
			'data-iiif-full-url',
			'https://iiif.example/bsb11610364_00006/full/full/0/default.jpg'
		);

		buildDom( `
			<a class="mw-mmv-description-page-button"
			   href="https://wiki.example/wiki/File:Bsb11610364.jpg">More details</a>
		` );

		env.registerModule( 'mmv', { ThumbnailInfo: class {} } );
		env.registerModule( 'mmv.ui.reuse', {
			Share: ( function () {
				function S() {}
				S.prototype.set = function () {};
				return S;
			} )(),
		} );

		loadMmvPatch( window );
		await new Promise( ( r ) => setTimeout( r, 10 ) );

		env.triggerJqEvent( 'mmv-metadata', {
			image: {
				thumbnail: thumbnailEl,
				src: 'https://iiif.example/thumb.jpg',
			},
		} );

		const moreBtn = document.querySelector(
			'.mw-mmv-description-page-button'
		);
		expect( moreBtn.getAttribute( 'href' ) ).toMatch( /page=6/ );
	} );

	test( 'patches .mw-mmv-download-button href to data-iiif-full-url', async () => {
		const thumbnailEl = document.createElement( 'img' );
		thumbnailEl.setAttribute( 'data-iiif-title', 'File:Bsb11610364.jpg' );
		thumbnailEl.setAttribute( 'data-iiif-page', '6' );
		thumbnailEl.setAttribute(
			'data-iiif-full-url',
			'https://iiif.example/bsb11610364_00006/full/full/0/default.jpg'
		);

		// Simulate MMV's pre-image bar: download button currently points at
		// canvas 1 because the imageinfo API was queried before transform.
		buildDom( `
			<a class="mw-mmv-download-button"
			   href="https://iiif.example/bsb11610364_00001/full/full/0/default.jpg">DL</a>
		` );

		env.registerModule( 'mmv', { ThumbnailInfo: class {} } );
		env.registerModule( 'mmv.ui.reuse', {
			Share: ( function () {
				function S() {}
				S.prototype.set = function () {};
				return S;
			} )(),
		} );

		loadMmvPatch( window );
		await new Promise( ( r ) => setTimeout( r, 10 ) );

		env.triggerJqEvent( 'mmv-metadata', {
			image: {
				thumbnail: thumbnailEl,
				src: 'https://iiif.example/thumb.jpg',
			},
		} );

		const dlBtn = document.querySelector( '.mw-mmv-download-button' );
		expect( dlBtn.getAttribute( 'href' ) ).toContain( 'bsb11610364_00006' );
		expect( dlBtn.getAttribute( 'href' ) ).not.toContain(
			'bsb11610364_00001'
		);
	} );

	test( 'does NOT append ?page= for page-1 thumbnails', async () => {
		const thumbnailEl = document.createElement( 'img' );
		thumbnailEl.setAttribute( 'data-iiif-title', 'File:Df_dk_0007450.jpg' );
		thumbnailEl.setAttribute( 'data-iiif-page', '1' );

		buildDom( `
			<a class="mw-mmv-description-page-button"
			   href="https://wiki.example/wiki/File:Df_dk_0007450.jpg">More details</a>
		` );

		env.registerModule( 'mmv', { ThumbnailInfo: class {} } );
		env.registerModule( 'mmv.ui.reuse', {
			Share: ( function () {
				function S() {}
				S.prototype.set = function () {};
				return S;
			} )(),
		} );

		loadMmvPatch( window );
		await new Promise( ( r ) => setTimeout( r, 10 ) );

		env.triggerJqEvent( 'mmv-metadata', {
			image: {
				thumbnail: thumbnailEl,
				src: 'https://iiif.example/thumb.jpg',
			},
		} );

		const moreBtn = document.querySelector(
			'.mw-mmv-description-page-button'
		);
		expect( moreBtn.getAttribute( 'href' ) ).not.toContain( 'page=' );
	} );
} );

// ─── Client-side pagination resilience ─────────────────────

describe( 'iiifPageByUrl rebuild on wikipage.content', () => {
	test( 'picks up new img[data-iiif-page] after content swap', async () => {
		// Initial DOM has only a page-1 image (no multi-page entries).
		buildDom( `
			<img src="https://iiif.example/p1.jpg"
				data-iiif-page="1" data-iiif-title="File:Test.jpg" />
		` );

		const getCalls = [];
		function FakeThumbnailInfo() {}
		FakeThumbnailInfo.prototype.get = function (
			file,
			sampleUrl,
			width,
			height
		) {
			getCalls.push( { file, sampleUrl, width, height } );
			return { then: () => {} };
		};
		env.registerModule( 'mmv', { ThumbnailInfo: FakeThumbnailInfo } );

		loadMmvPatch( window );
		await new Promise( ( r ) => setTimeout( r, 10 ) );

		// Simulate mediawiki.page.image.pagination swapping content to
		// page 7 of the multi-page file via AJAX + pushState. After
		// content replacement, fire wikipage.content so MMV's bootstrap
		// (and our rebuild handler) re-process the DOM.
		buildDom( `
			<div id="file">
				<a class="mw-file-description" href="https://iiif.example/p7/full/full/0/default.jpg">
					<img src="https://iiif.example/p7.jpg"
						data-iiif-page="7" data-iiif-title="File:Test.jpg"
						data-iiif-full-url="https://iiif.example/p7/full/full/0/default.jpg"
						data-file-width="1200" data-file-height="1600" />
				</a>
			</div>
		` );
		env.hookRegistry[ 'wikipage.content' ].fire( $( document.body ) );

		// Calling ThumbnailInfo.get with the NEW page-7 sampleUrl should
		// see it in the rebuilt map and append the page marker.
		const instance = new FakeThumbnailInfo();
		instance.get(
			'File:Test.jpg',
			'https://iiif.example/p7.jpg',
			600,
			400
		);

		expect( getCalls.length ).toBe( 1 );
		expect( getCalls[ 0 ].sampleUrl ).toContain( '#page7-600px' );
	} );
} );

describe( 'MultimediaViewer.loadImage refresh from current #file img', () => {
	test( 'overrides image.src/originalWidth/originalHeight from current DOM', async () => {
		buildDom( `
			<div id="file">
				<a class="mw-file-description" href="#">
					<img src="https://iiif.example/CURRENT.jpg"
						data-iiif-title="File:Test.jpg"
						data-iiif-page="5"
						data-file-width="1600" data-file-height="1200" />
				</a>
			</div>
		` );

		const loadImageCalls = [];
		function FakeMultimediaViewer() {}
		FakeMultimediaViewer.prototype.loadImage = function ( image ) {
			loadImageCalls.push( {
				src: image.src,
				originalWidth: image.originalWidth,
				originalHeight: image.originalHeight,
			} );
		};

		env.registerModule( 'mmv', {
			MultimediaViewer: FakeMultimediaViewer,
			ThumbnailInfo: class {},
		} );
		env.registerModule( 'mmv.ui.reuse', {} );

		loadMmvPatch( window );
		await new Promise( ( r ) => setTimeout( r, 10 ) );

		// Caller passes a STALE LightboxImage (e.g. the click handler
		// closure captured at initial load before AJAX pagination swap).
		const staleImage = {
			src: 'https://iiif.example/STALE.jpg',
			originalWidth: 300,
			originalHeight: 200,
		};
		const viewer = new FakeMultimediaViewer();
		viewer.loadImage( staleImage );

		expect( loadImageCalls.length ).toBe( 1 );
		expect( loadImageCalls[ 0 ].src ).toBe(
			'https://iiif.example/CURRENT.jpg'
		);
		expect( loadImageCalls[ 0 ].originalWidth ).toBe( 1600 );
		expect( loadImageCalls[ 0 ].originalHeight ).toBe( 1200 );
	} );

	test( 'leaves image untouched when no #file img with data-iiif-title is present', async () => {
		// Article-page context: no #file img with our marker.
		buildDom( `
			<img src="https://iiif.example/article-thumb.jpg"
				data-iiif-page="3" data-iiif-title="File:Test.jpg" />
		` );

		const loadImageCalls = [];
		function FakeMultimediaViewer() {}
		FakeMultimediaViewer.prototype.loadImage = function ( image ) {
			loadImageCalls.push( {
				src: image.src,
				originalWidth: image.originalWidth,
			} );
		};

		env.registerModule( 'mmv', {
			MultimediaViewer: FakeMultimediaViewer,
			ThumbnailInfo: class {},
		} );
		env.registerModule( 'mmv.ui.reuse', {} );

		loadMmvPatch( window );
		await new Promise( ( r ) => setTimeout( r, 10 ) );

		const image = {
			src: 'https://iiif.example/article-thumb.jpg',
			originalWidth: 400,
		};
		new FakeMultimediaViewer().loadImage( image );

		expect( loadImageCalls[ 0 ].src ).toBe(
			'https://iiif.example/article-thumb.jpg'
		);
		expect( loadImageCalls[ 0 ].originalWidth ).toBe( 400 );
	} );
} );
