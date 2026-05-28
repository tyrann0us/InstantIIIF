// Spoof/unspoof helpers and EXTENSIONS character class shared with
// media-search.js and (server-side) src/IIIFTitle.php. Loaded as a
// dependency via the ext.instantIIIF.title ResourceLoader module.
const iiifTitle = window.iiifTitle;

// On file detail pages, prevent MMV from intercepting prev/next
// navigation links for IIIF multi-page documents. The PHP hook
// marks these <img> elements with data-iiif-navigate="1".
// Removing the mw-file-description class from their parent <a>
// makes MMV's bootstrap skip them (it collects via
// '.mw-file-description img').
//
// This runs via mw.hook('wikipage.content') which fires in
// registration order — our module loads with position:top, so
// our handler is registered (and runs) before MMV's bootstrap.
mw.hook( 'wikipage.content' ).add( function () {
	document
		.querySelectorAll( 'img[data-iiif-navigate]' )
		.forEach( function ( img ) {
			const link = img.closest( 'a.mw-file-description' );
			if ( link ) {
				link.classList.remove( 'mw-file-description' );
			}
		} );

	// On file detail pages the shared-upload description text contains a
	// link to getDescriptionUrl(). Since that now returns the local wiki
	// URL (for MMV), the link would point back to the same page. Replace
	// it with the external provider URL passed via JS config.
	const providerUrl = mw.config.get( 'wgIIIFProviderUrl' );
	if ( providerUrl ) {
		document
			.querySelectorAll( '.sharedUploadNotice a' )
			.forEach( function ( a ) {
				const href = a.getAttribute( 'href' );
				// Match links that point to the local file page (the circular reference).
				// The href may be relative (/wiki/...) or absolute (https://...).
				if (
					href &&
					( href.indexOf( '/wiki/' ) === 0 ||
						href.indexOf( location.origin ) === 0 )
				) {
					a.href = providerUrl;
				}
			} );
	}
} );

mw.loader.using( 'mediawiki.Title' ).then( function () {
	const orig = mw.Title.newFromImg;
	mw.Title.newFromImg = function ( img ) {
		const el = img.jquery ? img[ 0 ] : img;
		const t =
			el.getAttribute( 'data-iiif-title' ) ||
			el.getAttribute( 'data-mwtitle' );
		if ( t ) {
			try {
				return new mw.Title( t );
			} catch {}
		}
		return orig( img );
	};

	// Enforce "Open in Media Viewer" button on file detail pages.
	//
	// On a file detail page, MMV's processFilePageThumb resolves the
	// title via `mw.Title.newFromText(wgTitle, wgNamespaceNumber)` —
	// i.e. ignoring our `data-iiif-title` data attribute — and bails
	// out early if `title.getExtension()` is empty. IIIF object IDs
	// are extension-less (Bsb11610364, df_dk_*, …), so MMV silently
	// skips the file and never adds the `.mw-mmv-view-expanded`
	// "Open in Media Viewer" button.
	//
	// Patch `getExtension` to return "jpg" for file-namespace titles
	// *on IIIF file detail pages*; this gates only on a marker we
	// ourselves placed (`#file img[data-iiif-title]`), so other pages
	// are unaffected. MMV happily processes the thumb after this and
	// surfaces its usual button.
	if ( document.querySelector( '#file img[data-iiif-title]' ) ) {
		const origGetExt = mw.Title.prototype.getExtension;
		mw.Title.prototype.getExtension = function () {
			const ext = origGetExt.call( this );
			if ( ext ) {
				return ext;
			}
			if (
				typeof this.getNamespaceId === 'function' &&
				this.getNamespaceId() === 6 // NS_FILE
			) {
				return iiifTitle.SPOOF_EXTENSION;
			}
			return ext;
		};
	}

	// --- IIIF-specific MMV patches ---

	// Map of thumbnail src URL → IIIF page number. Rebuilt on every
	// `wikipage.content` fire so it stays in sync after the
	// `mediawiki.page.image.pagination` core module swaps `.mw-filepage-multipage`
	// content via AJAX (pushState navigation between pages of a multi-page file).
	const iiifPageByUrl = new Map();

	function rebuildIiifPageByUrl() {
		iiifPageByUrl.clear();
		document
			.querySelectorAll( 'img[data-iiif-page]' )
			.forEach( function ( img ) {
				const page = parseInt(
					img.getAttribute( 'data-iiif-page' ),
					10
				);
				if ( page > 1 ) {
					iiifPageByUrl.set( img.getAttribute( 'src' ), page );
				}
			} );
	}

	rebuildIiifPageByUrl();
	mw.hook( 'wikipage.content' ).add( rebuildIiifPageByUrl );

	// Eagerly patch ThumbnailInfo so the fix is in place before MMV opens.
	// MMV's ThumbnailInfo.get() extracts a page number from the sampleUrl
	// via the regex /(lang|page)([\d\-a-z]+)-(\d+)px/. IIIF Image API URLs
	// don't contain this pattern, so iiurlparam stays undefined and the
	// API call returns page 1 regardless.
	//
	// Fix: when the sampleUrl maps to a known IIIF page > 1, append a
	// fragment "#pageN-Wpx" that the regex picks up. ThumbnailInfo then
	// sends iiurlparam=pageN-{width}px to the API, which
	// IIIFHandler::parseParamString() parses into {page: N, width: W}.
	// The "#" fragment is harmless — it never reaches the HTTP request.
	//
	// Patched unconditionally on pages that load this module: the
	// iiifPageByUrl map is rebuilt on every `wikipage.content` fire (see
	// above), so a page that starts at canvas 1 and only later acquires a
	// multi-page image (via the core mediawiki.page.image.pagination AJAX
	// swap) still has the patch in place when MMV opens.
	mw.loader.using( 'mmv' ).then( function ( require ) {
		const ThumbnailInfo = require( 'mmv' ).ThumbnailInfo;
		if (
			! ThumbnailInfo ||
			! ThumbnailInfo.prototype ||
			ThumbnailInfo.prototype.__instantIIIFPatched
		) {
			return;
		}
		const origGet = ThumbnailInfo.prototype.get;
		ThumbnailInfo.prototype.get = function (
			file,
			sampleUrl,
			width,
			height
		) {
			if ( sampleUrl ) {
				const page = iiifPageByUrl.get( sampleUrl );
				if ( page ) {
					const marker =
						'page' + page + '-' + ( width || 300 ) + 'px';
					return origGet.call(
						this,
						file,
						sampleUrl + '#' + marker,
						width,
						height
					);
				}
			}
			return origGet.call( this, file, sampleUrl, width, height );
		};
		ThumbnailInfo.prototype.__instantIIIFPatched = true;
	} );

	// --- IIIF state tracking and MMV patches via mmv-metadata ---
	let isCurrentImageIiif = false;
	let currentIiifFullUrl = null;
	let currentIiifPage = 1;
	let currentIiifTitle = null;

	// Clicking the displayed image in MMV fires the `mmv-viewfile`
	// jQuery event, whose MMV handler calls
	// `imageInfoProvider.get(filePageTitle)` *without* iiurlparam and
	// navigates to `imageInfo.url` — which is always canvas 1 for
	// multi-page IIIF docs. Intercept the event before MMV's handler
	// gets a chance to fire and navigate to the canvas the user is
	// actually looking at instead. Our handler is registered here
	// (inside mw.loader.using('mediawiki.Title')) which runs *before*
	// MMV is lazy-loaded by the user's first thumbnail click, so jQuery
	// calls ours first — stopImmediatePropagation suppresses MMV's.
	$( document ).on( 'mmv-viewfile.iiifpatch', function ( e ) {
		if ( isCurrentImageIiif && currentIiifFullUrl ) {
			e.stopImmediatePropagation();
			document.location = currentIiifFullUrl;
		}
	} );

	// MMV's `mmv-metadata` event fires *after* `metadataPromise` resolves,
	// but the imageinfo API response is fed into Share.set / Embed.set
	// off a separate `.then()` chain that can run *before* the event
	// handler does. The result: when MMV first opens, our reuse-dialog
	// patches see stale state (page = 1) and the share/embed URLs come
	// out without `?page=N`. Capture the page on the *click* that
	// opened MMV — by then the user has already chosen which canvas
	// they care about, and the value will be in place no matter which
	// promise wins the race.
	/**
	 * Parse `data-iiif-page` strictly: only accept a non-empty string of
	 * digits with a positive value. parseInt() is lenient ("3.5" → 3,
	 * "abc" → NaN) — clamp every other shape to 1 so a malformed
	 * attribute can't quietly send users to a different canvas than
	 * the rendered thumbnail.
	 * @param {string} raw
	 */
	function parseIiifPage( raw ) {
		if ( typeof raw !== 'string' || ! /^\d+$/.test( raw ) ) {
			return 1;
		}
		const n = parseInt( raw, 10 );
		return n > 0 ? n : 1;
	}

	document.addEventListener(
		'click',
		function ( event ) {
			let node = event.target;
			while ( node && node !== document.body ) {
				if ( node.tagName === 'IMG' ) {
					if ( node.hasAttribute( 'data-iiif-title' ) ) {
						const titleAttr =
							node.getAttribute( 'data-iiif-title' );
						const pageAttr = node.getAttribute( 'data-iiif-page' );
						const fullUrlAttr =
							node.getAttribute( 'data-iiif-full-url' );
						if ( titleAttr ) {
							currentIiifTitle = titleAttr;
							isCurrentImageIiif = true;
						}
						currentIiifPage = parseIiifPage( pageAttr );
						currentIiifFullUrl = fullUrlAttr || null;
					} else if (
						node.classList.contains( 'mw-file-element' ) ||
						node.closest( 'a.mw-file-description' )
					) {
						// Clicked a non-IIIF MediaViewer-eligible thumbnail
						// (e.g. a Commons file). Reset state so the share
						// / embed / mmv-viewfile patches don't reuse stale
						// IIIF context from a previous click in the same
						// session.
						isCurrentImageIiif = false;
						currentIiifTitle = null;
						currentIiifFullUrl = null;
						currentIiifPage = 1;
					}
					return;
				}
				node = node.parentElement;
			}
		},
		true
	);

	// Eagerly patch the reuse-dialog widgets so they are in place before
	// MMV's first call to Embed.set() / Share.set().
	//
	// `mmv.ui.reuse`'s package files (mmv.ui.download.dialog.js etc.)
	// call `require('mmv')` from their top-level scope when MW's
	// module runner executes them. `mmv.ui.reuse` does *not* declare
	// `mmv` as a ResourceLoader dependency, so requesting them
	// together with `mw.loader.using(['mmv', 'mmv.ui.reuse'])` lets
	// them load in parallel — `mmv.ui.reuse`'s runScript wins the race
	// ~9× out of 10 and throws "Error: Module 'mmv' is not loaded".
	// Chain the loads so `mmv` is fully ready before we ask for
	// `mmv.ui.reuse`.
	//
	// Only kick this off on pages that contain an IIIF thumbnail, to
	// avoid pulling MMV into pages that don't need it.
	const hasIiifImageOnPage = document.querySelector( 'img[data-iiif-title]' );
	if ( hasIiifImageOnPage ) {
		mw.loader
			.using( 'mmv' )
			.then( function ( require ) {
				// "Open in Media Viewer" stripe button after client-side
				// page navigation: MMV's processFilePageThumb is invoked
				// when wikipage.content fires (after the core
				// `mediawiki.page.image.pagination` AJAX swap), but in
				// practice the button's click handler keeps firing with
				// the LightboxImage from initial page load — so MMV ends
				// up displaying the original page rather than the page
				// the user is now on. Refresh the LightboxImage from the
				// current `#file img[data-iiif-title]` DOM state every
				// time MMV loads an image; the post-swap thumb's src,
				// data-file-width and data-file-height reflect the
				// current canvas, so MMV opens at the right page.
				const MultimediaViewer = require( 'mmv' ).MultimediaViewer;
				if (
					MultimediaViewer &&
					MultimediaViewer.prototype &&
					MultimediaViewer.prototype.loadImage
				) {
					const origLoadImage = MultimediaViewer.prototype.loadImage;
					MultimediaViewer.prototype.loadImage = function ( image ) {
						const fileImg = document.querySelector(
							'#file img[data-iiif-title]'
						);
						if ( fileImg && image ) {
							image.src = fileImg.getAttribute( 'src' );
							const w = parseInt(
								fileImg.getAttribute( 'data-file-width' ),
								10
							);
							const h = parseInt(
								fileImg.getAttribute( 'data-file-height' ),
								10
							);
							if ( w ) {
								image.originalWidth = w;
							}
							if ( h ) {
								image.originalHeight = h;
							}
						}
						return origLoadImage.call( this, image );
					};
				}

				return mw.loader.using( 'mmv.ui.reuse' );
			} )
			.then( function ( require ) {
				const reuse = require( 'mmv.ui.reuse' );

				// Share URL: MMV builds the share URL as
				// `descriptionUrl + #/media/Title`. Our PHP getDescriptionUrl
				// returns the URL with `?page=N` when the descriptionUrl is
				// looked up during a transform that carries the page (e.g.
				// the `iiurlparam=pageN-Wpx` flow), but MMV's *initial*
				// imageinfo request doesn't always pass iiurlparam — so
				// descriptionUrl sometimes arrives without the query string
				// and the share input ends up like
				// `…/wiki/File:Foo.jpg#/media/File:Foo.jpg`. Re-insert
				// `?page=N` between the URL and the `#/media/` fragment so
				// the share link always lands on the correct canvas. We
				// only touch the value when MMV already produced a share URL
				// *without* a page parameter — we never strip the fragment.
				if (
					reuse.Share &&
					reuse.Share.prototype &&
					reuse.Share.prototype.set
				) {
					const origShareSet = reuse.Share.prototype.set;
					reuse.Share.prototype.set = function ( img ) {
						origShareSet.call( this, img );
						if ( ! isCurrentImageIiif ) {
							return;
						}
						const current =
							this.$pageInput && this.$pageInput.val
								? this.$pageInput.val()
								: '';
						if ( typeof current !== 'string' || ! current ) {
							return;
						}
						const rewritten = rewriteIiifShareUrl( current );
						if ( rewritten !== current ) {
							this.$pageInput.val( rewritten );
						}
					};
				}

				// Download dialog "Original" size:
				// MMV's`Download.prototype.set` stores`image.url`
				// (= File::getUrl()) and later wires the download button
				// to it whenever the user picks the "Original" option.
				// MMV's *initial* imageinfo API call for the lightbox
				// has no iiurlparam, so the server resolves getUrl() to
				// canvas 1 — making "Original" always point at page 1 of
				// a multi-page IIIF document.
				//
				// Override `image.url` on the download pane's local copy
				// with the canvas the user opened (`currentIiifFullUrl`,
				// set from `data-iiif-full-url` at click time). Mutating
				// the local field — not the shared ImageModel — keeps the
				// rest of MMV's state untouched.
				if (
					reuse.Download &&
					reuse.Download.prototype &&
					reuse.Download.prototype.set
				) {
					const origDownloadSet = reuse.Download.prototype.set;
					reuse.Download.prototype.set = function ( img ) {
						origDownloadSet.call( this, img );
						if (
							isCurrentImageIiif &&
							currentIiifFullUrl &&
							this.image
						) {
							this.image = Object.assign(
								Object.create(
									Object.getPrototypeOf( this.image )
								),
								this.image,
								{ url: currentIiifFullUrl }
							);
							// The dropdown is reset to "original" inside
							// set(); re-fire its handler so the download
							// button picks up the rewritten URL.
							if ( typeof this.handleSizeSwitch === 'function' ) {
								try {
									this.handleSizeSwitch();
								} catch {}
							}
						}
					};
				}

				// HTML embed code: MMV builds the wrapping `<a href>` from
				// `descriptionUrl + Config.getMediaHash(image.title)` — the
				// hash carries the spoofed `.jpg` title from MMV's internal
				// model, which 404s the file-page lookup when the snippet
				// is pasted elsewhere. Patch the formatter so the embed
				// HTML's href runs through the same fragment rewrite as
				// the share URL.
				if (
					reuse.EmbedFileFormatter &&
					reuse.EmbedFileFormatter.prototype &&
					reuse.EmbedFileFormatter.prototype.getThumbnailHtml
				) {
					const EFF = reuse.EmbedFileFormatter;
					const origGetHtml = EFF.prototype.getThumbnailHtml;
					EFF.prototype.getThumbnailHtml = function (
						info,
						imgUrl,
						width,
						height
					) {
						let out = origGetHtml.call(
							this,
							info,
							imgUrl,
							width,
							height
						);
						if ( isCurrentImageIiif ) {
							out = out.replace(
								/href=("|')([^"']*?)\1/g,
								function ( match, quote, url ) {
									return (
										'href=' +
										quote +
										rewriteIiifShareUrl( url ) +
										quote
									);
								}
							);
						}
						return out;
					};
				}

				// Wikitext embed code: inject "|page=N" between the
				// title and the rest of the parameters so a copy-pasted
				// [[File:Foo|…]] snippet lands the reader back on the same
				// canvas of a multi-page document. Also strip the spoofed
				// `.jpg` that MMV pulls from imageInfo.title: copy-pasting a
				// `[[File:Foo.jpg]]` snippet would otherwise produce a
				// broken file link in the target article, since the real
				// wiki file lives at `File:Foo` (extension-less, like the
				// IIIF object ID). The outer-scope `currentIiifPage` is
				// kept in sync by the capturing click listener above and
				// the mmv-metadata handler below.
				if (
					reuse.EmbedFileFormatter &&
					reuse.EmbedFileFormatter.prototype &&
					reuse.EmbedFileFormatter.prototype.getThumbnailWikitext
				) {
					const EFF = reuse.EmbedFileFormatter;
					const origGetWikitext = EFF.prototype.getThumbnailWikitext;
					EFF.prototype.getThumbnailWikitext = function (
						title,
						width,
						caption,
						alt
					) {
						let out = origGetWikitext.call(
							this,
							title,
							width,
							caption,
							alt
						);
						if ( ! isCurrentImageIiif ) {
							return out;
						}
						// Strip the spoofed `.jpg` from the title.
						out = out.replace(
							new RegExp(
								'^\\[\\[([^\\|\\]]+?)\\.' +
									iiifTitle.SPOOF_EXTENSION +
									'(?=[\\|\\]])',
								'i'
							),
							'[[$1'
						);
						if ( currentIiifPage > 1 && ! /\|page=/.test( out ) ) {
							out = out.replace(
								/^\[\[([^\|\]]+)/,
								'[[$1|page=' + currentIiifPage
							);
						}
						return out;
					};
				}
			} );
	}

	/**
	 * Take a share URL MMV built ("https://…/File:Foo[?…]#/media/File:Foo.jpg")
	 * and rewrite it so it works when followed:
	 *
	 *  - strip the spoofed `.jpg` from the `#/media/` fragment so MMV's
	 *    hashchange handler finds the file-page thumb on landing (which
	 *    is keyed off the un-spoofed wgTitle, not data-iiif-title), and
	 *  - inject `?page=N` between the URL and the fragment when the user
	 *    is looking at a canvas other than 1 of a multi-page document
	 *    (MMV's natural Share URL never carries `?page=` because the
	 *    initial imageinfo API call has no iiurlparam).
	 * @param {string} url
	 */
	function rewriteIiifShareUrl( url ) {
		if ( typeof url !== 'string' || ! url ) {
			return url;
		}
		let out = url;

		// 1. Strip spoofed `.jpg` from the `#/media/<title>.jpg[/N]` fragment.
		out = out.replace(
			new RegExp(
				'(#/media/[^?#]*?)\\.' +
					iiifTitle.SPOOF_EXTENSION +
					'(?=$|/|\\?|#)',
				'i'
			),
			'$1'
		);

		// 2. Insert ?page=N between the URL and the (now-clean) fragment
		//    for canvases > 1, but only if the URL doesn't already carry
		//    a page param.
		if ( currentIiifPage > 1 && ! /[?&]page=/.test( out ) ) {
			const hashIdx = out.indexOf( '#' );
			const base = hashIdx >= 0 ? out.slice( 0, hashIdx ) : out;
			const frag = hashIdx >= 0 ? out.slice( hashIdx ) : '';
			const sep = base.indexOf( '?' ) >= 0 ? '&' : '?';
			out = base + sep + 'page=' + currentIiifPage + frag;
		}

		return out;
	}

	/**
	 * Build the local file page URL for the current IIIF image,
	 * appending ?page=N when on a multi-page canvas other than 1.
	 *
	 * `data-iiif-title` carries the spoofed `.jpg` extension Hooks
	 * appends to extension-less IDs so MMV accepts the file. The
	 * real wiki page sits at the un-spoofed title — link there or the
	 * file-page's File usage listing loses every wikitext usage.
	 */
	function buildLocalFileUrl() {
		if ( ! currentIiifTitle ) {
			return null;
		}
		const clean = iiifTitle.unspoof( currentIiifTitle );
		try {
			const title = new mw.Title( clean );
			return currentIiifPage > 1
				? title.getUrl( { page: currentIiifPage } )
				: title.getUrl();
		} catch {
			return null;
		}
	}

	/**
	 * Patch the static MMV overlay buttons that point to imageInfo.url
	 * (download / "open in new tab") or to descriptionUrl ("More details",
	 * reuse). MMV builds these once per image-open and never updates them
	 * to reflect the current canvas of a multi-page document, so we
	 * overwrite them here.
	 */
	function patchMmvOverlayLinks() {
		// The full-resolution / "download original" button uses imageInfo.url
		// (= File::getUrl()). With our PHP fix this normally already points
		// to the current canvas, but we patch defensively in case the API
		// response was cached when the page was first visited.
		if ( currentIiifFullUrl ) {
			document
				.querySelectorAll(
					'.mw-mmv-download-button, .mw-mmv-download-original-button'
				)
				.forEach( function ( el ) {
					if ( el.tagName === 'A' || el.hasAttribute( 'href' ) ) {
						el.setAttribute( 'href', currentIiifFullUrl );
					}
				} );

			// Legacy MMV wrapped the displayed image in an <a>; modern MMV
			// does not, but we patch both shapes for safety.
			const mmvImageLink = document.querySelector( '.mw-mmv-image a' );
			if ( mmvImageLink ) {
				mmvImageLink.href = currentIiifFullUrl;
			}
		}

		// "More details" stripe button and any reuse buttons that link to
		// the local file description page need ?page=N for multi-page
		// documents so the user lands on the same canvas they were viewing.
		const localUrl = buildLocalFileUrl();
		if ( localUrl ) {
			document
				.querySelectorAll(
					'.mw-mmv-description-page-button, ' +
						'.mw-mmv-reuse-button[href], ' +
						'.mw-mmv-stripe-button[href]'
				)
				.forEach( function ( el ) {
					el.setAttribute( 'href', localUrl );
				} );
		}
	}

	// mmv-metadata is a jQuery event — native addEventListener cannot catch it.
	$( document ).on( 'mmv-metadata', function ( e ) {
		const image = e.image;
		isCurrentImageIiif = !! (
			image &&
			image.thumbnail &&
			image.thumbnail.hasAttribute( 'data-iiif-title' )
		);

		if ( ! isCurrentImageIiif ) {
			return;
		}

		// Capture state about the currently displayed IIIF image so the
		// share / download / "more details" patches can all consult it.
		currentIiifTitle = image.thumbnail.getAttribute( 'data-iiif-title' );
		currentIiifFullUrl =
			image.thumbnail.getAttribute( 'data-iiif-full-url' ) || null;
		const pageAttr = image.thumbnail.getAttribute( 'data-iiif-page' );
		currentIiifPage = pageAttr ? parseInt( pageAttr, 10 ) || 1 : 1;

		// Apply patches synchronously; MMV renders the buttons before
		// firing mmv-metadata, so they exist by the time we get here.
		patchMmvOverlayLinks();

		// Share / EmbedFileFormatter are patched eagerly above; nothing
		// more to do here.

		// Defensive map update: if MMV navigates to a multi-page image whose
		// thumbnail src wasn't in the DOM at the most recent
		// wikipage.content rebuild (e.g. MMV's own prev/next arrows
		// surfacing a canvas that wasn't pre-rendered on the page), make
		// sure the eager ThumbnailInfo patch can still resolve its page.
		if (
			image.thumbnail.hasAttribute( 'data-iiif-page' ) &&
			currentIiifPage > 1 &&
			image.src
		) {
			iiifPageByUrl.set( image.src, currentIiifPage );
		}
	} );
} );
