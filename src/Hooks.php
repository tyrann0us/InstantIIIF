<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF;

use File;
use MediaWiki\Context\IContextSource;
use MediaWiki\Page\ImageHistoryList;
use MediaWiki\Page\ImagePage;
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
     * Example: "File:df_dk_0007450.jpg"
     *
     * @param array<string, mixed> $imgAttrs
     * @param array<string, mixed>|bool $linkAttrs
     */
    public static function onThumbnailBeforeProduceHTML(
        ThumbnailImage $thumb,
        array &$imgAttrs,
        array|bool &$linkAttrs
    ): bool {

        $file = $thumb->getFile();
        if ($file instanceof IIIFFile) {
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

    /**
     * Hide the file history section for IIIF files — version history is meaningless
     * for hotlinked remote resources, and the single auto-generated row shows
     * misleading data (no user, today's date).
     *
     * Suppressing the row alone still leaves the section heading rendered by
     * ImageHistoryPseudoPager::getBody(), so we also inject CSS to hide it.
     *
     * Also hide the file info below the image; it shows 0 bytes file size.
     *
     * @param ImageHistoryList $imageHistoryList
     * @param File $file
     * @param string &$line
     * @param string|null &$css
     * @return bool
     */
    public static function onImagePageFileHistoryLine(
        ImageHistoryList $imageHistoryList,
        File $file,
        string &$line,
        ?string &$css
    ): bool {

        if (!$file instanceof IIIFFile) {
            return true;
        }

        $imageHistoryList->getOutput()->addInlineStyle(
            'h2#filehistory, #mw-imagepage-section-filehistory { display: none; }' .
            ' span.fileInfo { display: none; }'
        );
        $line = '';
        return false;
    }

    /**
     * Remove the "File history" entry from the file page TOC for IIIF files.
     *
     * @param ImagePage $page
     * @param string[] &$toc
     */
    public static function onImagePageShowTOC(ImagePage $page, array &$toc): void
    {
        if (!$page->getDisplayedFile() instanceof IIIFFile) {
            return;
        }

        $toc = array_values(array_filter(
            $toc,
            static fn (string $item) => !str_contains($item, '#filehistory')
        ));
    }

    /**
     * Remove the wrong upload date from extmetadata for IIIF files.
     *
     * FormatMetadata generates a DateTime fallback via
     * wfTimestamp(TS_ISO_8601, $file->getTimestamp()).
     * Since IIIFFile::getTimestamp() returns false (no local storage), wfTimestamp interprets
     * false as "now" and produces today's date. MMV then displays it as "Uploaded: <today>".
     *
     * @param array<string, mixed> &$combinedMeta
     * @param File $file
     * @param IContextSource $context
     * @param bool $single
     * @param int|null &$maxCacheTime
     */
    public static function onGetExtendedMetadata(
        array &$combinedMeta,
        File $file,
        IContextSource $context,
        bool $single,
        ?int &$maxCacheTime
    ): void {

        if (!$file instanceof IIIFFile) {
            return;
        }
        // Value must survive CommonsMetadata's normalizeMetadataTimestamps (which converts
        // empty strings to "now" via wfTimestamp) and MMV's parseExtmeta which strips HTML
        // tags and skips display when the result is falsy.
        $combinedMeta['DateTime'] = ['value' => '<>', 'source' => 'mediawiki-metadata'];
        $maxCacheTime = 0;
    }
}
