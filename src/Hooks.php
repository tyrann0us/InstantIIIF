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
        $out->addModules(['ext.instantIIIF.mmvPatch']);
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

            // Localized namespace text for NS_FILE (e.g., "File").
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

            // For multi-page documents, include the page number so that
            // the JS patch can forward it to MMV's ThumbnailInfo API call.
            if ($file->isMultipage()) {
                $imgAttrs['data-iiif-page'] = $file->lastTransformPage();
            }
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
     * Populate extmetadata for IIIF files from their IIIF manifest.
     *
     * Suppresses the spurious upload date (FormatMetadata falls back to
     * wfTimestamp("now") because IIIFFile has no local storage) and maps
     * manifest fields to the extmetadata keys that MMV consumes.
     *
     * The DateTime sentinel '<>' survives CommonsMetadata's
     * normalizeMetadataTimestamps (wfTimestamp returns false for non-date
     * strings) and is stripped to '' by MMV's parseExtmeta, which then
     * skips the "Uploaded" label for falsy values.
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
        $combinedMeta['DateTime'] = ['value' => '<>', 'source' => 'extension'];

        $resolved = $file->getResolvedManifest();
        if ($resolved === null) {
            return;
        }

        $manifest = $resolved['manifestRaw'];

        $label = self::extractLocalizedString($manifest['label'] ?? '');
        if ($label !== '') {
            $combinedMeta['ObjectName'] = ['value' => $label, 'source' => 'extension'];
        }

        $attribution = self::extractAttribution($manifest);
        if ($attribution !== '') {
            $combinedMeta['Credit'] = [
                'value' => $attribution, 'source' => 'extension',
            ];
            $plain = str_replace(
                ['<br>', '<br/>', '<br />'],
                ' ',
                $attribution
            );
            $plain = trim(
                // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags, WordPressVIPMinimum.Functions.StripTags.StripTagsOneParameter -- not a WP project
                (string) preg_replace('/\s+/', ' ', strip_tags($plain))
            );
            $combinedMeta['Attribution'] = ['value' => $plain, 'source' => 'extension'];
            $combinedMeta['AttributionRequired'] = ['value' => 'true', 'source' => 'extension'];
        }

        // v2: license, v3: rights — both are URLs to the license document.
        // Falls back to the provider landing page (getDescriptionUrl).
        $licenseUrl = self::extractString($manifest['rights'] ?? $manifest['license'] ?? '');
        if ($licenseUrl === '') {
            $licenseUrl = $file->getDescriptionUrl();
        }
        if ($licenseUrl !== '') {
            $combinedMeta['LicenseUrl'] = ['value' => $licenseUrl, 'source' => 'extension'];
            $shortName = self::licenseShortName($licenseUrl);
            // MMV only creates a License object when LicenseShortName is set.
            // Without it, the license link falls back to filePageUrl with a
            // meaningless ?uselang=…#… fragment appended by MMV.
            if ($shortName === '') {
                $shortName = wfMessage('instantiiif-license-shortname')
                    ->inContentLanguage()->text();
            }
            $combinedMeta['LicenseShortName'] = [
                'value' => $shortName, 'source' => 'extension',
            ];
        }
    }

    /**
     * Resolve a IIIF value that may be a plain string or a v3 language map
     * to a single string, preferring the first available translation.
     */
    private static function extractLocalizedString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (!is_array($value)) {
            return '';
        }
        if (isset($value['@value']) && is_string($value['@value'])) {
            return $value['@value'];
        }
        foreach ($value as $langValues) {
            if (is_array($langValues) && isset($langValues[0]) && is_string($langValues[0])) {
                return $langValues[0];
            }
        }
        return '';
    }

    /**
     * Extract attribution text from a IIIF manifest.
     * v2 uses the `attribution` field, v3 uses `requiredStatement.value`.
     *
     * @param array<string, mixed> $manifest
     */
    private static function extractAttribution(array $manifest): string
    {
        $attribution = $manifest['attribution'] ?? '';
        if (is_string($attribution) && $attribution !== '') {
            return $attribution;
        }
        if (is_array($attribution)) {
            $resolved = self::extractLocalizedString($attribution);
            if ($resolved !== '') {
                return $resolved;
            }
        }

        $stmt = $manifest['requiredStatement'] ?? null;
        if (is_array($stmt) && isset($stmt['value'])) {
            return self::extractLocalizedString($stmt['value']);
        }

        return '';
    }

    private static function extractString(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * Derive a human-readable short name from well-known license URLs
     * (Creative Commons, RightsStatements.org).
     */
    private static function licenseShortName(string $url): string
    {
        if (preg_match('#creativecommons\.org/licenses/([a-z-]+)/(\d+\.\d+)#i', $url, $match)) {
            return 'CC ' . strtoupper($match[1]) . ' ' . $match[2];
        }
        if (str_contains($url, 'creativecommons.org/publicdomain/zero')) {
            return 'CC0';
        }
        if (str_contains($url, 'creativecommons.org/publicdomain/mark')) {
            return 'Public Domain';
        }
        if (preg_match('#rightsstatements\.org/vocab/([^/]+)/#i', $url, $match)) {
            return str_replace('-', ' ', $match[1]);
        }
        return '';
    }
}
