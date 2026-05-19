'use strict';

/**
 * Minimal IIIF mock server for E2E tests.
 *
 * Serves:
 *   GET /iiif/2/{id}/manifest.json  → fixture manifest (rewritten so service
 *                                      @ids point back to this server)
 *   GET /iiif/2/{id}/info.json      → synthetic info.json
 *   GET /iiif/2/{id}/*              → 1×1 red JPEG placeholder
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

// Map object IDs → fixture files.
// The manifest URL pattern is /iiif/2/{objectId}/manifest.json
// We map known objectIds to fixtures.
const MANIFEST_MAP = {
	df_dk_0007450: 'manifest-fotothek-v2.json',
	slub_obj_001: 'manifest-slub-v2.json',
	bsb10000001: 'manifest-bsb-v2.json',
	df_dk_multipage: 'manifest-multipage-v2.json',
	v3_test: 'manifest-v3.json'
};

function rewriteManifest( json, host ) {
	let text = JSON.stringify( json );
	text = text.replace(
		/https?:\/\/[^"]*?\/iiif\/2\//g,
		host + '/iiif/2/'
	);
	return JSON.parse( text );
}

function serveManifest( res, objectId, host ) {
	const fixture = MANIFEST_MAP[ objectId ];
	if ( !fixture ) {
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
		'Access-Control-Allow-Origin': '*'
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
		profile: [ 'http://iiif.io/api/image/2/level2.json' ]
	};
	res.writeHead( 200, {
		'Content-Type': 'application/ld+json',
		'Access-Control-Allow-Origin': '*'
	} );
	res.end( JSON.stringify( info ) );
}

function serveImage( res ) {
	res.writeHead( 200, {
		'Content-Type': 'image/jpeg',
		'Access-Control-Allow-Origin': '*'
	} );
	res.end( PLACEHOLDER_JPEG );
}

const server = http.createServer( ( req, res ) => {
	const url = new URL( req.url, 'http://localhost' );
	const parts = url.pathname.split( '/' ).filter( Boolean );

	// Expected patterns:
	//   /iiif/2/{objectId}/manifest.json
	//   /iiif/2/{objectId}/info.json
	//   /iiif/2/{objectId}/full/...  (image request)

	if ( parts[ 0 ] === 'iiif' && parts[ 1 ] === '2' && parts[ 2 ] ) {
		const objectId = parts[ 2 ];
		const rest = parts.slice( 3 ).join( '/' );
		const host = 'http://' + req.headers.host;

		if ( rest === 'manifest.json' ) {
			serveManifest( res, objectId, host );
		} else if ( rest === 'info.json' ) {
			serveInfoJson( res, objectId );
		} else {
			// Any other path under the object ID → image placeholder.
			serveImage( res );
		}
		return;
	}

	// Health check.
	if ( url.pathname === '/health' ) {
		res.writeHead( 200 );
		res.end( 'ok' );
		return;
	}

	res.writeHead( 404 );
	res.end( 'Not found: ' + url.pathname );
} );

server.listen( PORT, '0.0.0.0', () => {
	console.log( 'Mock IIIF server listening on port ' + PORT );
} );
