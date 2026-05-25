// Make IIIF files discoverable in the MediaWiki media-search UI
// (VisualEditor's "Insert media" dialog, GalleryDialog, …).
//
// MediaSearchWidget queries every configured FileRepo with
// `generator=search&gsrnamespace=6`, which goes through the local search
// engine. IIIF files have no local wiki page and are not indexed, so the
// search returns nothing for valid IIIF identifiers like "df_dk_0007450".
//
// Direct title lookup (`titles=File:<id>.jpg&prop=imageinfo`) does resolve
// the file: ApiQueryImageInfo asks our IIIFFile repo, which fetches the
// IIIF manifest and returns `imagerepository: "iiif"` plus imageinfo.
//
// This module replaces `fetchAPIresults` on the MediaSearchProvider tied
// to the IIIF repo with a direct title lookup. The repo is identified by
// matching `provider.apiurl` against the apiurls advertised in the
// `wgInstantIIIFRepos` config var (set by Hooks::onBeforePageDisplay).
( function () {
	const repos = mw.config.get( 'wgInstantIIIFRepos' );
	if ( ! Array.isArray( repos ) || ! repos.length ) {
		return;
	}

	const repoByApiUrl = {};
	const allPatterns = [];
	repos.forEach( function ( repo ) {
		if ( ! repo || typeof repo.apiurl !== 'string' ) {
			return;
		}
		const patterns = compilePatterns( repo.idPatterns || [] );
		repoByApiUrl[ repo.apiurl ] = { patterns };
		allPatterns.push.apply( allPatterns, patterns );
	} );

	mw.loader
		.using( 'mediawiki.widgets.MediaSearch' )
		.then( patchMediaSearchProvider );

	hideMisleadingUploadDateForIIIFFiles();

	function patchMediaSearchProvider() {
		const proto =
			mw.widgets &&
			mw.widgets.MediaSearchProvider &&
			mw.widgets.MediaSearchProvider.prototype;
		if ( ! proto || proto._instantIIIFPatched ) {
			return;
		}
		const origFetch = proto.fetchAPIresults;

		proto.fetchAPIresults = function ( howMany ) {
			const descriptor = repoByApiUrl[ this.apiurl ];
			if ( ! descriptor ) {
				return origFetch.call( this, howMany );
			}
			return iiifTitleLookup.call( this, descriptor );
		};

		proto._instantIIIFPatched = true;
	}

	// Fetch a single virtual IIIF file by treating the typed query as an
	// IIIF identifier. Returns the same shape as the upstream
	// `fetchAPIresults` so MediaSearchProvider's queue can consume it.
	function iiifTitleLookup( descriptor ) {
		// IIIF has no fulltext search — every result fits in the first
		// page, so the second call must report depletion.
		if ( this.getOffset() > 0 ) {
			this.toggleDepleted( true );
			return emptyPromise();
		}

		// IIIF identifiers in the wild are extension-less. Strip a trailing
		// image extension before pattern-matching so the user can type with
		// or without one without changing the lookup.
		const query = String( this.getUserParams().gsrsearch || '' )
			.trim()
			.replace( /\.(jpg|jpeg|png|gif|bmp|webp)$/i, '' );
		if ( ! query ) {
			this.toggleDepleted( true );
			return emptyPromise();
		}
		if (
			descriptor.patterns.length &&
			! descriptor.patterns.some( function ( re ) {
				return re.test( query );
			} )
		) {
			this.toggleDepleted( true );
			return emptyPromise();
		}

		const api = this.isLocal
			? new mw.Api()
			: new mw.ForeignApi( this.apiurl, { anonymous: true } );

		const xhr = api.get( {
			action: 'query',
			format: 'json',
			titles: 'File:' + query + '.jpg',
			prop: 'imageinfo',
			iiprop: 'dimensions|url|mediatype|extmetadata|timestamp|user',
			iiextmetadatalanguage: this.getLang(),
			iiurlheight: this.staticParams.iiurlheight,
			iiurlwidth: this.staticParams.iiurlwidth,
		} );

		const provider = this;
		const deferred = $.Deferred();
		xhr.then(
			function ( data ) {
				provider.toggleDepleted( true );
				deferred.resolve( extractIIIFResults( data ) );
			},
			function () {
				provider.toggleDepleted( true );
				deferred.resolve( [] );
			}
		);
		return deferred.promise( { abort: xhr.abort } );
	}

	// Pull the imageinfo entries that the local API has flagged as IIIF.
	// Anything else (missing files, unrelated repos) is dropped so the
	// user only sees real IIIF matches.
	function extractIIIFResults( data ) {
		if ( ! data || ! data.query || ! data.query.pages ) {
			return [];
		}
		const out = [];
		Object.keys( data.query.pages ).forEach( function ( pageId ) {
			const page = data.query.pages[ pageId ];
			if ( ! page || page.imagerepository !== 'iiif' ) {
				return;
			}
			const info = page.imageinfo && page.imageinfo[ 0 ];
			if ( ! info ) {
				return;
			}
			info.title = page.title;
			info.index = 0;
			out.push( info );
		} );
		return out;
	}

	// Convert PHP-style regex (with delimiters, e.g. `/^df_.+$/i`) into
	// a JS RegExp. Drops PHP-only flags the engine doesn't understand.
	function compilePatterns( raw ) {
		const compiled = [];
		raw.forEach( function ( pattern ) {
			if ( typeof pattern !== 'string' || pattern === '' ) {
				return;
			}
			const match = pattern.match( /^(.)([\s\S]+)\1([a-zA-Z]*)$/ );
			let body = pattern;
			let flags = '';
			if ( match ) {
				body = match[ 2 ];
				flags = match[ 3 ].replace( /[^gimsuy]/g, '' );
			}
			try {
				compiled.push( new RegExp( body, flags ) );
			} catch {
				// Skip patterns we can't parse — better than throwing
				// during widget setup and blocking the whole search.
			}
		} );
		return compiled;
	}

	// Strip the "Uploaded: a few seconds ago" row from VE's media-info
	// panel for IIIF files. The IIIFFile has no real upload timestamp, so
	// ApiQueryImageInfo falls back to wfTimestampNow() and VE renders the
	// dialog-opening time — misleading for hotlinked remote media. The
	// same fallback bug is already cosmetically suppressed on the file
	// description page via Hooks::onImagePageFileHistoryLine.
	//
	// A MutationObserver picks up newly-rendered media-info panels (the
	// dialog rebuilds them per selection); for any whose title matches a
	// configured IIIF idPattern we hide every `.oo-ui-icon-clock` row.
	// IIIF files never carry a real `DateTimeOriginal` either, so the
	// only clock row in practice is the timestamp.
	function hideMisleadingUploadDateForIIIFFiles() {
		if ( ! allPatterns.length || typeof MutationObserver !== 'function' ) {
			return;
		}
		const observer = new MutationObserver( function ( records ) {
			records.forEach( function ( record ) {
				record.addedNodes.forEach( function ( node ) {
					if ( node.nodeType !== 1 ) {
						return;
					}
					findInfoPanels( node ).forEach( maybeHideClockRows );
				} );
			} );
		} );
		observer.observe( document.body, { childList: true, subtree: true } );
	}

	function findInfoPanels( node ) {
		const panels = [];
		if (
			node.matches &&
			node.matches( '.ve-ui-mwMediaDialog-panel-imageinfo-info' )
		) {
			panels.push( node );
		}
		if ( node.querySelectorAll ) {
			node.querySelectorAll(
				'.ve-ui-mwMediaDialog-panel-imageinfo-info'
			).forEach( function ( el ) {
				panels.push( el );
			} );
		}
		return panels;
	}

	function maybeHideClockRows( panel ) {
		const titleEl = panel.querySelector(
			'.ve-ui-mwMediaDialog-panel-imageinfo-title'
		);
		if ( ! titleEl ) {
			return;
		}
		// VE renders titles with spaces and a capitalised first character.
		// Convert back to the IIIF object-id shape (lowercased, underscored)
		// before pattern-matching.
		const id = String( titleEl.textContent || '' )
			.trim()
			.replace( /\s+/g, '_' )
			.toLowerCase();
		if ( ! id || ! allPatterns.some( ( re ) => re.test( id ) ) ) {
			return;
		}
		panel
			.querySelectorAll( '.oo-ui-icon-clock' )
			.forEach( function ( icon ) {
				const row = icon.closest( '.ve-ui-mwMediaInfoFieldWidget' );
				if ( row ) {
					row.style.display = 'none';
				}
			} );
	}

	function emptyPromise() {
		return $.Deferred().resolve( [] ).promise( { abort: noop } );
	}

	function noop() {}
} )();
