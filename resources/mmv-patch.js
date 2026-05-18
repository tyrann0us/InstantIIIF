// On file detail pages, prevent MMV from intercepting prev/next
// navigation links for IIIF multi-page documents.  The PHP hook
// marks these <img> elements with data-iiif-navigate="1".
// Removing the mw-file-description class from their parent <a>
// makes MMV's bootstrap skip them (it collects via
// '.mw-file-description img').
//
// This runs via mw.hook('wikipage.content') which fires in
// registration order — our module loads with position:top, so
// our handler is registered (and runs) before MMV's bootstrap.
mw.hook( 'wikipage.content' ).add( function () {
    document.querySelectorAll( 'img[data-iiif-navigate]' ).forEach( function ( img ) {
        var link = img.closest( 'a.mw-file-description' );
        if ( link ) {
            link.classList.remove( 'mw-file-description' );
        }
    } );

    // On file detail pages the shared-upload description text contains a
    // link to getDescriptionUrl().  Since that now returns the local wiki
    // URL (for MMV), the link would point back to the same page.  Replace
    // it with the external provider URL passed via JS config.
    var providerUrl = mw.config.get( 'wgIIIFProviderUrl' );
    if ( providerUrl ) {
        document.querySelectorAll( '.sharedUploadNotice a' ).forEach( function ( a ) {
            var href = a.getAttribute( 'href' );
            // Match links that point to the local file page (the circular reference).
            // The href may be relative (/wiki/...) or absolute (https://...).
            if ( href && ( href.indexOf( '/wiki/' ) === 0 || href.indexOf( location.origin ) === 0 ) ) {
                a.href = providerUrl;
            }
        } );
    }
} );

mw.loader.using( 'mediawiki.Title' ).then( function () {
    const orig = mw.Title.newFromImg;
    mw.Title.newFromImg = function ( img ) {
        const el = img.jquery ? img[0] : img;
        const t = el.getAttribute( 'data-iiif-title' ) || el.getAttribute( 'data-mwtitle' );
        if ( t ) {
            try { return new mw.Title( t ); } catch ( e ) {}
        }
        return orig( img );
    };

    // --- IIIF-specific MMV patches ---

    // Build a map of thumbnail src URL → IIIF page number from the DOM.
    // This must happen before MMV opens so the patch can look up the page.
    const iiifPageByUrl = new Map();
    document.querySelectorAll( 'img[data-iiif-page]' ).forEach( function ( img ) {
        const page = parseInt( img.getAttribute( 'data-iiif-page' ), 10 );
        if ( page > 1 ) {
            iiifPageByUrl.set( img.getAttribute( 'src' ), page );
        }
    } );

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
    let thumbnailInfoPatched = false;
    if ( iiifPageByUrl.size > 0 ) {
        mw.loader.using( 'mmv' ).then( function ( require ) {
            const ThumbnailInfo = require( 'mmv' ).ThumbnailInfo;
            const origGet = ThumbnailInfo.prototype.get;
            ThumbnailInfo.prototype.get = function ( file, sampleUrl, width, height ) {
                if ( sampleUrl ) {
                    const page = iiifPageByUrl.get( sampleUrl );
                    if ( page ) {
                        const marker = 'page' + page + '-' + ( width || 300 ) + 'px';
                        return origGet.call( this, file, sampleUrl + '#' + marker, width, height );
                    }
                }
                return origGet.call( this, file, sampleUrl, width, height );
            };
            thumbnailInfoPatched = true;
        } );
    }

    // --- IIIF state tracking and MMV patches via mmv-metadata ---
    let isCurrentImageIiif = false;
    let currentIiifFullUrl = null;
    let shareSetPatched = false;

    // mmv-metadata is a jQuery event — native addEventListener cannot catch it.
    $( document ).on( 'mmv-metadata', function ( e ) {
        const image = e.image;
        isCurrentImageIiif = !!( image && image.thumbnail &&
            image.thumbnail.hasAttribute( 'data-iiif-title' ) );

        if ( !isCurrentImageIiif ) {
            return;
        }

        // Fix the image link in the MMV overlay for multi-page documents.
        // MMV wraps the displayed image in an <a> whose href is imageInfo.url
        // (from getUrl()), which always points to page 1.  Replace it with
        // the correct page's full-resolution URL from data-iiif-full-url.
        currentIiifFullUrl = null;
        if ( image.thumbnail && image.thumbnail.hasAttribute( 'data-iiif-full-url' ) ) {
            currentIiifFullUrl = image.thumbnail.getAttribute( 'data-iiif-full-url' );
            var mmvImage = document.querySelector( '.mw-mmv-image a' );
            if ( mmvImage ) {
                mmvImage.href = currentIiifFullUrl;
            }
        }

        // Share-URL fix: MMV builds the share URL from descriptionUrl, which
        // for IIIF files points to the external provider.  Replace it with
        // the local wiki file page URL so the share link is useful within
        // the wiki.  The local URL is derived from the spoofed title in
        // data-iiif-title (e.g. "Datei:Df_dk_0007450.jpg").
        if ( !shareSetPatched ) {
            mw.loader.using( 'mmv.ui.reuse' ).then( function ( require ) {
                const Share = require( 'mmv.ui.reuse' ).Share;
                const origSet = Share.prototype.set;
                Share.prototype.set = function ( image ) {
                    origSet.call( this, image );
                    if ( isCurrentImageIiif && image.thumbnail ) {
                        const iiifTitle = image.thumbnail.getAttribute( 'data-iiif-title' );
                        if ( iiifTitle ) {
                            try {
                                const title = new mw.Title( iiifTitle );
                                const localUrl = title.getUrl();
                                this.$pageInput.val( localUrl );
                            } catch ( err ) {}
                        }
                    }
                };
                shareSetPatched = true;
            } );
        }

        // Late ThumbnailInfo patch for pages loaded without multi-page images
        // but where MMV navigates to one (e.g. via prev/next arrows).
        if ( !thumbnailInfoPatched && image.thumbnail &&
            image.thumbnail.hasAttribute( 'data-iiif-page' ) ) {
            const page = parseInt( image.thumbnail.getAttribute( 'data-iiif-page' ), 10 );
            if ( page > 1 && image.src ) {
                iiifPageByUrl.set( image.src, page );
            }
            mw.loader.using( 'mmv' ).then( function ( require ) {
                const ThumbnailInfo = require( 'mmv' ).ThumbnailInfo;
                const origGet = ThumbnailInfo.prototype.get;
                ThumbnailInfo.prototype.get = function ( file, sampleUrl, width, height ) {
                    if ( sampleUrl ) {
                        const pg = iiifPageByUrl.get( sampleUrl );
                        if ( pg ) {
                            const marker = 'page' + pg + '-' + ( width || 300 ) + 'px';
                            return origGet.call( this, file, sampleUrl + '#' + marker, width, height );
                        }
                    }
                    return origGet.call( this, file, sampleUrl, width, height );
                };
                thumbnailInfoPatched = true;
            } );
        }
    } );
} );
