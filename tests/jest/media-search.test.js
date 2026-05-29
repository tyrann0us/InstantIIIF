/**
 * Tests for resources/media-search.js — the client-side patch that makes
 * IIIF files discoverable in MediaSearchWidget (VE's media insert dialog).
 */

'use strict';

const { createMwEnv, loadMediaSearch } = require( './mw-mock' );

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

	test( 'strips a trailing .jpg from the typed query before lookup', async () => {
		const { apiCalls } = setupMediaSearchProvider( env.mw, {
			apiResponse: {},
		} );

		loadMediaSearch( window );
		await new Promise( ( r ) => setTimeout( r, 0 ) );

		// The user typed `df_dk_0007450.jpg`; the patch strips the
		// trailing `.jpg` to match the bare IIIF object id, then
		// re-appends it when composing the API title (File titles must
		// carry an image extension for the imageinfo lookup to succeed).
		const provider = makeProvider( env.mw, {
			getUserParams: () => ( { gsrsearch: 'df_dk_0007450.jpg' } ),
		} );
		await env.mw.widgets.MediaSearchProvider.prototype.fetchAPIresults.call(
			provider,
			10
		);

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

	test( 'skips malformed wgInstantIIIFRepos entries (null / missing apiurl)', async () => {
		// Configure a mix of malformed and valid entries — the malformed
		// ones must be ignored without throwing so the valid one still
		// routes through our patch.
		env.config.set( 'wgInstantIIIFRepos', [
			null,
			{ /* no apiurl */ idPatterns: [ '/^df_.+$/' ] },
			{ apiurl: 42 /* not a string */ },
			{
				apiurl: 'https://wiki.example.org/w/api.php',
				idPatterns: [ '/^df_.+$/' ],
			},
		] );

		const { apiCalls } = setupMediaSearchProvider( env.mw );
		loadMediaSearch( window );
		await new Promise( ( r ) => setTimeout( r, 0 ) );

		const provider = makeProvider( env.mw );
		await env.mw.widgets.MediaSearchProvider.prototype.fetchAPIresults.call(
			provider,
			10
		);

		// Patch still routes via direct title lookup for the one valid entry.
		expect( apiCalls ).toHaveLength( 1 );
	} );

	test( 'returns empty result when the query is empty after unspoof', async () => {
		// User typed only `.jpg` (or nothing at all) — after stripping
		// the spoofed extension the query is empty, so the patch must
		// report depletion without firing an API call.
		const { apiCalls } = setupMediaSearchProvider( env.mw );
		loadMediaSearch( window );
		await new Promise( ( r ) => setTimeout( r, 0 ) );

		const provider = makeProvider( env.mw, {
			getUserParams: () => ( { gsrsearch: '.jpg' } ),
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

	test( 'reports depletion when the API request rejects', async () => {
		// Network or 5xx from the imageinfo lookup must not bubble up as
		// a rejected promise — the queue would treat that as a fatal
		// error. Resolve to [] instead and flip depletion.
		const apiCalls = [];
		function FakeForeignApi() {
			this.get = ( params ) => {
				apiCalls.push( params );
				const p = Promise.reject( new Error( 'simulated 500' ) );
				p.abort = jest.fn();
				return p;
			};
		}
		env.mw.Api = function () {};
		env.mw.ForeignApi = FakeForeignApi;
		env.mw.widgets = env.mw.widgets || {};
		env.mw.widgets.MediaSearchProvider = function () {};
		env.mw.widgets.MediaSearchProvider.prototype.fetchAPIresults =
			jest.fn();
		env.mw.loader.using = () => Promise.resolve( () => ( {} ) );

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
		expect( provider.isDepleted() ).toBe( true );
	} );

	test( 'drops pages whose imageinfo array is missing or empty', async () => {
		// imagerepository=iiif but no imageinfo[0] (rare: imageinfo
		// queries can return the marker without per-revision data).
		// The result list must be empty rather than include `undefined`.
		const { apiCalls } = setupMediaSearchProvider( env.mw, {
			apiResponse: {
				query: {
					pages: {
						'-1': {
							title: 'File:Df_dk_0007450.jpg',
							imagerepository: 'iiif',
							// No imageinfo key at all.
						},
						'-2': {
							title: 'File:Df_dk_0007451.jpg',
							imagerepository: 'iiif',
							imageinfo: [],
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

	test( 'compilePatterns skips non-string and empty entries', async () => {
		// idPatterns may carry junk from configuration — compile what
		// we can, drop the rest. A bad entry must not stop the search
		// from matching a good one.
		env.config.set( 'wgInstantIIIFRepos', [
			{
				apiurl: 'https://wiki.example.org/w/api.php',
				idPatterns: [ '', null, 42, '/^df_.+$/' ],
			},
		] );

		const { apiCalls } = setupMediaSearchProvider( env.mw );
		loadMediaSearch( window );
		await new Promise( ( r ) => setTimeout( r, 0 ) );

		const provider = makeProvider( env.mw );
		await env.mw.widgets.MediaSearchProvider.prototype.fetchAPIresults.call(
			provider,
			10
		);

		// The single valid pattern still permits the lookup.
		expect( apiCalls ).toHaveLength( 1 );
	} );
} );

describe( 'media-search.js — patch idempotency', () => {
	beforeEach( () => {
		env.config.set( 'wgInstantIIIFRepos', [
			{
				apiurl: 'https://wiki.example.org/w/api.php',
				idPatterns: [],
			},
		] );
	} );

	test( 'does not re-patch a prototype already marked _instantIIIFPatched', async () => {
		// Simulate the patch having been applied by a previous module
		// load on the same page — the guard at the top of
		// patchMediaSearchProvider must short-circuit and leave the
		// existing fetchAPIresults intact.
		setupMediaSearchProvider( env.mw );
		const proto = env.mw.widgets.MediaSearchProvider.prototype;
		const sentinel = jest.fn( () => Promise.resolve( [] ) );
		proto.fetchAPIresults = sentinel;
		proto._instantIIIFPatched = true;

		loadMediaSearch( window );
		await new Promise( ( r ) => setTimeout( r, 0 ) );

		// Our patch left the prior sentinel in place — no rewrite.
		expect( proto.fetchAPIresults ).toBe( sentinel );
	} );

	test( 'does nothing when mw.widgets.MediaSearchProvider is missing', async () => {
		// `mw.loader.using('mediawiki.widgets.MediaSearch').then(...)`
		// can resolve without the widget being on the page — the
		// guard handles a missing prototype without crashing.
		env.mw.widgets = {}; // No MediaSearchProvider.
		env.mw.loader.using = () => Promise.resolve( () => ( {} ) );

		loadMediaSearch( window );
		await new Promise( ( r ) => setTimeout( r, 0 ) );

		// No throw means the test passed. Assert something concrete
		// so jest reports a meaningful expectation count.
		expect( env.mw.widgets.MediaSearchProvider ).toBeUndefined();
	} );
} );

describe( 'media-search.js — branch edge cases', () => {
	test( 'repo without idPatterns key still patches (falls through `|| []`)', async () => {
		// idPatterns is optional in the config — when omitted, the
		// patch falls back to an empty pattern list (any query is
		// allowed). Verifies the `repo.idPatterns || []` short-circuit.
		env.config.set( 'wgInstantIIIFRepos', [
			{
				apiurl: 'https://wiki.example.org/w/api.php' /* no idPatterns */,
			},
		] );

		const { apiCalls } = setupMediaSearchProvider( env.mw );
		loadMediaSearch( window );
		await new Promise( ( r ) => setTimeout( r, 0 ) );

		const provider = makeProvider( env.mw );
		await env.mw.widgets.MediaSearchProvider.prototype.fetchAPIresults.call(
			provider,
			10
		);

		// No patterns → no idPattern filter → the lookup still runs.
		expect( apiCalls ).toHaveLength( 1 );
	} );

	test( 'missing gsrsearch param is normalised to empty string', async () => {
		// `getUserParams()` may not include `gsrsearch` at all if the
		// search input is blank — the patch must coerce undefined to
		// '' before calling .trim() (otherwise the call would throw).
		env.config.set( 'wgInstantIIIFRepos', [
			{ apiurl: 'https://wiki.example.org/w/api.php' },
		] );

		const { apiCalls } = setupMediaSearchProvider( env.mw );
		loadMediaSearch( window );
		await new Promise( ( r ) => setTimeout( r, 0 ) );

		const provider = makeProvider( env.mw, {
			getUserParams: () => ( {
				/* no gsrsearch */
			} ),
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

	test( 'pattern without PHP delimiters is treated as a literal regex', async () => {
		// PHP regexes carry delimiters (`/^foo$/i`). If a pattern from
		// config doesn't match the delimiter-shaped pattern, the patch
		// must still try to compile it as a JS regex source.
		env.config.set( 'wgInstantIIIFRepos', [
			{
				apiurl: 'https://wiki.example.org/w/api.php',
				idPatterns: [ '^df_' /* no delimiters */ ],
			},
		] );

		const { apiCalls } = setupMediaSearchProvider( env.mw );
		loadMediaSearch( window );
		await new Promise( ( r ) => setTimeout( r, 0 ) );

		const provider = makeProvider( env.mw );
		await env.mw.widgets.MediaSearchProvider.prototype.fetchAPIresults.call(
			provider,
			10
		);

		// The literal-compiled regex still matches `df_dk_0007450`.
		expect( apiCalls ).toHaveLength( 1 );
	} );
} );
