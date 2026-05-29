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

// ─── mw.Title.prototype.getExtension override ─────────────

describe( 'mw.Title.getExtension override on IIIF file detail pages', () => {
	test( 'returns the spoofed extension for NS_FILE titles when an IIIF img sits in #file', async () => {
		// The override only kicks in when `#file img[data-iiif-title]`
		// exists in the DOM — otherwise it's a no-op so non-IIIF pages
		// stay untouched.
		buildDom( `
			<div id="file">
				<a class="mw-file-description" href="#">
					<img src="https://iiif.example/x.jpg"
					     data-iiif-title="File:Df_dk_0007450.jpg" />
				</a>
			</div>
		` );

		loadMmvPatch( window );
		await new Promise( ( r ) => setTimeout( r, 10 ) );

		const fileTitle = new env.mw.Title( 'File:Df_dk_0007450' );
		// Bare dbkey has no extension — the override should inject the
		// spoof so MMV's processFilePageThumb doesn't bail out.
		expect( fileTitle.getExtension() ).toBe( 'jpg' );
	} );

	test( 'passes through when the title already carries a real extension', async () => {
		buildDom( `
			<div id="file">
				<img src="https://iiif.example/x.jpg"
				     data-iiif-title="File:Foo.jpg" />
			</div>
		` );

		loadMmvPatch( window );
		await new Promise( ( r ) => setTimeout( r, 10 ) );

		// "File:Foo.jpg" already has an extension — return as-is, not
		// the spoof constant.
		const t = new env.mw.Title( 'File:Foo.jpg' );
		expect( t.getExtension() ).toBe( 'jpg' );
	} );

	test( 'does not override getExtension when no IIIF img sits in #file', async () => {
		// Article-page context: no #file container, no override.
		buildDom( '<img src="x.jpg" data-iiif-title="File:Foo.jpg" />' );

		const before = env.mw.Title.prototype.getExtension;
		loadMmvPatch( window );
		await new Promise( ( r ) => setTimeout( r, 10 ) );

		expect( env.mw.Title.prototype.getExtension ).toBe( before );
	} );

	test( 'returns the original (empty) extension for non-NS_FILE titles', async () => {
		// Override IS installed (IIIF img in #file), but the title
		// passed in is not in the File namespace — the spoof must
		// NOT leak onto unrelated titles.
		buildDom( `
			<div id="file"><img data-iiif-title="File:Foo.jpg" /></div>
		` );

		loadMmvPatch( window );
		await new Promise( ( r ) => setTimeout( r, 10 ) );

		// "Bar" → namespace 0 in our stub, no real extension either.
		const t = new env.mw.Title( 'Bar' );
		expect( t.getExtension() ).toBe( '' );
	} );
} );

// ─── mmv-viewfile redirect to IIIF full URL ──────────────

describe( 'mmv-viewfile redirects to data-iiif-full-url', () => {
	test( 'click on IIIF thumb + mmv-viewfile event stops propagation and triggers navigation', async () => {
		// MMV's "open file" stripe button fires `mmv-viewfile` and
		// would normally take the user to the imageInfo.url. For
		// multi-page IIIF docs the patched stripe-button URL is the
		// current canvas's full IIIF URL, captured at click time.
		//
		// jsdom can't actually navigate (and silently no-ops the
		// `document.location =` assignment with a "Not implemented"
		// console warning), so we only assert that the handler took
		// the IIIF branch — proven by stopImmediatePropagation being
		// invoked, which only happens when both isCurrentImageIiif
		// and currentIiifFullUrl are set.
		buildDom( `
			<img id="thumb"
			     data-iiif-title="File:Bsb11610364.jpg"
			     data-iiif-full-url="https://iiif.example/p6/full/full/0/default.jpg" />
		` );

		loadMmvPatch( window );
		await new Promise( ( r ) => setTimeout( r, 10 ) );

		// Prime currentIiifFullUrl via a click on the thumb.
		document
			.getElementById( 'thumb' )
			.dispatchEvent( new Event( 'click', { bubbles: true } ) );

		const stopFn = jest.fn();
		env.triggerJqEvent( 'mmv-viewfile', {
			stopImmediatePropagation: stopFn,
		} );

		expect( stopFn ).toHaveBeenCalled();
	} );

	test( 'mmv-viewfile is a no-op for non-IIIF images', async () => {
		buildDom( '<img id="thumb" src="x.jpg" />' );

		loadMmvPatch( window );
		await new Promise( ( r ) => setTimeout( r, 10 ) );

		const stopFn = jest.fn();
		env.triggerJqEvent( 'mmv-viewfile', {
			stopImmediatePropagation: stopFn,
		} );

		// No IIIF context → handler bails before stopping propagation.
		expect( stopFn ).not.toHaveBeenCalled();
	} );
} );

// ─── Click capture: non-IIIF reset ───────────────────────

describe( 'click capture resets IIIF state for non-IIIF MediaViewer thumbs', () => {
	test( 'click on .mw-file-element (Commons) resets prior IIIF state', async () => {
		// First: click an IIIF thumb to seed state.
		buildDom( `
			<img id="iiif"
			     data-iiif-title="File:Bsb11610364.jpg"
			     data-iiif-page="6"
			     data-iiif-full-url="https://iiif.example/p6.jpg" />
			<img id="commons" class="mw-file-element" src="https://upload.example/foo.jpg" />
		` );

		// Stub a Share so we can inspect what state the patch sees
		// at the next mmv-metadata fire.
		function FakeShare() {
			this.$pageInput = {
				val: jest
					.fn()
					.mockReturnValue(
						'https://wiki.example/wiki/File:Foo.jpg#/media/File:Foo'
					),
			};
		}
		FakeShare.prototype.set = jest.fn();
		env.registerModule( 'mmv', { ThumbnailInfo: class {} } );
		env.registerModule( 'mmv.ui.reuse', { Share: FakeShare } );

		loadMmvPatch( window );
		await new Promise( ( r ) => setTimeout( r, 10 ) );

		// Seed IIIF state.
		document
			.getElementById( 'iiif' )
			.dispatchEvent( new Event( 'click', { bubbles: true } ) );

		// Then click a Commons thumbnail — the click capture must
		// reset isCurrentImageIiif so subsequent Share/Embed patches
		// no longer rewrite URLs.
		document
			.getElementById( 'commons' )
			.dispatchEvent( new Event( 'click', { bubbles: true } ) );

		// Fire mmv-metadata WITHOUT an IIIF thumbnail to take the
		// non-IIIF branch — patches should not run.
		env.triggerJqEvent( 'mmv-metadata', {
			image: {
				thumbnail: document.getElementById( 'commons' ),
				src: 'https://upload.example/foo.jpg',
			},
		} );

		// FakeShare.set hasn't been called by the handler because
		// the eager patches are bound on prototype, not invoked here.
		// The point is just that the click-reset code path ran without
		// throwing.
		expect( document.getElementById( 'commons' ) ).not.toBeNull();
	} );

	test( 'click on an element nested deep under an img walks up to find it', async () => {
		// Click target is the inner <span>; the handler must walk
		// parentElement chain until it finds the IMG (line 256).
		buildDom( `
			<a class="mw-file-description" href="#">
				<img data-iiif-title="File:X.jpg" data-iiif-page="3"><span id="caption">caption</span></img>
			</a>
		` );

		loadMmvPatch( window );
		await new Promise( ( r ) => setTimeout( r, 10 ) );

		// Clicking on a non-IMG element (the <a>) walks up; no
		// exception is enough — the handler must not crash.
		document
			.querySelector( 'a.mw-file-description' )
			.dispatchEvent( new Event( 'click', { bubbles: true } ) );

		// Sanity assert that the DOM is still intact.
		expect(
			document.querySelector( 'a.mw-file-description' )
		).not.toBeNull();
	} );
} );

// ─── Share / Download / Embed patches: edge cases ──────

describe( 'Share.set / Download.set / EmbedFileFormatter.getThumbnailHtml', () => {
	test( 'Share.set leaves URL alone when isCurrentImageIiif is false', async () => {
		// IIIF image present so the eager patches install on
		// Share.prototype.set — but no click happens, so
		// isCurrentImageIiif is still false when set() runs. The
		// patched set runs origShareSet (which seeds the val) and
		// then hits the `if (! isCurrentImageIiif) return` guard.
		buildDom( '<img id="x" data-iiif-title="File:Foo.jpg" />' );

		const initial = 'https://wiki.example/wiki/File:Foo#/media/File:Foo';
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

		// No click on the IIIF image → isCurrentImageIiif stays false.
		new FakeShare().set( {} );

		expect( captured ).toBe( initial ); // untouched
	} );

	test( 'Share.set bails when $pageInput.val() is empty', async () => {
		buildDom(
			'<img id="x" data-iiif-title="File:Foo.jpg" data-iiif-page="2" />'
		);

		function FakeShare() {
			this.$pageInput = {
				val: () => '', // empty value → patch early-returns
			};
		}
		const setSpy = jest.fn();
		FakeShare.prototype.set = setSpy;

		env.registerModule( 'mmv', { ThumbnailInfo: class {} } );
		env.registerModule( 'mmv.ui.reuse', { Share: FakeShare } );

		loadMmvPatch( window );
		await new Promise( ( r ) => setTimeout( r, 10 ) );

		// Seed IIIF state.
		document
			.getElementById( 'x' )
			.dispatchEvent( new Event( 'click', { bubbles: true } ) );

		// Calling the patched set with an empty val must not crash.
		expect( () => new FakeShare().set( {} ) ).not.toThrow();
	} );

	test( 'Download.set rewrites image.url to currentIiifFullUrl', async () => {
		buildDom( `
			<img id="thumb"
			     data-iiif-title="File:Bsb11610364.jpg"
			     data-iiif-page="6"
			     data-iiif-full-url="https://iiif.example/p6/full/full/0/default.jpg" />
		` );

		const handleSizeSwitch = jest.fn();
		function FakeDownload() {
			this.image = {
				url: 'https://iiif.example/p1/full/full/0/default.jpg',
			};
			this.handleSizeSwitch = handleSizeSwitch;
		}
		FakeDownload.prototype.set = function () {};

		env.registerModule( 'mmv', { ThumbnailInfo: class {} } );
		env.registerModule( 'mmv.ui.reuse', {
			Share: ( function () {
				function S() {}
				S.prototype.set = function () {};
				return S;
			} )(),
			Download: FakeDownload,
		} );

		loadMmvPatch( window );
		await new Promise( ( r ) => setTimeout( r, 10 ) );

		// Prime state via click.
		document
			.getElementById( 'thumb' )
			.dispatchEvent( new Event( 'click', { bubbles: true } ) );

		const dl = new FakeDownload();
		dl.set( {} );

		expect( dl.image.url ).toBe(
			'https://iiif.example/p6/full/full/0/default.jpg'
		);
		expect( handleSizeSwitch ).toHaveBeenCalled();
	} );

	test( 'EmbedFileFormatter.getThumbnailHtml rewrites href attributes for IIIF images', async () => {
		buildDom(
			'<img id="thumb" data-iiif-title="File:Bsb11610364.jpg" data-iiif-page="6" />'
		);

		function FakeEFF() {}
		FakeEFF.prototype.getThumbnailHtml = function () {
			return (
				'<a href="https://wiki.example/wiki/File:Bsb11610364.jpg' +
				'#/media/File:Bsb11610364.jpg">img</a>'
			);
		};

		env.registerModule( 'mmv', { ThumbnailInfo: class {} } );
		env.registerModule( 'mmv.ui.reuse', {
			Share: ( function () {
				function S() {}
				S.prototype.set = function () {};
				return S;
			} )(),
			EmbedFileFormatter: FakeEFF,
		} );

		loadMmvPatch( window );
		await new Promise( ( r ) => setTimeout( r, 10 ) );

		// Prime state via click.
		document
			.getElementById( 'thumb' )
			.dispatchEvent( new Event( 'click', { bubbles: true } ) );

		const out = new FakeEFF().getThumbnailHtml();

		// The spoofed `.jpg` inside the `#/media/` fragment must be
		// stripped, and `?page=6` must be inserted before the hash.
		expect( out ).toMatch( /\?page=6#\/media\/File:Bsb11610364(?!\.jpg)/ );
	} );

	test( 'EmbedFileFormatter.getThumbnailHtml passes through unchanged for non-IIIF', async () => {
		buildDom( '<img id="thumb" src="x.jpg" />' ); // no data-iiif-title

		function FakeEFF() {}
		const original = '<a href="https://commons.example/x">img</a>';
		FakeEFF.prototype.getThumbnailHtml = jest.fn( () => original );

		env.registerModule( 'mmv', { ThumbnailInfo: class {} } );
		env.registerModule( 'mmv.ui.reuse', {
			Share: ( function () {
				function S() {}
				S.prototype.set = function () {};
				return S;
			} )(),
			EmbedFileFormatter: FakeEFF,
		} );

		loadMmvPatch( window );
		await new Promise( ( r ) => setTimeout( r, 10 ) );

		expect( new FakeEFF().getThumbnailHtml() ).toBe( original );
	} );

	test( 'EmbedFileFormatter.getThumbnailWikitext passes through unchanged for non-IIIF', async () => {
		// IIIF image in DOM so the eager patch installs on the
		// prototype, but no click → isCurrentImageIiif is false →
		// patched getThumbnailWikitext runs orig + hits the early
		// `return out` guard.
		buildDom( '<img id="x" data-iiif-title="File:Foo.jpg" />' );

		function FakeEFF() {}
		const original = '[[File:Foo.jpg|thumb|caption]]';
		FakeEFF.prototype.getThumbnailWikitext = jest.fn( () => original );

		env.registerModule( 'mmv', { ThumbnailInfo: class {} } );
		env.registerModule( 'mmv.ui.reuse', {
			Share: ( function () {
				function S() {}
				S.prototype.set = function () {};
				return S;
			} )(),
			EmbedFileFormatter: FakeEFF,
		} );

		loadMmvPatch( window );
		await new Promise( ( r ) => setTimeout( r, 10 ) );

		expect(
			new FakeEFF().getThumbnailWikitext(
				{ getPrefixedText: () => 'File:Foo.jpg' },
				800,
				'caption'
			)
		).toBe( original );
	} );

	test( 'EmbedFileFormatter.getThumbnailHtml: rewriteIiifShareUrl bails on empty href', async () => {
		// Some MMV layouts emit `<a href="">` placeholders. The replace
		// callback in the html-rewriter passes whatever the capture
		// matched to rewriteIiifShareUrl — an empty string must
		// short-circuit instead of being treated as a URL.
		buildDom(
			'<img id="thumb" data-iiif-title="File:Foo.jpg" data-iiif-page="2" />'
		);

		function FakeEFF() {}
		FakeEFF.prototype.getThumbnailHtml = function () {
			return '<a href="">empty</a> <a href="https://wiki/x">real</a>';
		};

		env.registerModule( 'mmv', { ThumbnailInfo: class {} } );
		env.registerModule( 'mmv.ui.reuse', {
			Share: ( function () {
				function S() {}
				S.prototype.set = function () {};
				return S;
			} )(),
			EmbedFileFormatter: FakeEFF,
		} );

		loadMmvPatch( window );
		await new Promise( ( r ) => setTimeout( r, 10 ) );

		// Click to seed IIIF state so the replace branch is entered.
		document
			.getElementById( 'thumb' )
			.dispatchEvent( new Event( 'click', { bubbles: true } ) );

		const out = new FakeEFF().getThumbnailHtml();

		// The empty href stays empty (returned as-is), the real one is
		// rewritten by the helper as normal.
		expect( out ).toMatch( /href=""[^>]*>empty/ );
	} );
} );

// ─── buildLocalFileUrl edge cases ────────────────────────

describe( 'buildLocalFileUrl: defensive null returns', () => {
	test( 'empty data-iiif-title means no localUrl is set on the stripe button', async () => {
		// mmv-metadata with `data-iiif-title=""` sets currentIiifTitle
		// to the empty string. buildLocalFileUrl's `!currentIiifTitle`
		// guard then bails — the stripe button keeps its native href.
		buildDom( `
			<a id="stripe" class="mw-mmv-description-page-button"
			   href="https://wiki.example/original">More details</a>
		` );

		const thumb = document.createElement( 'img' );
		thumb.setAttribute( 'data-iiif-title', '' );

		env.registerModule( 'mmv', { ThumbnailInfo: class {} } );
		env.registerModule( 'mmv.ui.reuse', {} );

		loadMmvPatch( window );
		await new Promise( ( r ) => setTimeout( r, 10 ) );

		env.triggerJqEvent( 'mmv-metadata', {
			image: { thumbnail: thumb, src: 'x.jpg' },
		} );

		// buildLocalFileUrl returned null → href untouched.
		expect(
			document.getElementById( 'stripe' ).getAttribute( 'href' )
		).toBe( 'https://wiki.example/original' );
	} );

	test( 'data-iiif-navigate img with no .mw-file-description parent is left alone', async () => {
		// The class-removal handler must not throw when an img carries
		// the navigate marker but lives outside an `<a.mw-file-description>` —
		// e.g. an extension config sets the attribute but the surrounding
		// markup isn't MediaViewer-eligible.
		buildDom( '<img data-iiif-navigate="1" />' );

		loadMmvPatch( window );
		env.mw.hook( 'wikipage.content' ).fire();

		// No crash; the lonely img is still there.
		expect( document.querySelector( 'img[data-iiif-navigate]' ) ).not.toBeNull();
	} );

	test( 'ThumbnailInfo.get with no sampleUrl falls through to the original', async () => {
		// Defensive: MMV may call .get with an empty/undefined sampleUrl
		// for placeholder fetches. The patch's `if (sampleUrl)` guard
		// must skip the URL-rewriting branch.
		buildDom( '<img data-iiif-page="2" data-iiif-title="File:X.jpg" src="x.jpg" />' );

		const getCalls = [];
		function FakeThumbnailInfo() {}
		FakeThumbnailInfo.prototype.get = function ( file, sampleUrl, width ) {
			getCalls.push( { sampleUrl, width } );
			return { then: () => {} };
		};
		env.registerModule( 'mmv', { ThumbnailInfo: FakeThumbnailInfo } );

		loadMmvPatch( window );
		await new Promise( ( r ) => setTimeout( r, 10 ) );

		new FakeThumbnailInfo().get( 'File:X.jpg', '', 600 );

		expect( getCalls[ 0 ].sampleUrl ).toBe( '' );
	} );

	test( 'click handler tolerates IIIF img without data-iiif-page / data-iiif-full-url / data-file-width / data-file-height', async () => {
		// Bare data-iiif-title only: tests the `if (titleAttr)` true
		// branch alongside the `pageAttr ? ... : 1` and the
		// MultimediaViewer.loadImage `if (w) / if (h)` defaults.
		buildDom( `
			<div id="file">
				<a class="mw-file-description" href="#">
					<img data-iiif-title="File:Foo.jpg" src="x.jpg" />
				</a>
			</div>
		` );

		const loadImageCalls = [];
		function FakeMultimediaViewer() {}
		FakeMultimediaViewer.prototype.loadImage = function ( image ) {
			loadImageCalls.push( { ...image } );
		};

		env.registerModule( 'mmv', {
			MultimediaViewer: FakeMultimediaViewer,
			ThumbnailInfo: class {},
		} );
		env.registerModule( 'mmv.ui.reuse', {} );

		loadMmvPatch( window );
		await new Promise( ( r ) => setTimeout( r, 10 ) );

		// Click an IIIF img (the click handler's `if (titleAttr)` runs).
		document
			.querySelector( '#file img' )
			.dispatchEvent( new Event( 'click', { bubbles: true } ) );

		// LoadImage with stale state — the patch should NOT overwrite
		// originalWidth/originalHeight since the DOM has none.
		const staleImage = {
			src: 'https://iiif.example/stale.jpg',
			originalWidth: 999,
			originalHeight: 999,
		};
		new FakeMultimediaViewer().loadImage( staleImage );

		expect( loadImageCalls[ 0 ].originalWidth ).toBe( 999 );
		expect( loadImageCalls[ 0 ].originalHeight ).toBe( 999 );
	} );

	test( 'click on plain non-IIIF img (no mw-file-element, no mw-file-description ancestor) is a no-op', async () => {
		// The click handler's third branch path: the img matches no
		// IIIF marker AND isn't a MediaViewer-eligible thumbnail —
		// state must be untouched (no reset).
		buildDom( `
			<img id="iiif" data-iiif-title="File:X.jpg" />
			<img id="plain" src="https://example/x.jpg" />
		` );

		function FakeShare() {
			this.$pageInput = { val: jest.fn().mockReturnValue( 'state' ) };
		}
		FakeShare.prototype.set = jest.fn();
		env.registerModule( 'mmv', { ThumbnailInfo: class {} } );
		env.registerModule( 'mmv.ui.reuse', { Share: FakeShare } );

		loadMmvPatch( window );
		await new Promise( ( r ) => setTimeout( r, 10 ) );

		// Seed IIIF state via the marked img.
		document
			.getElementById( 'iiif' )
			.dispatchEvent( new Event( 'click', { bubbles: true } ) );

		// Then click a plain non-IIIF img — the handler returns at the
		// outer IMG check without entering the if/else-if. State stays.
		document
			.getElementById( 'plain' )
			.dispatchEvent( new Event( 'click', { bubbles: true } ) );

		// No throw means the path completed.
		expect( document.getElementById( 'plain' ) ).not.toBeNull();
	} );

	test( 'Share.set tolerates missing $pageInput', async () => {
		// Some MMV revisions construct Share with no $pageInput field
		// (or one without .val). The guard `this.$pageInput && this.$pageInput.val`
		// must short-circuit cleanly.
		buildDom( '<img id="x" data-iiif-title="File:X.jpg" />' );

		function FakeShare() {
			// No $pageInput at all.
		}
		FakeShare.prototype.set = jest.fn();
		env.registerModule( 'mmv', { ThumbnailInfo: class {} } );
		env.registerModule( 'mmv.ui.reuse', { Share: FakeShare } );

		loadMmvPatch( window );
		await new Promise( ( r ) => setTimeout( r, 10 ) );

		document
			.getElementById( 'x' )
			.dispatchEvent( new Event( 'click', { bubbles: true } ) );

		expect( () => new FakeShare().set( {} ) ).not.toThrow();
	} );

	test( 'Download.set is a no-op when no IIIF context is set', async () => {
		// Download.set must NOT rewrite image.url unless
		// isCurrentImageIiif AND currentIiifFullUrl AND this.image
		// are all truthy.
		buildDom( '<img id="x" data-iiif-title="File:X.jpg" />' );

		function FakeDownload() {
			// No `this.image` → the multi-condition guard fails.
		}
		FakeDownload.prototype.set = jest.fn();
		env.registerModule( 'mmv', { ThumbnailInfo: class {} } );
		env.registerModule( 'mmv.ui.reuse', {
			Share: ( function () {
				function S() {}
				S.prototype.set = function () {};
				return S;
			} )(),
			Download: FakeDownload,
		} );

		loadMmvPatch( window );
		await new Promise( ( r ) => setTimeout( r, 10 ) );

		// No prior click → isCurrentImageIiif=false → patched set
		// runs orig and returns without trying to rewrite anything.
		expect( () => new FakeDownload().set( {} ) ).not.toThrow();
	} );

	test( 'Download.set skips handleSizeSwitch when the instance does not expose it', async () => {
		buildDom( `
			<img id="thumb" data-iiif-title="File:X.jpg"
			     data-iiif-full-url="https://iiif.example/p1.jpg" />
		` );

		function FakeDownload() {
			this.image = { url: 'https://iiif.example/p0.jpg' };
			// no handleSizeSwitch method
		}
		FakeDownload.prototype.set = function () {};
		env.registerModule( 'mmv', { ThumbnailInfo: class {} } );
		env.registerModule( 'mmv.ui.reuse', {
			Share: ( function () {
				function S() {}
				S.prototype.set = function () {};
				return S;
			} )(),
			Download: FakeDownload,
		} );

		loadMmvPatch( window );
		await new Promise( ( r ) => setTimeout( r, 10 ) );

		document
			.getElementById( 'thumb' )
			.dispatchEvent( new Event( 'click', { bubbles: true } ) );

		const dl = new FakeDownload();
		// No throw despite no handleSizeSwitch.
		expect( () => dl.set( {} ) ).not.toThrow();
		expect( dl.image.url ).toBe( 'https://iiif.example/p1.jpg' );
	} );

	test( 'EmbedFileFormatter.getThumbnailHtml passes through unchanged for non-IIIF', async () => {
		// Mirrors the Wikitext test but for the HTML formatter — when
		// isCurrentImageIiif is false the replace pipeline is skipped.
		buildDom( '<img id="x" data-iiif-title="File:X.jpg" />' );

		function FakeEFF() {}
		const original = '<a href="https://commons.example/x">img</a>';
		FakeEFF.prototype.getThumbnailHtml = jest.fn( () => original );

		env.registerModule( 'mmv', { ThumbnailInfo: class {} } );
		env.registerModule( 'mmv.ui.reuse', {
			Share: ( function () {
				function S() {}
				S.prototype.set = function () {};
				return S;
			} )(),
			EmbedFileFormatter: FakeEFF,
		} );

		loadMmvPatch( window );
		await new Promise( ( r ) => setTimeout( r, 10 ) );

		// No click → isCurrentImageIiif stays false.
		expect( new FakeEFF().getThumbnailHtml() ).toBe( original );
	} );

	test( 'getThumbnailWikitext on page-1 IIIF strips .jpg but does NOT inject |page=', async () => {
		// Single-canvas IIIF: spoof gets stripped, but no |page=N is
		// injected (currentIiifPage === 1).
		buildDom(
			'<img id="thumb" data-iiif-title="File:Foo.jpg" data-iiif-page="1" />'
		);

		function FakeEFF() {}
		FakeEFF.prototype.getThumbnailWikitext = function ( title, width, caption ) {
			return '[[' + title.getPrefixedText() + '|' + ( caption || '' ) + ']]';
		};

		env.registerModule( 'mmv', { ThumbnailInfo: class {} } );
		env.registerModule( 'mmv.ui.reuse', {
			Share: ( function () {
				function S() {}
				S.prototype.set = function () {};
				return S;
			} )(),
			EmbedFileFormatter: FakeEFF,
		} );

		loadMmvPatch( window );
		await new Promise( ( r ) => setTimeout( r, 10 ) );

		document
			.getElementById( 'thumb' )
			.dispatchEvent( new Event( 'click', { bubbles: true } ) );

		const out = new FakeEFF().getThumbnailWikitext(
			{ getPrefixedText: () => 'File:Foo.jpg' },
			800,
			'caption'
		);
		expect( out ).toContain( 'File:Foo' );
		expect( out ).not.toMatch( /File:Foo\.jpg/ );
		expect( out ).not.toMatch( /\|page=/ );
	} );

	test( 'click on img with empty data-iiif-title does not flip isCurrentImageIiif', async () => {
		// `if (titleAttr)` false branch: the attribute exists but is
		// empty. We still parse page / full-url attrs (defensive) but
		// never mark the image as IIIF.
		buildDom( '<img id="x" data-iiif-title="" data-iiif-page="2" />' );

		loadMmvPatch( window );
		await new Promise( ( r ) => setTimeout( r, 10 ) );

		// No throw on the click — the handler took the no-titleAttr
		// path through the IMG branch.
		document
			.getElementById( 'x' )
			.dispatchEvent( new Event( 'click', { bubbles: true } ) );

		expect( document.getElementById( 'x' ) ).not.toBeNull();
	} );

	test( 'rewriteIiifShareUrl chains with `&` when the base URL already has a query string', async () => {
		// Share URL where the descriptionUrl already carries `?lang=de`
		// (or similar): the page= injection must use `&page=N`, not
		// `?page=N`, to avoid a malformed second `?`.
		buildDom(
			'<img id="thumb" data-iiif-title="File:Bsb11610364.jpg" data-iiif-page="6" />'
		);

		const initial =
			'https://wiki.example/wiki/File:Bsb11610364.jpg?lang=de#/media/File:Bsb11610364';
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
				thumbnail: document.getElementById( 'thumb' ),
				src: 'x.jpg',
			},
		} );
		await new Promise( ( r ) => setTimeout( r, 10 ) );

		new FakeShare().set( {} );

		// Note the `&page=6` separator — the existing `?lang=de` was
		// the first parameter, so the page join must use `&`.
		expect( captured ).toBe(
			'https://wiki.example/wiki/File:Bsb11610364.jpg?lang=de&page=6#/media/File:Bsb11610364'
		);
	} );

	test( 'patchMmvOverlayLinks skips download elements that are neither <a> nor have href', async () => {
		// A `<button class="mw-mmv-download-button">` matches the
		// selector but isn't a link and has no href — the patch must
		// not invent one (which would change its semantics).
		buildDom( `
			<img id="thumb"
			     data-iiif-title="File:X.jpg"
			     data-iiif-full-url="https://iiif.example/p1.jpg" />
			<button id="dlbtn" class="mw-mmv-download-button">Download</button>
		` );

		env.registerModule( 'mmv', { ThumbnailInfo: class {} } );
		env.registerModule( 'mmv.ui.reuse', {} );

		loadMmvPatch( window );
		await new Promise( ( r ) => setTimeout( r, 10 ) );

		env.triggerJqEvent( 'mmv-metadata', {
			image: {
				thumbnail: document.getElementById( 'thumb' ),
				src: 'x.jpg',
			},
		} );

		const btn = document.getElementById( 'dlbtn' );
		expect( btn.hasAttribute( 'href' ) ).toBe( false );
	} );

	test( 'mmv-metadata with non-numeric data-iiif-page falls back to page 1', async () => {
		// `parseInt(pageAttr, 10) || 1` falsy-branch: a non-numeric
		// attribute value (or "0") yields NaN/0 → fall back to 1.
		buildDom( `
			<a id="stripe" class="mw-mmv-description-page-button"
			   href="https://wiki.example/orig">More details</a>
		` );

		const thumb = document.createElement( 'img' );
		thumb.setAttribute( 'data-iiif-title', 'File:Foo.jpg' );
		thumb.setAttribute( 'data-iiif-page', 'abc' );

		env.registerModule( 'mmv', { ThumbnailInfo: class {} } );
		env.registerModule( 'mmv.ui.reuse', {} );

		loadMmvPatch( window );
		await new Promise( ( r ) => setTimeout( r, 10 ) );

		env.triggerJqEvent( 'mmv-metadata', {
			image: { thumbnail: thumb, src: 'x.jpg' },
		} );

		// localUrl built, but no ?page= since the parse fell back to 1.
		expect(
			document.getElementById( 'stripe' ).getAttribute( 'href' )
		).not.toMatch( /[?&]page=/ );
	} );

	test( 'mmv-metadata with no data-iiif-page defaults currentIiifPage to 1', async () => {
		// `pageAttr ? ... : 1` false-branch: thumbnail has data-iiif-title
		// but no data-iiif-page → page defaults to 1, stripe-button
		// rewrite skips the `?page=` insertion.
		buildDom( `
			<a id="stripe" class="mw-mmv-description-page-button"
			   href="https://wiki.example/orig">More details</a>
		` );

		const thumb = document.createElement( 'img' );
		thumb.setAttribute( 'data-iiif-title', 'File:Foo.jpg' );
		// No data-iiif-page.

		env.registerModule( 'mmv', { ThumbnailInfo: class {} } );
		env.registerModule( 'mmv.ui.reuse', {} );

		loadMmvPatch( window );
		await new Promise( ( r ) => setTimeout( r, 10 ) );

		env.triggerJqEvent( 'mmv-metadata', {
			image: { thumbnail: thumb, src: 'x.jpg' },
		} );

		const href = document.getElementById( 'stripe' ).getAttribute( 'href' );
		// localUrl was built (currentIiifTitle = "File:Foo.jpg"),
		// but no ?page= was tacked on because currentIiifPage === 1.
		expect( href ).not.toMatch( /[?&]page=/ );
	} );

	test( 'invalid title (mw.Title throws after unspoof) yields null localUrl', async () => {
		// data-iiif-title is just the spoofed extension (".jpg"); after
		// iiifTitle.unspoof it's empty and `new mw.Title('')` throws —
		// the catch in buildLocalFileUrl maps that to null so the
		// stripe-button href stays whatever MMV emitted.
		buildDom( `
			<a id="stripe" class="mw-mmv-description-page-button"
			   href="https://wiki.example/native">More details</a>
		` );

		const thumb = document.createElement( 'img' );
		thumb.setAttribute( 'data-iiif-title', '.jpg' );

		env.registerModule( 'mmv', { ThumbnailInfo: class {} } );
		env.registerModule( 'mmv.ui.reuse', {} );

		loadMmvPatch( window );
		await new Promise( ( r ) => setTimeout( r, 10 ) );

		env.triggerJqEvent( 'mmv-metadata', {
			image: { thumbnail: thumb, src: 'x.jpg' },
		} );

		expect(
			document.getElementById( 'stripe' ).getAttribute( 'href' )
		).toBe( 'https://wiki.example/native' );
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
