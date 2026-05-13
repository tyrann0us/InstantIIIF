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

    // --- Share-URL fix and state tracking via mmv-metadata ---
    let isCurrentImageIiif = false;
    let shareSetPatched = false;

    // mmv-metadata is a jQuery event — native addEventListener cannot catch it.
    $( document ).on( 'mmv-metadata', function ( e ) {
        const image = e.image;
        isCurrentImageIiif = !!( image && image.thumbnail &&
            image.thumbnail.hasAttribute( 'data-iiif-title' ) );

        if ( !isCurrentImageIiif ) {
            return;
        }

        // Share-URL fix: MMV appends #/media/File:… via Config.getMediaHash().
        // That fragment only makes sense on local wiki pages; on an external
        // IIIF provider URL it is meaningless.
        if ( !shareSetPatched ) {
            mw.loader.using( 'mmv.ui.reuse' ).then( function ( require ) {
                const Share = require( 'mmv.ui.reuse' ).Share;
                const origSet = Share.prototype.set;
                Share.prototype.set = function ( image ) {
                    origSet.call( this, image );
                    if ( isCurrentImageIiif ) {
                        const val = this.$pageInput.val();
                        if ( val && val.includes( '#' ) ) {
                            this.$pageInput.val( val.replace( /#.*$/, '' ) );
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
