<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF;

use File;
use MediaWiki\Context\IContextSource;
use MediaWiki\Context\RequestContext;
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
     *
     * On File: pages with an IIIF file, also pass the provider URL as a
     * JS config variable so the client-side code can fix the shared-upload
     * description link (which would otherwise point to the local URL
     * because getDescriptionUrl() now returns the wiki page URL).
     */
    public static function onBeforePageDisplay(OutputPage $out, Skin $skin): void
    {
        $out->addModules(['ext.instantIIIF.mmvPatch']);

        $iiifRepos = self::collectIIIFRepoDescriptors();
        if ($iiifRepos !== []) {
            $out->addJsConfigVars('wgInstantIIIFRepos', $iiifRepos);
            $out->addModules(['ext.instantIIIF.mediaSearch']);
        }

        $title = $out->getTitle();
        if ($title === null || $title->getNamespace() !== NS_FILE) {
            return;
        }

        $services = \MediaWiki\MediaWikiServices::getInstance();
        $file = $services->getRepoGroup()->findFile($title);
        if (!$file instanceof IIIFFile) {
            return;
        }

        $providerUrl = $file->getProviderUrl();
        if ($providerUrl !== '') {
            $out->addJsConfigVars('wgIIIFProviderUrl', $providerUrl);
        }
    }

    /**
     * Collect descriptors for every configured InstantIIIF repo so the
     * client-side media-search patch can recognise them and route their
     * search requests through a direct title lookup.
     *
     * @return list<array{apiurl: string, idPatterns: list<string>}>
     */
    private static function collectIIIFRepoDescriptors(): array
    {
        $descriptors = [];
        \MediaWiki\MediaWikiServices::getInstance()
            ->getRepoGroup()
            // phpcs:ignore Syde.Functions.ArgumentTypeDeclaration.NoArgumentType -- forEachForeignRepo callback receives FileRepo
            ->forEachForeignRepo(static function ($repo) use (&$descriptors): bool {
                if ($repo instanceof Repo) {
                    $descriptors[] = [
                        'apiurl' => Repo::resolveLocalApiUrl(),
                        'idPatterns' => $repo->idPatterns(),
                    ];
                }
                return false;
            });
        return $descriptors;
    }

    /**
     * Adds data-iiif-title spoofed ending to <img>.
     * Example: "File:df_dk_0007450.jpg"
     *
     * Also fixes the link target for IIIF thumbnails:
     * - file-link context (main image on file detail page): point to the
     *   correct page's full-resolution IIIF URL instead of always page 1.
     * - desc-link context (article thumbnails, prev/next on file pages):
     *   already handled by passing `page` to ThumbnailImage, which makes
     *   getDescLinkAttribs() add `?page=N` automatically.
     *
     * @param array<string, mixed> $imgAttrs
     * @param array<string, mixed>|bool $linkAttrs
     */
    // phpcs:ignore Syde.Functions.FunctionLength.TooLong, SlevomatCodingStandard.Complexity.Cognitive.ComplexityTooHigh -- Pending full refactor
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

        // Localized namespace text for NS_FILE (e.g., "File").
        $nsText = $title->getNsText();
        if ($nsText === '') {
            // Fallback, but should not happen here
            $nsText = MWNamespace::getCanonicalName(NS_FILE) ?: 'File';
        }

        // DB-Key = Title without a namespace, with underscores (no spaces).
        $dbKey = $title->getDBkey();

        // MultimediaViewer requires the file to have a valid image file
        // extension, so we spoof one here. Only append it if the title
        // doesn't already end with a recognised image extension —
        // otherwise we'd produce e.g. "Df_dk_multipage.jpg.jpg", and the
        // imageinfo API call from MMV would fail to resolve the manifest
        // because removeImageExtension() only strips one extension.
        $spoofedKey = $dbKey;
        if (!preg_match('/\.(jpg|jpeg|png|gif|bmp|webp)$/i', $spoofedKey)) {
            $spoofedKey .= '.jpg';
        }
        $imgAttrs['data-iiif-title'] = sprintf('%s:%s', $nsText, $spoofedKey);

        // Bonus: Include dimensions (helps MMV).
        $imgAttrs['data-file-width'] = $thumb->getWidth();
        $imgAttrs['data-file-height'] = $thumb->getHeight();

        $page = $file->lastTransformPage();

        // For multi-page documents, include the page number and the
        // full-resolution URL for that page. The JS patch uses the page
        // number for the ThumbnailInfo API call and the full URL to fix
        // the image link in the MMV overlay (which otherwise always
        // points to page 1 because getUrl() is not page-aware).
        if ($file->isMultipage()) {
            $imgAttrs['data-iiif-page'] = $page;
            if ($page > 1) {
                $fullUrl = $file->getUrlForPage($page);
                if ($fullUrl !== '') {
                    $imgAttrs['data-iiif-full-url'] = $fullUrl;
                }
            }
        }

        // Determine the link context once based on the *incoming* attrs.
        // - "file-link": main image on file detail page. MediaWiki core
        //   passes no class here and an href pointing at the raw IIIF URL.
        // - "desc-link": prev/next thumbnails on file detail pages and
        //   article thumbnails. MediaWiki core sets
        //   class="mw-file-description" and an href pointing at the local
        //   file page (with `?page=N` appended for multi-page contexts).
        $isFileLink = is_array($linkAttrs)
            && isset($linkAttrs['href'])
            && !isset($linkAttrs['class']);
        $isDescLink = is_array($linkAttrs)
            && isset($linkAttrs['class'])
            && str_contains((string) $linkAttrs['class'], 'mw-file-description');

        // For multi-page documents, the file-link href returned by core
        // points at canvas 1 (`File::getUrl()` legacy behaviour); rewrite
        // it to the canvas the user is actually looking at.
        if ($isFileLink && $page > 1 && $file->isMultipage()) {
            $pageUrl = $file->getUrlForPage($page);
            if ($pageUrl !== '') {
                $linkAttrs['href'] = $pageUrl;
            }
        }

        // Tag prev/next-on-same-file navigation thumbnails BEFORE we
        // promote the file-link context with `mw-file-description`,
        // otherwise that promotion would also pick up here and the JS
        // module would strip the class off the main image.
        if ($isDescLink && $file->isMultipage()) {
            $pageTitle = RequestContext::getMain()->getTitle();
            if (
                $pageTitle !== null
                && $pageTitle->getNamespace() === NS_FILE
                && $pageTitle->getDBkey() === $title->getDBkey()
            ) {
                $imgAttrs['data-iiif-navigate'] = '1';
            }
        }

        // Add `mw-file-description` to the main image link so MMV
        // recognises it (its bootstrap collects via the same selector),
        // which both intercepts the click into the overlay and surfaces
        // the "Open in Media Viewer" stripe button. Prev/next
        // thumbs are tagged with data-iiif-navigate above and stripped
        // back by mmv-patch.js, so navigation behaviour does not
        // regress.
        if ($isFileLink && is_array($linkAttrs)) {
            $existing = $linkAttrs['class'] ?? '';
            $linkAttrs['class'] = trim($existing . ' mw-file-description');
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

        $label = self::extractLocalizedString($manifest['label'] ?? '', $context);
        if ($label !== '') {
            $combinedMeta['ObjectName'] = ['value' => $label, 'source' => 'extension'];
        }

        $providerUrl = $file->getProviderUrl();

        $attribution = self::extractAttribution($manifest, $context);
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

        // v2: license, v3: rights — usually a URL or list of URLs.
        // Providers vary: BSB returns `["https://…"]`, SLUB returns
        // nothing (link is in metadata under "Rechteinformationen"),
        // Fotothek returns nothing either. Fall back through metadata,
        // then the provider landing page.
        $licenseUrl = self::extractLicenseUrl($manifest['rights'] ?? $manifest['license'] ?? '');
        if ($licenseUrl === '') {
            $licenseUrl = $file->getLicenseUrlFromMetadata($resolved);
        }
        if ($licenseUrl === '') {
            $licenseUrl = $providerUrl;
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
     * Extract a license URL from the manifest's `rights`/`license` field,
     * accepting plain strings and IIIF v2 lists of strings.
     */
    private static function extractLicenseUrl(mixed $value): string
    {
        if (is_string($value)) {
            return preg_match('~^https?://~', $value) ? $value : '';
        }
        if (is_array($value)) {
            foreach ($value as $entry) {
                if (is_string($entry) && preg_match('~^https?://~', $entry)) {
                    return $entry;
                }
            }
        }
        return '';
    }

    /**
     * Build the preferred-language priority list.
     *
     * Picks (in order): the user's interface language when an
     * IContextSource is available (extmetadata is rendered for that
     * user in MMV), the wiki's content language, then English as a
     * universal IIIF fallback.
     *
     * @return list<string>
     */
    private static function preferredLanguages(?IContextSource $context = null): array
    {
        $langs = [];
        if ($context !== null) {
            try {
                $userLang = $context->getLanguage()->getCode();
                if ($userLang !== '') {
                    $langs[] = $userLang;
                }
            } catch (\Throwable $unused) {
                // No language on this context — fall through.
                unset($unused);
            }
        }
        try {
            $contentLang = \MediaWiki\MediaWikiServices::getInstance()
                ->getContentLanguage()
                ->getCode();
            if ($contentLang !== '' && !in_array($contentLang, $langs, true)) {
                $langs[] = $contentLang;
            }
        } catch (\Throwable $unused) {
            // Services unavailable.
            unset($unused);
        }
        if (!in_array('en', $langs, true)) {
            $langs[] = 'en';
        }
        return $langs;
    }

    /**
     * Resolve a IIIF value to a single string. Accepts:
     *  - plain string
     *  - v2 single language object: `{"@value": "...", "@language": "..."}`
     *  - v2 array of language objects: `[{"@language":"de","@value":"…"}, …]`
     *  - v3 language map: `{"de": ["…"], "en": ["…"]}`
     *
     * Picks the closest available translation to the user's interface
     * language, falling back through the wiki's content language to
     * English, then to the first translation present.
     */
    private static function extractLocalizedString(
        mixed $value,
        ?IContextSource $context = null
    ): string {

        if (is_string($value)) {
            return $value;
        }
        if (!is_array($value)) {
            return '';
        }
        if (isset($value['@value']) && is_string($value['@value'])) {
            return $value['@value'];
        }
        $preferred = self::preferredLanguages($context);
        if (isset($value[0]) && is_array($value[0]) && isset($value[0]['@value'])) {
            return self::pickLocalizedFromList($value, $preferred);
        }
        return self::pickLocalizedFromMap($value, $preferred);
    }

    /**
     * @param array<int, mixed> $entries
     * @param list<string> $preferred
     */
    private static function pickLocalizedFromList(array $entries, array $preferred): string
    {
        foreach ($preferred as $lang) {
            foreach ($entries as $entry) {
                if (
                    is_array($entry)
                    && ($entry['@language'] ?? null) === $lang
                    && is_string($entry['@value'] ?? null)
                ) {
                    return $entry['@value'];
                }
            }
        }
        foreach ($entries as $entry) {
            if (is_array($entry) && is_string($entry['@value'] ?? null)) {
                return $entry['@value'];
            }
        }
        return '';
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string> $preferred
     */
    private static function pickLocalizedFromMap(array $value, array $preferred): string
    {
        foreach ($preferred as $lang) {
            $candidate = $value[$lang] ?? null;
            if (is_array($candidate) && isset($candidate[0]) && is_string($candidate[0])) {
                return $candidate[0];
            }
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
     * The `attribution` field may be:
     *  - a plain string ("…HTML…")
     *  - a single language object `{"@language":"de","@value":"…"}`
     *  - an array of language objects (BSB)
     *  - a v3 language map `{"de":["…"]}`
     *
     * @param array<string, mixed> $manifest
     */
    private static function extractAttribution(
        array $manifest,
        ?IContextSource $context = null
    ): string {

        $attribution = $manifest['attribution'] ?? '';
        if (is_string($attribution) && $attribution !== '') {
            return $attribution;
        }
        if (is_array($attribution)) {
            $resolved = self::extractLocalizedString($attribution, $context);
            if ($resolved !== '') {
                return $resolved;
            }
        }

        $stmt = $manifest['requiredStatement'] ?? null;
        if (is_array($stmt) && isset($stmt['value'])) {
            return self::extractLocalizedString($stmt['value'], $context);
        }

        return '';
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
