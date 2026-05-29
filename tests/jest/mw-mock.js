/**
 * Minimal MediaWiki JS environment mock for testing the resource modules.
 *
 * Sets up window.mw (config, hook, loader, Title) and jQuery ($) so the
 * scripts can run in a JSDOM environment without the full ResourceLoader
 * runtime.
 */

'use strict';

/**
 * Build a fresh mw mock and attach it (plus jQuery) to the given window.
 * Returns helper handles so tests can inspect and trigger behaviour.
 * @param {Window} win
 */
function createMwEnv( win ) {
	// ── jQuery (minimal, just what mmv-patch.js needs) ──────────

	function resolveElements( selector ) {
		if ( typeof selector === 'string' ) {
			return Array.from( win.document.querySelectorAll( selector ) );
		}
		if ( selector.nodeType ) {
			return [ selector ];
		}
		return Array.from( selector );
	}

	function jQueryFactory( selector ) {
		const wrap = {
			_el: resolveElements( selector ),
			on( event, handler ) {
				// Strip jQuery event namespaces (`event.ns`) so
				// triggerJqEvent( 'event' ) still finds the handler.
				const bareEvent = event.split( '.' )[ 0 ];
				wrap._el.forEach( ( el ) => {
					if ( ! el.__jqHandlers ) {
						el.__jqHandlers = {};
					}
					if ( ! el.__jqHandlers[ bareEvent ] ) {
						el.__jqHandlers[ bareEvent ] = [];
					}
					el.__jqHandlers[ bareEvent ].push( handler );
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
			},
		};
		return wrap;
	}

	// Minimal jQuery Deferred stand-in. Production code uses jQuery's
	// Deferred to make promises that also expose `.abort()`. Tests don't
	// care about chaining semantics — just that the patch can wrap an
	// eventually-resolved value into a thenable carrying `.abort`.
	jQueryFactory.Deferred = function () {
		let resolveFn;
		let rejectFn;
		const native = new Promise( ( resolve, reject ) => {
			resolveFn = resolve;
			rejectFn = reject;
		} );
		const dfd = {
			resolve( v ) {
				resolveFn( v );
				return dfd;
			},
			reject( e ) {
				rejectFn( e );
				return dfd;
			},
			promise( extras ) {
				return Object.assign(
					Object.create( native ),
					{
						then: native.then.bind( native ),
						catch: native.catch.bind( native ),
						finally: native.finally.bind( native ),
					},
					extras || {}
				);
			},
		};
		return dfd;
	};

	/**
	 * Trigger a jQuery-style event on document (used by mmv-metadata).
	 * @param {string} eventName
	 * @param {Object} [extraProps]
	 */
	function triggerJqEvent( eventName, extraProps ) {
		const doc = win.document;
		if ( ! doc.__jqHandlers || ! doc.__jqHandlers[ eventName ] ) {
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
		},
	};

	// ── mw.hook ─────────────────────────────────────────────────

	const hookRegistry = {};
	function mwHook( name ) {
		if ( ! hookRegistry[ name ] ) {
			hookRegistry[ name ] = { _handlers: [] };
			hookRegistry[ name ].add = function ( fn ) {
				hookRegistry[ name ]._handlers.push( fn );
				return hookRegistry[ name ];
			};
			hookRegistry[ name ].fire = function ( ...args ) {
				hookRegistry[ name ]._handlers.forEach( ( fn ) =>
					fn( ...args )
				);
			};
		}
		return hookRegistry[ name ];
	}

	// ── mw.loader ───────────────────────────────────────────────

	const moduleRegistry = {};
	const mwLoader = {
		using() {
			return Promise.resolve( function require( name ) {
				return moduleRegistry[ name ] || {};
			} );
		},
	};

	/**
	 * Register a fake module that mw.loader.using() will return.
	 * @param {string} name
	 * @param {Object} exports
	 */
	function registerModule( name, exports ) {
		moduleRegistry[ name ] = exports;
	}

	// ── mw.Title ────────────────────────────────────────────────

	class MwTitle {
		constructor( text ) {
			if ( typeof text !== 'string' || text === '' ) {
				// Match real mw.Title's "Invalid title" throw so the
				// `try { new mw.Title(...) } catch {}` paths in code
				// under test are exercisable from Jest.
				throw new Error( 'Invalid title: ' + String( text ) );
			}
			this._text = text;
		}

		getUrl( query ) {
			const base = '/wiki/' + this._text.replace( / /g, '_' );
			if ( ! query ) {
				return base;
			}
			if ( typeof query === 'string' ) {
				return base + '?' + query;
			}
			const parts = [];
			for ( const k in query ) {
				if ( Object.prototype.hasOwnProperty.call( query, k ) ) {
					parts.push(
						encodeURIComponent( k ) +
							'=' +
							encodeURIComponent( query[ k ] )
					);
				}
			}
			return parts.length ? base + '?' + parts.join( '&' ) : base;
		}

		// MMV reads file extensions off Title. Real mw.Title infers it
		// from the dbkey; the stub returns the dot-suffix or empty.
		getExtension() {
			const m = /\.([^.]+)$/.exec( this._text || '' );
			return m ? m[ 1 ] : '';
		}

		getNamespaceId() {
			// Treat any "Foo:..." prefix as NS_FILE so the override's
			// namespace check has something to compare against.
			return this._text && /^File:/i.test( this._text ) ? 6 : 0;
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
		Title: MwTitle,
	};

	win.mw = mw;

	// ── Return helpers for tests ────────────────────────────────

	return {
		mw,
		config: mwConfig,
		hookRegistry,
		registerModule,
		triggerJqEvent,
	};
}

/**
 * Execute a ResourceLoader module (a browser IIFE) inside the current
 * jsdom context.
 *
 * Loads the file through `require()` so Jest/Istanbul instruments it
 * and reports coverage on the real source under `resources/`.
 * The module's IIFE reads `mw`/`$` (and the jsdom-provided `window`/
 * `document`/`location`/`MutationObserver`) off the global scope,
 * so we publish the mock's `mw`/`$` as globals first. `jest.isolateModules()
 * forces the IIFE to re-execute on every call instead of being served
 * from the require cache, so each test gets a fresh patch against
 * its own mock.
 *
 * @param {Window} win
 * @param {string} relativePath Path to the resource file, relative to this dir.
 */
function loadResource( win, relativePath ) {
	global.mw = win.mw;
	global.$ = win.$;
	jest.isolateModules( () => {
		require( relativePath );
	} );
}

/**
 * Load and execute resources/mmv-patch.js. Call after createMwEnv().
 * Loads the shared iiif-title helpers first (the production module declares
 * them as a dependency).
 * @param {Window} win
 */
function loadMmvPatch( win ) {
	loadResource( win, '../../resources/iiif-title.js' );
	loadResource( win, '../../resources/mmv-patch.js' );
}

/**
 * Load and execute resources/media-search.js. Call after createMwEnv().
 * Loads the shared iiif-title helpers first.
 * @param {Window} win
 */
function loadMediaSearch( win ) {
	loadResource( win, '../../resources/iiif-title.js' );
	loadResource( win, '../../resources/media-search.js' );
}

module.exports = { createMwEnv, loadMmvPatch, loadMediaSearch };
