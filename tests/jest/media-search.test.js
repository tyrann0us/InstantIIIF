/**
 * Tests for resources/media-search.js — the client-side patch that makes
 * IIIF files discoverable in MediaSearchWidget (VE's media insert dialog).
 */

'use strict';

const fs = require( 'fs' );
const path = require( 'path' );
const { createMwEnv } = require( './mw-mock' );

function loadMediaSearch( win ) {
	const code = fs.readFileSync(
		path.resolve( __dirname, '../../resources/media-search.js' ),
		'utf-8'
	);
	const fn = new Function(
		'window',
		'document',
		'mw',
		'$',
		'location',
		code
	);
	fn( win, win.document, win.mw, win.$, win.location );
}

// Minimal promise+abort surface to mimic the jQuery-style xhr the upstream
// MediaSearchProvider returns. Pulled into a helper because every test
// stubs mw.Api around it.
function fakeXhr( data ) {
	const p = Promise.resolve( data );
	p.abort = jest.fn();
	return p;
}

// Build the mw stubs MediaSearchProvider depends on inside the patch:
// mw.Api, mw.ForeignApi, mw.widgets.MediaSearchProvider (with a real
// fetchAPIresults we can spy on).
function setupMediaSearchProvider( mw, opts = {} ) {
	const apiCalls = [];
	const apiResponse = opts.apiResponse ?? {};

	function FakeApi() {
		this.get = ( params ) => {
			apiCalls.push( { kind: 'local', params } );
			return fakeXhr( apiResponse );
		};
	}
	function FakeForeignApi( url ) {
		this.get = ( params ) => {
			apiCalls.push( { kind: 'foreign', url, params } );
			return fakeXhr( apiResponse );
		};
	}
	mw.Api = FakeApi;
	mw.ForeignApi = FakeForeignApi;

	const origFetch = jest.fn( () => fakeXhr( [] ) );
	mw.widgets = mw.widgets || {};
	mw.widgets.MediaSearchProvider = function () {};
	mw.widgets.MediaSearchProvider.prototype.fetchAPIresults = origFetch;

	// Force mw.loader.using to resolve immediately for `mediawiki.widgets.MediaSearch`.
	mw.loader.using = () => Promise.resolve( () => ( {} ) );

	return { apiCalls, origFetch };
}

function makeProvider( mw, overrides = {} ) {
	const provider = Object.create( mw.widgets.MediaSearchProvider.prototype );
	const userParams = { gsrsearch: 'df_dk_0007450' };
	const offset = { value: 0 };
	const depleted = { value: false };
	Object.assign(
		provider,
		{
			apiurl: 'https://wiki.example.org/w/api.php',
			isLocal: false,
			staticParams: { iiurlheight: 200, iiurlwidth: 300 },
			getUserParams() {
				return userParams;
			},
			getOffset() {
				return offset.value;
			},
			setOffset( v ) {
				offset.value = v;
			},
			toggleDepleted( v ) {
				depleted.value = v;
			},
			isDepleted() {
				return depleted.value;
			},
			getLang() {
				return 'de';
			},
		},
		overrides
	);
	provider._userParams = userParams;
	provider._offset = offset;
	provider._depleted = depleted;
	return provider;
}

let env;

beforeEach( () => {
	env = createMwEnv( window );
} );

describe( 'media-search.js — config gate', () => {
	test( 'does nothing when wgInstantIIIFRepos is unset', async () => {
		const { origFetch } = setupMediaSearchProvider( env.mw );
		loadMediaSearch( window );
		await new Promise( ( r ) => setTimeout( r, 0 ) );

		const proto = env.mw.widgets.MediaSearchProvider.prototype;
		expect( proto.fetchAPIresults ).toBe( origFetch );
	} );

	test( 'does nothing when wgInstantIIIFRepos is empty', async () => {
		env.config.set( 'wgInstantIIIFRepos', [] );
		const { origFetch } = setupMediaSearchProvider( env.mw );
		loadMediaSearch( window );
		await new Promise( ( r ) => setTimeout( r, 0 ) );

		expect(
			env.mw.widgets.MediaSearchProvider.prototype.fetchAPIresults
		).toBe( origFetch );
	} );
} );

describe( 'media-search.js — IIIF provider routing', () => {
	beforeEach( () => {
		env.config.set( 'wgInstantIIIFRepos', [
			{
				apiurl: 'https://wiki.example.org/w/api.php',
				idPatterns: [ '/^df_.+$/' ],
			},
		] );
	} );

	test( 'routes IIIF apiurl through direct title lookup and returns imageinfo', async () => {
		const { apiCalls, origFetch } = setupMediaSearchProvider( env.mw, {
			apiResponse: {
				query: {
					pages: {
						'-1': {
							ns: 6,
							title: 'File:Df_dk_0007450.jpg',
							imagerepository: 'iiif',
							imageinfo: [
								{
									width: 1600,
									height: 1324,
									url: 'https://iiif.example/full.jpg',
								},
							],
						},
					},
				},
			},
		} );

		loadMediaSearch( window );
		await new Promise( ( r ) => setTimeout( r, 0 ) );

		const provider = makeProvider( env.mw );
		const results =
			await env.mw.widgets.MediaSearchProvider.prototype.fetchAPIresults.call(
				provider,
				10
			);

		expect( origFetch ).not.toHaveBeenCalled();
		expect( apiCalls ).toHaveLength( 1 );
		expect( apiCalls[ 0 ].kind ).toBe( 'foreign' );
		expect( apiCalls[ 0 ].params.titles ).toBe( 'File:df_dk_0007450.jpg' );
		expect( apiCalls[ 0 ].params.prop ).toBe( 'imageinfo' );

		expect( results ).toEqual( [
			{
				width: 1600,
				height: 1324,
				url: 'https://iiif.example/full.jpg',
				title: 'File:Df_dk_0007450.jpg',
				index: 0,
			},
		] );
		expect( provider.isDepleted() ).toBe( true );
	} );

	test( 'drops API entries that are not flagged as iiif', async () => {
		const { apiCalls } = setupMediaSearchProvider( env.mw, {
			apiResponse: {
				query: {
					pages: {
						'-1': {
							ns: 6,
							title: 'File:Df_dk_0007450.jpg',
							imagerepository: '',
							missing: '',
						},
					},
				},
			},
		} );

		loadMediaSearch( window );
		await new Promise( ( r ) => setTimeout( r, 0 ) );

		const provider = makeProvider( env.mw );
		const results =
			await env.mw.widgets.MediaSearchProvider.prototype.fetchAPIresults.call(
				provider,
				10
			);

		expect( apiCalls ).toHaveLength( 1 );
		expect( results ).toEqual( [] );
	} );

	test( 'strips trailing image extension before lookup so "id.jpg" still resolves', async () => {
		const { apiCalls } = setupMediaSearchProvider( env.mw, {
			apiResponse: {},
		} );

		loadMediaSearch( window );
		await new Promise( ( r ) => setTimeout( r, 0 ) );

		const provider = makeProvider( env.mw, {
			getUserParams: () => ( { gsrsearch: 'df_dk_0007450.png' } ),
		} );
		await env.mw.widgets.MediaSearchProvider.prototype.fetchAPIresults.call(
			provider,
			10
		);

		// "png" should be stripped before composing the title (we re-append
		// `.jpg` since File titles must carry an image extension).
		expect( apiCalls[ 0 ].params.titles ).toBe( 'File:df_dk_0007450.jpg' );
	} );

	test( 'skips the lookup when the query does not match any IIIF idPattern', async () => {
		const { apiCalls } = setupMediaSearchProvider( env.mw );

		loadMediaSearch( window );
		await new Promise( ( r ) => setTimeout( r, 0 ) );

		const provider = makeProvider( env.mw, {
			getUserParams: () => ( { gsrsearch: 'kornhaus' } ),
		} );
		const results =
			await env.mw.widgets.MediaSearchProvider.prototype.fetchAPIresults.call(
				provider,
				10
			);

		expect( apiCalls ).toHaveLength( 0 );
		expect( results ).toEqual( [] );
		expect( provider.isDepleted() ).toBe( true );
	} );

	test( 'falls back to the original fetch for non-IIIF providers (Commons, local)', async () => {
		const { origFetch } = setupMediaSearchProvider( env.mw );

		loadMediaSearch( window );
		await new Promise( ( r ) => setTimeout( r, 0 ) );

		const provider = makeProvider( env.mw, {
			apiurl: 'https://commons.wikimedia.org/w/api.php',
		} );
		await env.mw.widgets.MediaSearchProvider.prototype.fetchAPIresults.call(
			provider,
			10
		);

		expect( origFetch ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'reports depletion on subsequent calls so the queue does not paginate forever', async () => {
		setupMediaSearchProvider( env.mw, { apiResponse: {} } );

		loadMediaSearch( window );
		await new Promise( ( r ) => setTimeout( r, 0 ) );

		const provider = makeProvider( env.mw );
		provider.setOffset( 10 );
		const results =
			await env.mw.widgets.MediaSearchProvider.prototype.fetchAPIresults.call(
				provider,
				10
			);

		expect( results ).toEqual( [] );
		expect( provider.isDepleted() ).toBe( true );
	} );

	test( 'hides the timestamp row in VE media-info panels for IIIF titles', async () => {
		setupMediaSearchProvider( env.mw );

		loadMediaSearch( window );
		await new Promise( ( r ) => setTimeout( r, 0 ) );

		document.body.innerHTML = `
			<div class="ve-ui-mwMediaDialog-panel-imageinfo-info">
				<div class="ve-ui-mwMediaDialog-panel-imageinfo-title">Df dk 0007450</div>
				<div class="ve-ui-mwMediaDialog-panel-imageinfo-details">
					<div class="ve-ui-mwMediaInfoFieldWidget" id="row-clock">
						<span class="oo-ui-icon-clock"></span>
						Hochgeladen: vor ein paar Sekunden
					</div>
					<div class="ve-ui-mwMediaInfoFieldWidget" id="row-info">
						<span class="oo-ui-icon-info"></span>
						Weitere Informationen
					</div>
				</div>
			</div>
		`;

		await new Promise( ( r ) => setTimeout( r, 50 ) );

		expect( document.getElementById( 'row-clock' ).style.display ).toBe(
			'none'
		);
		expect( document.getElementById( 'row-info' ).style.display ).toBe(
			''
		);
	} );

	test( 'does not hide the timestamp row for non-IIIF titles (Commons hit)', async () => {
		setupMediaSearchProvider( env.mw );

		loadMediaSearch( window );
		await new Promise( ( r ) => setTimeout( r, 0 ) );

		document.body.innerHTML = `
			<div class="ve-ui-mwMediaDialog-panel-imageinfo-info">
				<div class="ve-ui-mwMediaDialog-panel-imageinfo-title">Kornhaus Burgdorf</div>
				<div class="ve-ui-mwMediaDialog-panel-imageinfo-details">
					<div class="ve-ui-mwMediaInfoFieldWidget" id="row-clock">
						<span class="oo-ui-icon-clock"></span>
						Hochgeladen: vor 5 Jahren
					</div>
				</div>
			</div>
		`;

		await new Promise( ( r ) => setTimeout( r, 50 ) );

		expect( document.getElementById( 'row-clock' ).style.display ).toBe(
			''
		);
	} );

	test( 'uses mw.Api (not ForeignApi) when the provider is flagged local', async () => {
		const { apiCalls } = setupMediaSearchProvider( env.mw, {
			apiResponse: {},
		} );

		loadMediaSearch( window );
		await new Promise( ( r ) => setTimeout( r, 0 ) );

		const provider = makeProvider( env.mw, { isLocal: true } );
		await env.mw.widgets.MediaSearchProvider.prototype.fetchAPIresults.call(
			provider,
			10
		);

		expect( apiCalls[ 0 ].kind ).toBe( 'local' );
	} );
} );
