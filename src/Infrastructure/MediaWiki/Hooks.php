<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF\Infrastructure\MediaWiki;

use MWNamespace;
use OutputPage;
use Skin;
use ThumbnailImage;
use Title;

/**
 * MediaWiki hook handlers for InstantIIIF.
 */
class Hooks
{
    /**
     * Load the RL module on every page.
     *
     * @param OutputPage $out
     * @param Skin $skin
     */
    public static function onBeforePageDisplay(OutputPage $out, Skin $skin): void
    {
        $out->addModules(['ext.instantIIIF.mmvTitlePatch']);
    }

    /**
     * Adds data-iiif-title attribute to <img> tags for MultimediaViewer compatibility.
     *
     * MMV requires files to have a valid image extension, so we spoof one here.
     *
     * @param ThumbnailImage $thumb
     * @param array<string, mixed> $imgAttrs
     * @param array<string, mixed>|bool $linkAttrs
     */
    public static function onThumbnailBeforeProduceHTML(
        ThumbnailImage $thumb,
        array &$imgAttrs,
        array|bool &$linkAttrs
    ): bool {

        $file = $thumb->getFile();

        if (!$file instanceof IIIFFile) {
            return true;
        }

        $title = $file->getTitle();
        if ($title === null) {
            return true;
        }

        $nsText = self::getFileNamespaceText($title);
        $dbKey = $title->getDBkey();

        // MMV requires the file to have a valid image file extension
        $imgAttrs['data-iiif-title'] = sprintf('%s:%s.jpg', $nsText, $dbKey);

        // Include dimensions for MMV
        $imgAttrs['data-file-width'] = $thumb->getWidth();
        $imgAttrs['data-file-height'] = $thumb->getHeight();

        return true;
    }

    /**
     * Get the localized namespace text for NS_FILE.
     */
    private static function getFileNamespaceText(Title $title): string
    {
        $nsText = $title->getNsText();

        if ($nsText !== '') {
            return $nsText;
        }

        // Fallback to canonical name
        $canonical = MWNamespace::getCanonicalName(NS_FILE);
        return $canonical !== false ? $canonical : 'File';
    }
}
