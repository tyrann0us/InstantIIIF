/**
 * Patches mw.Title.newFromImg to handle IIIF files without proper extensions.
 *
 * MultimediaViewer expects files to have image extensions, but IIIF object IDs
 * typically don't have them. We add a spoofed .jpg extension via data-iiif-title.
 */
mw.loader.using('mediawiki.Title').then(function () {
    const orig = mw.Title.newFromImg;

    mw.Title.newFromImg = function (img) {
        const el = img.jquery ? img[0] : img;
        const t = el.getAttribute('data-iiif-title') || el.getAttribute('data-mwtitle');

        if (t) {
            try {
                return new mw.Title(t);
            } catch (e) {
                // Fall through to original
            }
        }

        return orig(img);
    };
});
