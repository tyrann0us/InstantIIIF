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

    // --- Share-URL fix for IIIF files ---
    // MMV appends #/media/File:… to the share URL via Config.getMediaHash().
    // That fragment only makes sense on local wiki pages; on an external
    // IIIF provider URL it is meaningless.

    let isCurrentImageIiif = false;
    let shareSetPatched = false;

    // mmv-metadata is a jQuery event — native addEventListener cannot catch it.
    $( document ).on( 'mmv-metadata', function ( e ) {
        const image = e.image;
        isCurrentImageIiif = !!( image && image.thumbnail &&
            image.thumbnail.hasAttribute( 'data-iiif-title' ) );

        if ( !isCurrentImageIiif || shareSetPatched ) {
            return;
        }

        // mmv.ui.reuse is lazy-loaded but exports Share. Patch
        // Share.prototype.set once to strip the hash for IIIF files.
        // At this point mmv.bootstrap is already loaded (MMV is running),
        // but mmv.ui.reuse may not be. mw.loader.using will load it on
        // demand or return immediately if already loaded.
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
    } );
} );
