'use strict';

/**
 * Minimal IIIF mock server for E2E tests.
 *
 * Serves:
 *   GET /iiif/2/{id}/manifest.json  - fixture manifest (rewritten so service
 *                                      `@id` values point back to this server)
 *   GET /iiif/2/{id}/info.json      - synthetic info.json
 *   GET /iiif/2/{id}/*              - 1x1 red JPEG placeholder
 */

const http = require( 'http' );
const fs = require( 'fs' );
const path = require( 'path' );

const PORT = 8111;
const FIXTURES = path.join( __dirname, '../tests/phpunit/Fixtures' );

// Tiny 1×1 red JPEG (285 bytes).
const PLACEHOLDER_JPEG = Buffer.from(
	'/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkS' +
		'Ew8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJ' +
		'CQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIy' +
		'MjIyMjIyMjIyMjIyMjL/wAARCAABAAEDASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEA' +
		'AAAAAAAAAAECAwQFBgcICQoL/8QAFRABAQAAAAAAAAAAAAAAAAAAAAn/xAAUAQEAAAAA' +
		'AAAAAAAAAAAAAAD/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCwAB//2Q==',
	'base64'
);

// Map object IDs → fixture files. Real-world object IDs are preserved
// alongside legacy short names so existing test wikitext keeps working.
const MANIFEST_MAP = {
	df_dk_0007450: 'manifest-fotothek-v2.json',
	'384671365-19500000': 'manifest-slub-v2.json',
	bsb00127289: 'manifest-bsb-v2.json',
	bsb11610364: 'manifest-multipage-v2.json',
	df_dk_multipage: 'manifest-multipage-v2.json',
	v3_test: 'manifest-v3.json',
};

function rewriteManifest( json, host ) {
	// Real provider manifests reference their own Image API endpoints
	// (paths like /iiif/2/, /iiif/image/v2/, /proxy/fotothek/, …).
	// Rewrite the host portion of every absolute IIIF URL inside the
	// manifest so the rendered thumbnails resolve back to this mock
	// server inside the Docker network.
	let text = JSON.stringify( json );
	text = text.replace(
		/https?:\/\/[^"\/]+(\/iiif\/(?:image\/)?(?:2|v2|3|v3)\/)/g,
		host + '$1'
	);
	return JSON.parse( text );
}

function serveManifest( res, objectId, host ) {
	const fixture = MANIFEST_MAP[ objectId ];
	if ( ! fixture ) {
		res.writeHead( 404, { 'Content-Type': 'application/json' } );
		res.end( JSON.stringify( { error: 'Unknown object: ' + objectId } ) );
		return;
	}

	const filePath = path.join( FIXTURES, fixture );
	let raw;
	try {
		raw = JSON.parse( fs.readFileSync( filePath, 'utf-8' ) );
	} catch ( e ) {
		res.writeHead( 500 );
		res.end( 'Failed to read fixture: ' + e.message );
		return;
	}

	const rewritten = rewriteManifest( raw, host );
	const body = JSON.stringify( rewritten, null, 2 );
	res.writeHead( 200, {
		'Content-Type': 'application/ld+json',
		'Access-Control-Allow-Origin': '*',
	} );
	res.end( body );
}

function serveInfoJson( res, objectId ) {
	const info = {
		'@context': 'http://iiif.io/api/image/2/context.json',
		'@id': 'http://iiif-mock:' + PORT + '/iiif/2/' + objectId,
		protocol: 'http://iiif.io/api/image',
		width: 4000,
		height: 5500,
		profile: [ 'http://iiif.io/api/image/2/level2.json' ],
	};
	res.writeHead( 200, {
		'Content-Type': 'application/ld+json',
		'Access-Control-Allow-Origin': '*',
	} );
	res.end( JSON.stringify( info ) );
}

function serveImage( res ) {
	res.writeHead( 200, {
		'Content-Type': 'image/jpeg',
		'Access-Control-Allow-Origin': '*',
	} );
	res.end( PLACEHOLDER_JPEG );
}

const server = http.createServer( ( req, res ) => {
	const url = new URL( req.url, 'http://localhost' );

	// Health check.
	if ( url.pathname === '/health' ) {
		res.writeHead( 200 );
		res.end( 'ok' );
		return;
	}

	const parts = url.pathname.split( '/' ).filter( Boolean );

	// Strip optional path prefixes from real-world IIIF endpoints —
	// they may show up after rewriteManifest() rewrites only the host
	// portion of canvas URLs. Examples:
	//   /iiif/2/{id}/...                (manifest fetcher pattern)
	//   /iiif/image/v2/{id}/full/...    (BSB Image API canvas service)
	//   /iiif/3/{id}/...                (Presentation v3)
	let objectIdIndex = -1;
	if ( parts[ 0 ] === 'iiif' && [ '2', '3' ].includes( parts[ 1 ] ) ) {
		objectIdIndex = 2;
	} else if (
		parts[ 0 ] === 'iiif' &&
		parts[ 1 ] === 'image' &&
		[ 'v2', 'v3' ].includes( parts[ 2 ] )
	) {
		objectIdIndex = 3;
	}

	if ( objectIdIndex > 0 && parts[ objectIdIndex ] ) {
		const objectId = parts[ objectIdIndex ];
		const rest = parts.slice( objectIdIndex + 1 ).join( '/' );
		const host = 'http://' + req.headers.host;

		if ( rest === 'manifest.json' || rest === 'manifest' ) {
			serveManifest( res, objectId, host );
		} else if ( rest === 'info.json' ) {
			serveInfoJson( res, objectId );
		} else {
			// Any other path under the object ID → image placeholder.
			serveImage( res );
		}
		return;
	}

	res.writeHead( 404 );
	res.end( 'Not found: ' + url.pathname );
} );

server.listen( PORT, '0.0.0.0', () => {
	console.log( 'Mock IIIF server listening on port ' + PORT );
} );
