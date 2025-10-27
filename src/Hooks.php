<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF;

use MWNamespace;
use OutputPage;
use Skin;
use ThumbnailImage;

class Hooks
{
    /**
     * Load the RL module on every page.
     */
    public static function onBeforePageDisplay(OutputPage $out, Skin $skin): void
    {
        $out->addModules(['ext.instantIIIF.mmvTitlePatch']);
    }

    /**
     * Adds data-iiif-title spoofed ending to <img>.
     * Example: “File:df_dk_0007450.jpg”
     *
     * @param array<string, mixed> $imgAttrs
     * @param array<string, mixed> $linkAttrs
     */
    public static function onThumbnailBeforeProduceHTML(
        ThumbnailImage $thumb,
        array &$imgAttrs,
        array &$linkAttrs
    ): bool {

        $file = $thumb->getFile();
        if ($file instanceof \MediaWiki\Extension\InstantIIIF\IIIFFile) {
            $title = $file->getTitle();
            if ($title === null) {
                return true;
            }

            // Localized namespace text for NS_FILE (e.g., “File”).
            $nsText = $title->getNsText();
            if ($nsText === '') {
                // Fallback, but should not happen here
                $nsText = MWNamespace::getCanonicalName(NS_FILE) ?: 'File';
            }

            // DB-Key = Title without a namespace, with underscores (no spaces).
            $dbKey = $title->getDBkey();

            // MultimediaViewer requires the file to have a valid image file extension,
            // so we spoof one here.
            $imgAttrs['data-iiif-title'] = sprintf('%s:%s.jpg', $nsText, $dbKey);

            // Bonus: Include dimensions (helps MMV).
            $imgAttrs['data-file-width'] = $thumb->getWidth();
            $imgAttrs['data-file-height'] = $thumb->getHeight();
        }
        return true;
    }
}
