// @ts-check
const base = require( '@playwright/test' );

/**
 * Extend the base test with automatic request routing.
 *
 * Image URLs in the rendered HTML point to http://iiif-mock:8111/… which is
 * only resolvable inside the Docker network.  This fixture intercepts those
 * requests in the browser and rewrites them to http://localhost:8111/… so the
 * Playwright browser (running on the host) can reach the mock server.
 */
module.exports = base.test.extend( {
	page: async ( { page }, use ) => {
		await page.route( /http:\/\/iiif-mock:8111\//, ( route ) => {
			const url = route.request().url().replace(
				'http://iiif-mock:8111/',
				'http://localhost:8111/'
			);
			route.continue( { url } );
		} );
		await use( page );
	}
} );
