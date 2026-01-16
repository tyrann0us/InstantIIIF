mw.loader.using( 'mediawiki.Title' ).then( function () {
    const orig = mw.Title.newFromImg;
    mw.Title.newFromImg = function ( img ) {
        const el = img.jquery ? img[0] : img;
        const t = el.getAttribute('data-iiif-title') || el.getAttribute('data-mwtitle');
        if ( t ) {
            try { return new mw.Title( t ); } catch (e) {}
        }
        return orig( img );
    };
} );
