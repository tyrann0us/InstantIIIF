/**
 * Minimal MediaWiki JS environment mock for testing mmv-patch.js.
 *
 * Sets up window.mw (config, hook, loader, Title) and jQuery ($) so
 * the script can be evaluated in a JSDOM environment without the full
 * ResourceLoader runtime.
 */

'use strict';

const fs = require( 'fs' );
const path = require( 'path' );

/**
 * Build a fresh mw mock and attach it (plus jQuery) to the given window.
 * Returns helper handles so tests can inspect and trigger behaviour.
 */
function createMwEnv( win ) {
	// ── jQuery (minimal, just what mmv-patch.js needs) ──────────

	function jQueryFactory( selector ) {
		const wrap = {
			_el: typeof selector === 'string'
				? Array.from( win.document.querySelectorAll( selector ) )
				: ( selector.nodeType ? [ selector ] : Array.from( selector ) ),
			on( event, handler ) {
				wrap._el.forEach( ( el ) => {
					if ( !el.__jqHandlers ) {
						el.__jqHandlers = {};
					}
					if ( !el.__jqHandlers[ event ] ) {
						el.__jqHandlers[ event ] = [];
					}
					el.__jqHandlers[ event ].push( handler );
				} );
				return wrap;
			},
			val( v ) {
				if ( v === undefined ) {
					return wrap._el[ 0 ] ? wrap._el[ 0 ].value : '';
				}
				wrap._el.forEach( ( el ) => {
					el.value = v;
				} );
				return wrap;
			}
		};
		return wrap;
	}

	/**
	 * Trigger a jQuery-style event on document (used by mmv-metadata).
	 */
	function triggerJqEvent( eventName, extraProps ) {
		const doc = win.document;
		if ( !doc.__jqHandlers || !doc.__jqHandlers[ eventName ] ) {
			return;
		}
		const evt = Object.assign( { type: eventName }, extraProps || {} );
		doc.__jqHandlers[ eventName ].forEach( ( fn ) => fn( evt ) );
	}

	// Clear any leftover jQuery handlers from previous test runs.
	if ( win.document.__jqHandlers ) {
		win.document.__jqHandlers = {};
	}

	win.$ = jQueryFactory;

	// ── mw.config ───────────────────────────────────────────────

	const configStore = {};
	const mwConfig = {
		get( key ) {
			return configStore[ key ] !== undefined ? configStore[ key ] : null;
		},
		set( key, value ) {
			configStore[ key ] = value;
		}
	};

	// ── mw.hook ─────────────────────────────────────────────────

	const hookRegistry = {};
	function mwHook( name ) {
		if ( !hookRegistry[ name ] ) {
			hookRegistry[ name ] = { _handlers: [] };
			hookRegistry[ name ].add = function ( fn ) {
				hookRegistry[ name ]._handlers.push( fn );
				return hookRegistry[ name ];
			};
			hookRegistry[ name ].fire = function ( ...args ) {
				hookRegistry[ name ]._handlers.forEach( ( fn ) => fn( ...args ) );
			};
		}
		return hookRegistry[ name ];
	}

	// ── mw.loader ───────────────────────────────────────────────

	const moduleRegistry = {};
	const mwLoader = {
		using( moduleName ) {
			return Promise.resolve( function require( name ) {
				return moduleRegistry[ name ] || {};
			} );
		}
	};

	/**
	 * Register a fake module that mw.loader.using() will return.
	 */
	function registerModule( name, exports ) {
		moduleRegistry[ name ] = exports;
	}

	// ── mw.Title ────────────────────────────────────────────────

	class MwTitle {
		constructor( text ) {
			this._text = text;
		}

		getUrl() {
			return '/wiki/' + this._text.replace( / /g, '_' );
		}
	}

	MwTitle.newFromImg = function ( img ) {
		const el = img.jquery ? img[ 0 ] : img;
		const t = el.getAttribute( 'data-mwtitle' );
		if ( t ) {
			return new MwTitle( t );
		}
		return null;
	};

	// ── Assemble mw object ──────────────────────────────────────

	const mw = {
		config: mwConfig,
		hook: mwHook,
		loader: mwLoader,
		Title: MwTitle
	};

	win.mw = mw;

	// ── Return helpers for tests ────────────────────────────────

	return {
		mw,
		config: mwConfig,
		hookRegistry,
		registerModule,
		triggerJqEvent
	};
}

/**
 * Load and execute mmv-patch.js in the current JSDOM window context.
 * Must be called after createMwEnv().
 */
function loadMmvPatch( win ) {
	const code = fs.readFileSync(
		path.resolve( __dirname, '../../resources/mmv-patch.js' ),
		'utf-8'
	);
	// Execute the script in the window's global context.
	const fn = new Function( 'window', 'document', 'mw', '$', 'location', code );
	fn( win, win.document, win.mw, win.$, win.location );
}

module.exports = { createMwEnv, loadMmvPatch };
