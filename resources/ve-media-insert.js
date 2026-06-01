// Strip the spoofed `.jpg` from IIIF files when VisualEditor's "Insert media"
// dialog turns a chosen search result into the inserted node.
//
// VE needs the spoofed `.jpg` to *display* the result in the media-search grid
// (it filters results by file extension — see media-search.js, which flags
// each IIIF result with `isInstantIIIF`). The inserted wikitext, however, must
// be extension-less so VE-inserted embeds match hand-written `[[File:<id>]]`
// wikitext. ve.ui.MWMediaDialog#confirmSelectedImage builds the node's
// `resource`/`href` from `info.title`, so we un-spoof that title there — only
// for flagged IIIF results, leaving Commons/local `.jpg` files untouched.
//
// The `.jpg` the MMV overlay needs on rendered thumbnails is re-added
// independently by the ThumbnailBeforeProduceHTML hook (data-iiif-title).
//
// Registered as a VisualEditorPluginModules attribute (extension.json), so VE
// loads and runs this only while the editor is initialising — before the user
// can open the media dialog. We still wait on ext.visualEditor.mwimage (which
// defines ve.ui.MWMediaDialog) since plugin-module load order isn't guaranteed.
( function () {
	'use strict';

	// Shared spoof/unspoof helpers (mirrored in src/IIIFTitle.php). Loaded via
	// the ext.instantIIIF.title dependency.
	const iiifTitle = window.iiifTitle;

	mw.loader.using( 'ext.visualEditor.mwimage' ).then( patch, function () {} );

	function patch() {
		const ve = window.ve;
		if (
			! ve ||
			! ve.ui ||
			! ve.ui.MWMediaDialog ||
			ve.ui.MWMediaDialog.prototype._instantIIIFPatched
		) {
			return;
		}
		const proto = ve.ui.MWMediaDialog.prototype;
		const origConfirm = proto.confirmSelectedImage;

		proto.confirmSelectedImage = function () {
			const info = this.selectedImageInfo;
			if ( ! info || ! info.isInstantIIIF ) {
				return origConfirm.apply( this, arguments );
			}
			// confirmSelectedImage (synchronously) bakes `info.title` into the
			// node's resource/href. Un-spoof it across that call so the embed
			// serialises extension-less, then restore the original so the
			// cached search-result object VE still holds keeps the spoofed
			// title it needs to render in the results grid.
			const origTitle = info.title;
			const origCanonical = info.canonicaltitle;
			if ( origTitle ) {
				info.title = iiifTitle.unspoof( origTitle );
			}
			if ( origCanonical ) {
				info.canonicaltitle = iiifTitle.unspoof( origCanonical );
			}
			try {
				return origConfirm.apply( this, arguments );
			} finally {
				info.title = origTitle;
				info.canonicaltitle = origCanonical;
			}
		};

		proto._instantIIIFPatched = true;
	}
} )();
