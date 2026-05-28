<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF;

use File;
use MediaHandler;
use MediaTransformError;
use MediaWiki\Context\RequestContext;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use ThumbnailImage;

/**
 * Virtual File that hotlinks images from a remote IIIF Image Service using
 * the IIIF Presentation API (v2/v3) manifest.
 */
class IIIFFile extends File
{
    /**
     * @var array{
     *     provider: string,
     *     objectId: string,
     *     manifestUrl: string,
     *     manifestRaw: array<string, mixed>
     * }|null
     */
    protected ?array $resolved = null;

    /** @var array<string, array<string, mixed>> Cache for info.json per image service id */
    protected array $infoJsonMap = [];

    /**
     * Page number from the most recent transform() call.
     * Read by HookHandler::onThumbnailBeforeProduceHTML to set data-iiif-page.
     */
    protected int $lastTransformPage = 1;

    /** Provider-specific label mapping to find landing/homepage URL in `metadata` */
    /** @var array<string, list<string>> */
    private const LANDING_META_KEYS = [
        'deutsche-fotothek' => ['Link zum Werk'],
        'slub-dresden' => ['PURL', 'Persistent URL'],
    ];

    /**
     * Provider-specific label mapping to find a license URL inside the
     * `metadata` array. Used as a fallback when the manifest has no
     * top-level `license`/`rights` field (e.g., SLUB manifests).
     *
     * @var array<string, list<string>>
     */
    private const LICENSE_META_KEYS = [
        'slub-dresden' => ['Rechteinformationen', 'Rights'],
    ];

    /**
     * Non-date marker used wherever the extension wants `wfTimestamp()` to
     * return false (so consumers blank the upload date instead of falling
     * back to "now"). Any string ConvertibleTimestamp cannot parse works;
     * a falsy value would instead be treated as "now". Public so
     * MetadataExtractor's `DateTime` extmetadata sentinel
     * (used for the MMV overlay) and SpecialInstantIIIFInspect's check
     * stay in lock-step with IIIFFile::getTimestamp() (used for the
     * ApiQueryImageInfo timestamp field consumed by VisualEditor's media
     * dialog).
     */
    public const NO_TIMESTAMP_SENTINEL = '<>';

    /**
     * @param Repo $repo
     * @param Title $title
     * @param string|false $time
     */
    public function __construct(Repo $repo, Title $title, $time = false)
    {
        parent::__construct($title, $repo, $time);
        $this->repo = $repo;
    }

    public function exists(): bool
    {
        return (bool) $this->ensureResolved();
    }

    // phpcs:ignore Syde.Classes.DisallowGetterSetter.GetterFound -- MediaWiki File override
    public function getSize(): int
    {
        // We do not fetch binaries; report unknown.
        return 0;
    }

    // phpcs:ignore Syde.Classes.DisallowGetterSetter.GetterFound -- MediaWiki File override
    public function getMimeType(): string
    {
        // We always request JPEG from the Image API.
        return 'image/jpeg';
    }

    // phpcs:ignore Syde.Classes.DisallowGetterSetter.GetterFound -- MediaWiki File override
    public function getMediaType(): string
    {
        return MEDIATYPE_BITMAP;
    }

    /**
     * IIIF files have no upload timestamp. Returning a falsy value would
     * make wfTimestamp() fall back to the current time — ConvertibleTimestamp
     * treats 0/''/false as "now" — which surfaces a misleading
     * "uploaded a few seconds ago" in ApiQueryImageInfo (the `timestamp`
     * field consumed by VisualEditor's media dialog).
     *
     * Returning a non-date sentinel instead makes wfTimestamp() return false,
     * so the API emits an empty timestamp and clients skip the upload line.
     * The same sentinel is reused by MetadataExtractor for the `DateTime`
     * extmetadata field MultimediaViewer reads (see NO_TIMESTAMP_SENTINEL).
     */
    // phpcs:ignore Syde.Classes.DisallowGetterSetter.GetterFound -- MediaWiki File override
    public function getTimestamp(): string
    {
        return self::NO_TIMESTAMP_SENTINEL;
    }

    /**
     * Return the custom IIIFHandler instead of JpegHandler.
     *
     * The base File class picks the handler via getMimeType() (image/jpeg
     * → JpegHandler), which does not support the `page` parameter.
     * Overriding here lets us use IIIFHandler with multi-page support.
     *
     * @return MediaHandler|false
     */
    // phpcs:ignore Syde.Classes.DisallowGetterSetter.GetterFound, Syde.Functions.ReturnTypeDeclaration.NoReturnType -- MediaWiki File override
    public function getHandler()
    {
        if (!$this->handler) {
            $this->handler = new IIIFHandler();
        }
        return $this->handler;
    }

    /**
     * A manifest with more than one canvas represents a multi-page document.
     * This tells MediaWiki to pass the `page=` parameter through to
     * getWidth(), getHeight(), and transform().
     */
    public function isMultipage(): bool
    {
        return $this->pageCount() > 1;
    }

    /**
     * Number of pages (canvases) in the IIIF manifest.
     */
    public function pageCount(): int
    {
        $resolved = $this->ensureResolved();
        if (!$resolved) {
            return 0;
        }
        return count($this->getCanvases($resolved['manifestRaw']));
    }

    /**
     * @param int $page
     */
    // phpcs:ignore Syde.Classes.DisallowGetterSetter.GetterFound, Syde.Functions.ArgumentTypeDeclaration.NoArgumentType -- MediaWiki File override
    public function getWidth($page = 1): int
    {
        $resolved = $this->ensureResolved();
        if (!$resolved) {
            return 0;
        }
        $page = $this->normalizePage($page);
        $dims = $this->getCanvasDimensions($page);
        if ($dims[0] && $dims[1]) {
            return $dims[0];
        }
        $svc = $this->getServiceIdForPage($page);
        $info = $svc ? $this->ensureInfoJsonFor($svc) : [];
        return (int) ($info['width'] ?? 0);
    }

    /**
     * @param int $page
     */
    // phpcs:ignore Syde.Classes.DisallowGetterSetter.GetterFound, Syde.Functions.ArgumentTypeDeclaration.NoArgumentType -- MediaWiki File override
    public function getHeight($page = 1): int
    {
        $resolved = $this->ensureResolved();
        if (!$resolved) {
            return 0;
        }
        $page = $this->normalizePage($page);
        $dims = $this->getCanvasDimensions($page);
        if ($dims[0] && $dims[1]) {
            return $dims[1];
        }
        $svc = $this->getServiceIdForPage($page);
        $info = $svc ? $this->ensureInfoJsonFor($svc) : [];
        return (int) ($info['height'] ?? 0);
    }

    // phpcs:ignore Syde.Classes.DisallowGetterSetter.GetterFound -- MediaWiki File override
    public function getFullUrl(): string
    {
        return $this->getUrl();
    }

    /**
     * Return the IIIF Image API URL for the currently selected page.
     *
     * Resolution order:
     *  1. `?page=N` on the current request — set when the user is viewing
     *     a file detail page at `/wiki/File:Foo?page=6`. Anchored to the
     *     request URL so it isn't overwritten by subsequent transform()
     *     calls (e.g. for the prev/next thumbnails, whose transforms
     *     update `lastTransformPage` after the main image was rendered).
     *  2. `lastTransformPage` — used in the imageinfo API flow, where the
     *     request URL has no `?page=` parameter but MediaWiki calls
     *     transform() with `iiurlparam=pageN-Wpx` before reading `url`.
     *  3. Page 1 as the safe default.
     *
     * This way both the "Original file" link on a file description page
     * (rendered after several transforms) and the imageinfo `url` field
     * (a single transform per API request) resolve to the canvas the
     * caller actually meant.
     */
    // phpcs:ignore Syde.Classes.DisallowGetterSetter.GetterFound -- MediaWiki File override
    public function getUrl(): string
    {
        $page = $this->resolveCurrentPage();
        $svc = $this->getServiceIdForPage($page);
        return $svc ? $this->buildImageUrl($svc, 'full') : '';
    }

    /**
     * Resolve the page number to use for non-thumb URL methods.
     */
    private function resolveCurrentPage(): int
    {
        $request = RequestContext::getMain()->getRequest();
        $requestPage = (int) $request->getVal('page', '0');
        if ($requestPage > 0) {
            return $this->normalizePage($requestPage);
        }
        return $this->lastTransformPage;
    }

    /**
     * Build the full-resolution IIIF Image API URL for a specific page.
     *
     * Unlike getUrl() (always page 1), this returns the URL for any page.
     * Used by the ThumbnailBeforeProduceHTML hook to fix the main-image
     * link on file detail pages for multi-page documents.
     */
    // phpcs:ignore Syde.Classes.DisallowGetterSetter.GetterFound -- public API for hooks
    public function getUrlForPage(int $page): string
    {
        $page = $this->normalizePage($page);
        $svc = $this->getServiceIdForPage($page);
        return $svc ? $this->buildImageUrl($svc, 'full') : '';
    }

    /**
     * Local wiki file page URL for this IIIF file.
     *
     * Exposed by the API as `descriptionurl`; consumed by MMV for the
     * "More details" button, share link, and embed credit link.
     *
     * Returning the local URL (instead of the provider URL) ensures that
     * all MMV-generated links point to the wiki. The external provider
     * URL is available via getProviderUrl() and used for license fallback
     * and the shared-upload description text.
     */
    // phpcs:ignore Syde.Classes.DisallowGetterSetter.GetterFound -- MediaWiki File override
    public function getDescriptionUrl(): string
    {
        $title = $this->canonicalLocalTitle();
        if ($title === null) {
            return '';
        }
        $page = $this->resolveCurrentPage();
        $query = $page > 1 ? 'page=' . $page : '';
        return $title->getFullURL($query, false, PROTO_HTTPS);
    }

    /**
     * Return the Title pointing at the *real* wiki file page for this
     * IIIF file — i.e. with the spoofed `.jpg` extension stripped off.
     *
     * MMV requires file titles to carry a recognised image extension,
     * which HookHandler::onThumbnailBeforeProduceHTML provides by appending
     * ".jpg" to extension-less DB keys. When MMV later round-trips
     * that spoofed title back through the imageinfo API, the IIIFFile
     * here carries the doctored title (e.g. "Bsb11610364.jpg"), but
     * the wiki page that actually catalogues the file's usage lives
     * at the un-spoofed dbkey ("Bsb11610364"). Linking back to the
     * spoofed title would 404 the "File usage" listing.
     *
     * IIIF object IDs in the wild are extension-less (BSB IDs, SLUB
     * shelfmarks, Fotothek `df_*` codes), so dropping `.jpg` always
     * resolves to the canonical wiki title for the kinds of files
     * this extension handles.
     */
    private function canonicalLocalTitle(): ?Title
    {
        $title = $this->getTitle();
        if ($title === null) {
            return null;
        }
        $dbKey = $title->getDBkey();
        $stripped = IIIFTitle::unspoof($dbKey);
        if ($stripped === $dbKey || $stripped === '') {
            return $title;
        }
        $clean = Title::makeTitleSafe($title->getNamespace(), $stripped);
        return $clean ?? $title;
    }

    /**
     * Return the provider's landing-page URL for use as the file's
     * "short" description URL.
     *
     * MMV uses `descriptionShortUrl` for the credit "Link" inside the
     * download-dialog attribution (plain + HTML) and the HTML embed's
     * trailing `<a>Link</a>`. Returning the provider URL
     * here means a re-user who copies that snippet links back to the
     * original work at the institution that holds it, not to a local
     * wiki page (which would be a circular reference for re-users
     * outside the wiki).
     *
     * Falls back to the local file-page URL when no provider URL is
     * available — the base File class returns null, which causes the
     * API to omit the field and MMV to crash in HtmlUtils.
     *
     * Called by ApiQueryImageInfo — not referenced directly in this
     * extension.
     *
     * @noinspection PhpUnused
     */
    // phpcs:ignore Syde.Classes.DisallowGetterSetter.GetterFound -- MediaWiki File override
    public function getDescriptionShortUrl(): string
    {
        $providerUrl = $this->getProviderUrl();
        return $providerUrl !== '' ? $providerUrl : $this->getDescriptionUrl();
    }

    /**
     * Human-readable landing page for the object at the IIIF provider.
     * v3: `homepage`, v2: `related`, fallback: provider metadata label mapping.
     *
     * Used for:
     * - Shared-upload description text (ImagePage)
     * - License URL fallback in extmetadata
     * - JS config var for fixing the shared-upload link on file pages
     */
    // phpcs:ignore Syde.Classes.DisallowGetterSetter.GetterFound -- public API for hooks
    public function getProviderUrl(): string
    {
        $resolved = $this->ensureResolved();
        if (!$resolved) {
            return '';
        }

        $manifest = $resolved['manifestRaw'];

        $homepageUrl = $this->extractHomepageUrl($manifest);
        if ($homepageUrl !== null) {
            return $homepageUrl;
        }

        $relatedUrl = $this->extractRelatedUrl($manifest);
        if ($relatedUrl !== null) {
            return $relatedUrl;
        }

        return $this->extractUrlFromMetadata($resolved);
    }

    /* -------------------- Description URL extraction -------------------- */

    /**
     * Extract homepage URL from manifest (v3: `homepage` field).
     *
     * @param array<string, mixed> $manifest
     */
    private function extractHomepageUrl(array $manifest): ?string
    {
        $homepage = $manifest['homepage'] ?? null;
        if ($homepage === null) {
            return null;
        }

        return $this->extractHttpUrl($homepage);
    }

    /**
     * Extract related URL from manifest (v2: `related` field).
     *
     * @param array<string, mixed> $manifest
     */
    private function extractRelatedUrl(array $manifest): ?string
    {
        $related = $manifest['related'] ?? null;
        if ($related === null) {
            return null;
        }

        return $this->extractHttpUrl($related);
    }

    /**
     * Extract an HTTP(S) URL from a value that may be a string, an object
     * with `@id`/`id`, or an array of such objects.
     */
    private function extractHttpUrl(mixed $value): ?string
    {
        // Plain URL string.
        if (is_string($value) && preg_match('~^https?://~', $value)) {
            return $value;
        }

        // Single object with @id or id.
        if (is_array($value)) {
            $id = $value['@id'] ?? $value['id'] ?? null;
            if (is_string($id) && preg_match('~^https?://~', $id)) {
                return $id;
            }

            // Array of objects — use the first one.
            $first = $value[0] ?? null;
            if (is_array($first)) {
                $id = $first['@id'] ?? $first['id'] ?? null;
                if (is_string($id) && preg_match('~^https?://~', $id)) {
                    return $id;
                }
            }
        }

        return null;
    }

    /**
     * Extract URL from manifest metadata using provider-specific label mapping.
     *
     * @param array<string, mixed> $resolved
     */
    private function extractUrlFromMetadata(array $resolved): string
    {
        $manifest = $resolved['manifestRaw'] ?? [];
        $provider = $resolved['provider'] ?? null;

        if (!$provider || !isset(self::LANDING_META_KEYS[$provider])) {
            return '';
        }

        return $this->findUrlInMetadata(
            $manifest['metadata'] ?? [],
            self::LANDING_META_KEYS[$provider]
        );
    }

    /**
     * Locate a label-matching metadata entry and return any HTTP(S) URL it
     * contains. Values may be plain URL strings, HTML fragments
     * (`<a href="…">…</a>`), language maps, or arrays thereof — providers
     * differ a lot here, especially SLUB which embeds links inside HTML.
     *
     * @param array<int, mixed> $metadata
     * @param list<string> $labels
     */
    private function findUrlInMetadata(array $metadata, array $labels): string
    {
        $needles = array_map('mb_strtolower', $labels);
        foreach ($metadata as $item) {
            if (!is_array($item)) {
                continue;
            }
            $label = mb_strtolower($this->stringFromMaybeLangMap($item['label'] ?? ''));
            if (!in_array($label, $needles, true)) {
                continue;
            }
            $url = $this->extractUrlFromValue($item['value'] ?? '');
            if ($url !== '') {
                return $url;
            }
        }
        return '';
    }

    /**
     * Pull the first HTTP(S) URL out of a metadata `value`. Accepts:
     *  - plain URL string ("https://…")
     *  - HTML containing <a href="…">
     *  - v2/v3 language object/map shapes (recursively resolved)
     *  - array of any of the above
     */
    private function extractUrlFromValue(mixed $value): string
    {
        if (is_string($value)) {
            if (preg_match('~^https?://~', $value)) {
                return $value;
            }
            if (
                preg_match('~href=["\']([^"\']+)["\']~i', $value, $match)
                && preg_match('~^https?://~', $match[1])
            ) {
                return $match[1];
            }
            return '';
        }

        if (!is_array($value)) {
            return '';
        }

        // Language map / object — resolve to string first, then re-parse.
        $resolved = $this->stringFromMaybeLangMap($value);
        if ($resolved !== '') {
            $url = $this->extractUrlFromValue($resolved);
            if ($url !== '') {
                return $url;
            }
        }

        // Array of values — try each.
        foreach ($value as $entry) {
            $url = $this->extractUrlFromValue($entry);
            if ($url !== '') {
                return $url;
            }
        }

        return '';
    }

    /**
     * Find a license URL inside the manifest's `metadata` field for
     * providers that don't expose a top-level `license`/`rights` (e.g.
     * SLUB embeds the license as an HTML link in `Rechteinformationen`).
     *
     * @param array<string, mixed> $resolved
     */
    // phpcs:ignore Syde.Classes.DisallowGetterSetter.GetterFound -- public API for hooks
    public function getLicenseUrlFromMetadata(array $resolved): string
    {
        $provider = $resolved['provider'] ?? null;
        if (!$provider || !isset(self::LICENSE_META_KEYS[$provider])) {
            return '';
        }
        $manifest = $resolved['manifestRaw'] ?? [];
        return $this->findUrlInMetadata(
            $manifest['metadata'] ?? [],
            self::LICENSE_META_KEYS[$provider]
        );
    }

    /* -------------------- Transform (thumbnails) -------------------- */

    /**
     * @param array<string, mixed> $params
     * @param int $flags
     * @return ThumbnailImage|MediaTransformError
     */
    // phpcs:ignore Syde.Functions.ArgumentTypeDeclaration.NoArgumentType, Syde.Functions.ReturnTypeDeclaration.NoReturnType -- MediaWiki File override
    public function transform($params, $flags = 0): MediaTransformError|ThumbnailImage
    {
        $page = $this->normalizePage($params['page'] ?? 1);
        $this->lastTransformPage = $page;
        $svc = $this->getServiceIdForPage($page);
        if (!$svc) {
            return new MediaTransformError(
                'iiif-unresolved',
                (int) ($params['width'] ?? 0),
                (int) ($params['height'] ?? 0)
            );
        }

        $width = max(0, (int) ($params['width'] ?? $params['w'] ?? 0));
        $height = max(0, (int) ($params['height'] ?? $params['h'] ?? 0));

        $originalDims = $this->getOriginalDimensions($page, $svc);

        return $this->createThumbnail($svc, $width, $height, $originalDims, $page);
    }

    /**
     * Get original dimensions from canvas or info.json.
     *
     * @return array{0: int, 1: int}
     */
    private function getOriginalDimensions(int $page, string $svc): array
    {
        $dims = $this->getCanvasDimensions($page);
        $origWidth = $dims[0] ?? 0;
        $origHeight = $dims[1] ?? 0;

        if (!$origWidth || !$origHeight) {
            $info = $this->ensureInfoJsonFor($svc);
            $origWidth = $origWidth ?: ($info['width'] ?? 0);
            $origHeight = $origHeight ?: ($info['height'] ?? 0);
        }

        return [(int) $origWidth, (int) $origHeight];
    }

    /**
     * Create a thumbnail based on requested dimensions.
     *
     * @param array{0: int, 1: int} $originalDims
     */
    private function createThumbnail(
        string $svc,
        int $width,
        int $height,
        array $originalDims,
        int $page = 1
    ): ThumbnailImage {

        [$origWidth, $origHeight] = $originalDims;

        if (!$width && !$height) {
            $url = $this->buildImageUrl($svc, 'full');
            return new ThumbnailImage($this, $url, false, [
                'width' => $origWidth,
                'height' => $origHeight,
                'page' => $page,
            ]);
        }

        if ($width && !$height) {
            return $this->createWidthOnlyThumbnail($svc, $width, $origWidth, $origHeight, $page);
        }

        if (!$width) {
            return $this->createHeightOnlyThumbnail($svc, $height, $origWidth, $origHeight, $page);
        }

        return $this->createBothDimensionsThumbnail($svc, $width, $height, $page);
    }

    private function createWidthOnlyThumbnail(
        string $svc,
        int $width,
        int $origWidth,
        int $origHeight,
        int $page = 1
    ): ThumbnailImage {

        [$clampedWidth, $clampedHeight] = $this->clampSizeToService($svc, $width, 0);
        $clampedHeight = $clampedHeight
            ?: ($origWidth ? (int) round($origHeight * ($clampedWidth / $origWidth)) : 0);
        $url = $this->buildImageUrl($svc, ['w' => $clampedWidth]);
        return new ThumbnailImage($this, $url, false, [
            'width' => $clampedWidth,
            'height' => $clampedHeight,
            'page' => $page,
        ]);
    }

    private function createHeightOnlyThumbnail(
        string $svc,
        int $height,
        int $origWidth,
        int $origHeight,
        int $page = 1
    ): ThumbnailImage {

        [$clampedWidth, $clampedHeight] = $this->clampSizeToService($svc, 0, $height);
        $clampedWidth = $clampedWidth
            ?: ($origHeight ? (int) round($origWidth * ($clampedHeight / $origHeight)) : 0);
        $url = $this->buildImageUrl($svc, ['h' => $clampedHeight]);
        return new ThumbnailImage($this, $url, false, [
            'width' => $clampedWidth,
            'height' => $clampedHeight,
            'page' => $page,
        ]);
    }

    private function createBothDimensionsThumbnail(
        string $svc,
        int $width,
        int $height,
        int $page = 1
    ): ThumbnailImage {

        [$clampedWidth, $clampedHeight] = $this->clampSizeToService($svc, $width, $height);
        $url = $this->buildImageUrl($svc, ['w' => $clampedWidth, 'h' => $clampedHeight]);
        return new ThumbnailImage($this, $url, false, [
            'width' => $clampedWidth,
            'height' => $clampedHeight,
            'page' => $page,
        ]);
    }

    /* -------------------- Resolution helpers -------------------- */

    protected function normalizePage(mixed $page): int
    {
        $pageNum = (int) $page;
        return max($pageNum, 1);
    }

    /** @return array<string, mixed>|null */
    // phpcs:ignore Syde.Classes.DisallowGetterSetter.GetterFound -- MediaWiki File override
    public function getResolvedManifest(): ?array
    {
        return $this->ensureResolved();
    }

    /**
     * Page number from the most recent transform() call.
     * Used by HookHandler::onThumbnailBeforeProduceHTML to set data-iiif-page.
     */
    // phpcs:ignore Syde.Classes.DisallowGetterSetter.GetterFound -- needed by hook
    public function lastTransformPage(): int
    {
        return $this->lastTransformPage;
    }

    /**
     * @return array{
     *     provider: string,
     *     objectId: string,
     *     manifestUrl: string,
     *     manifestRaw: array<string, mixed>
     * }|null
     */
    // phpcs:ignore SlevomatCodingStandard.Complexity.Cognitive.ComplexityTooHigh -- Pending full refactor
    protected function ensureResolved(): ?array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $title = $this->getTitle();
        if ($title === null) {
            $this->resolved = null;
            return null;
        }
        $objId = lcfirst($title->getDBkey());
        // Strip the spoofed image extension we appended for MMV.
        $objId = IIIFTitle::unspoof($objId);

        if (!$objId) {
            $this->resolved = null;
            return null;
        }

        $sources = $this->getProviderConfig();
        foreach ($sources as $src) {
            if (isset($src['idPattern']) && !preg_match($src['idPattern'], $objId)) {
                continue;
            }
            $manifestUrl = str_replace('$1', $objId, $src['manifestPattern'] ?? '');
            if (!$manifestUrl) {
                continue;
            }

            $json = $this->fetchTextCached($manifestUrl);
            if (!$json) {
                continue;
            }

            $manifestRaw = json_decode($json, true) ?: [];

            if ($this->isErrorManifest($manifestRaw)) {
                continue;
            }

            $this->resolved = [
                'provider' => (string) ($src['id'] ?? 'default'),
                'objectId' => $objId,
                'manifestUrl' => $manifestUrl,
                'manifestRaw' => $manifestRaw,
            ];
            return $this->resolved;
        }

        $this->resolved = null;
        return null;
    }

    /** @param array<string, mixed> $manifest */
    private function isErrorManifest(array $manifest): bool
    {
        $label = $manifest['label'] ?? '';
        if (is_string($label) && str_starts_with($label, 'error:')) {
            return true;
        }

        $canvasId = $manifest['sequences'][0]['canvases'][0]['@id'] ?? '';
        if (is_string($canvasId) && str_starts_with($canvasId, 'error/')) {
            return true;
        }

        return false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    // phpcs:ignore Syde.Classes.DisallowGetterSetter.GetterFound -- internal accessor
    protected function getProviderConfig(): array
    {
        if (!$this->repo instanceof Repo) {
            return [];
        }
        return $this->repo->iiifSources();
    }

    // phpcs:ignore Syde.Classes.DisallowGetterSetter.GetterFound -- public for SpecialInstantIIIFInspect
    public function getServiceIdForPage(int $page): ?string
    {
        $resolved = $this->ensureResolved();
        if (!$resolved) {
            return null;
        }
        return $this->extractServiceId($resolved['manifestRaw'], $page);
    }

    /**
     * @return array{0: int, 1: int}
     */
    // phpcs:ignore Syde.Classes.DisallowGetterSetter.GetterFound -- public for SpecialInstantIIIFInspect
    public function getCanvasDimensions(int $page): array
    {
        $resolved = $this->ensureResolved();
        if (!$resolved) {
            return [0, 0];
        }
        return $this->extractCanvasDimensions($resolved['manifestRaw'], $page);
    }

    /**
     * Build an Image API v2 URL for a given service base and desired size.
     *
     * @param string|array{w?: int, h?: int} $size
     */
    protected function buildImageUrl(string $serviceId, string|array $size): string
    {
        $base = rtrim($serviceId, '/');
        if ($size === 'full') {
            return $base . '/full/full/0/default.jpg';
        }

        $width = isset($size['w']) ? max(0, (int) $size['w']) : 0;
        $height = isset($size['h']) ? max(0, (int) $size['h']) : 0;

        // Never request more than the service can provide.
        [$width, $height] = $this->clampSizeToService($serviceId, $width, $height);

        $sizeParam = $this->buildSizeParameter($width, $height);
        return $base . '/full/' . $sizeParam . '/0/default.jpg';
    }

    private function buildSizeParameter(int $width, int $height): string
    {
        if ($width && $height) {
            return $width . ',' . $height;
        }
        if ($width) {
            return $width . ',';
        }
        if ($height) {
            return ',' . $height;
        }
        return 'full';
    }

    /**
     * Clamp requested w/h to the limits supported by the image service.
     *
     * @return array{0: int, 1: int}
     */
    protected function clampSizeToService(string $serviceId, int $width, int $height): array
    {
        if (!$width && !$height) {
            return [$width, $height];
        }

        $info = $this->ensureInfoJsonFor($serviceId);
        $limits = $this->extractServiceLimits($info);

        [$width, $height] = $this->applyMaxDimensionLimits(
            $width,
            $height,
            $limits['maxW'],
            $limits['maxH']
        );

        return $this->applyMaxAreaLimit(
            $width,
            $height,
            $limits['maxArea'],
            $limits['origW'],
            $limits['origH']
        );
    }

    /**
     * @param array<string, mixed> $info
     * @return array{origW: int, origH: int, maxW: int, maxH: int, maxArea: int}
     */
    private function extractServiceLimits(array $info): array
    {
        $origWidth = (int) ($info['width'] ?? 0);
        $origHeight = (int) ($info['height'] ?? 0);
        return [
            'origW' => $origWidth,
            'origH' => $origHeight,
            'maxW' => (int) ($info['maxWidth'] ?? $origWidth),
            'maxH' => (int) ($info['maxHeight'] ?? $origHeight),
            'maxArea' => (int) ($info['maxArea'] ?? 0),
        ];
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function applyMaxDimensionLimits(
        int $width,
        int $height,
        int $maxW,
        int $maxH
    ): array {

        if ($width && $height) {
            return $this->scaleBothDimensions($width, $height, $maxW, $maxH);
        }

        if ($width && $maxW && $width > $maxW) {
            $width = $maxW;
        }
        if ($height && $maxH && $height > $maxH) {
            $height = $maxH;
        }
        return [$width, $height];
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function scaleBothDimensions(int $width, int $height, int $maxW, int $maxH): array
    {
        $scale = 1.0;
        if ($maxW && $width > $maxW) {
            $scale = min($scale, $maxW / $width);
        }
        if ($maxH && $height > $maxH) {
            $scale = min($scale, $maxH / $height);
        }
        if ($scale < 1.0) {
            $width = max(1, (int) floor($width * $scale));
            $height = max(1, (int) floor($height * $scale));
        }
        return [$width, $height];
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function applyMaxAreaLimit(
        int $width,
        int $height,
        int $maxArea,
        int $origW,
        int $origH
    ): array {

        if (!$maxArea || !$origW || !$origH) {
            return [$width, $height];
        }

        if ($width && $height) {
            return $this->scaleForArea($width, $height, $maxArea);
        }

        if ($width) {
            $estimatedHeight = (int) round($width * $origH / $origW);
            if ($width * $estimatedHeight > $maxArea) {
                $width = max(1, (int) floor(sqrt($maxArea * $origW / $origH)));
            }
        } elseif ($height) {
            $estimatedWidth = (int) round($height * $origW / $origH);
            if ($height * $estimatedWidth > $maxArea) {
                $height = max(1, (int) floor(sqrt($maxArea * $origH / $origW)));
            }
        }

        return [$width, $height];
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function scaleForArea(int $width, int $height, int $maxArea): array
    {
        $area = $width * $height;
        if ($area > $maxArea) {
            $scale = sqrt($maxArea / $area);
            $width = max(1, (int) floor($width * $scale));
            $height = max(1, (int) floor($height * $scale));
        }
        return [$width, $height];
    }

    /**
     * HTTP fetch and cache of text resources (manifest, info.json).
     */
    protected function fetchTextCached(string $url): ?string
    {
        $services = MediaWikiServices::getInstance();
        $cache = $services->getMainWANObjectCache();
        $key = $cache->makeKey('InstantIIIF', 'text', md5($url));

        return $cache->getWithSetCallback(
            $key,
            3600,
            static function () use ($services, $url): ?string {
                $httpFactory = $services->getHttpRequestFactory();
                $timeout = $services->getMainConfig()->get('InstantIIIFDefaultTimeout');
                $req = $httpFactory->create($url, ['timeout' => $timeout]);
                $status = $req->execute();
                if (!$status->isOK()) {
                    return null;
                }
                return $req->getContent();
            },
            ['pcTTL' => 3600]
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function ensureInfoJsonFor(string $serviceId): array
    {
        if (isset($this->infoJsonMap[$serviceId])) {
            return $this->infoJsonMap[$serviceId];
        }
        $url = rtrim($serviceId, '/') . '/info.json';
        $json = $this->fetchTextCached($url);
        $arr = $json ? (json_decode($json, true) ?: []) : [];
        $this->infoJsonMap[$serviceId] = $arr;
        return $arr;
    }

    /* -------------------- Raw JSON manifest extraction -------------------- */

    /**
     * Get the list of canvases from the manifest.
     * v2: sequences[0].canvases, v3: items.
     *
     * @param array<string, mixed> $manifest
     * @return array<int, array<string, mixed>>
     */
    private function getCanvases(array $manifest): array
    {
        // v3
        if (isset($manifest['items']) && is_array($manifest['items'])) {
            return $manifest['items'];
        }

        // v2
        $canvases = $manifest['sequences'][0]['canvases'] ?? null;
        if (is_array($canvases)) {
            return $canvases;
        }

        return [];
    }

    /**
     * Extract the IIIF Image API service @id for a given page.
     *
     * @param array<string, mixed> $manifest
     */
    private function extractServiceId(array $manifest, int $page): ?string
    {
        $canvases = $this->getCanvases($manifest);
        $idx = max(0, $page - 1);
        if (!isset($canvases[$idx]) || !is_array($canvases[$idx])) {
            return null;
        }
        $canvas = $canvases[$idx];

        // v3: items[0].items[0].body.service[0].@id (or .id)
        $serviceId = $this->extractServiceFromV3Canvas($canvas);
        if ($serviceId !== null) {
            return $serviceId;
        }

        // v2: images[0].resource.service.@id
        return $this->extractServiceFromV2Canvas($canvas);
    }

    /**
     * v3 canvas: items (AnnotationPage) → items (Annotation) → body → service.
     *
     * @param array<string, mixed> $canvas
     */
    private function extractServiceFromV3Canvas(array $canvas): ?string
    {
        $body = $canvas['items'][0]['items'][0]['body'] ?? null;
        if (!is_array($body)) {
            return null;
        }

        $service = $body['service'] ?? null;
        return $this->extractServiceIdFromField($service);
    }

    /**
     * v2 canvas: images[0].resource.service.
     *
     * @param array<string, mixed> $canvas
     */
    private function extractServiceFromV2Canvas(array $canvas): ?string
    {
        $resource = $canvas['images'][0]['resource'] ?? null;
        if (!is_array($resource)) {
            return null;
        }

        $service = $resource['service'] ?? null;
        $id = $this->extractServiceIdFromField($service);
        if ($id !== null) {
            return $id;
        }

        // Fallback: try to derive service base from the resource @id URL.
        $resourceId = $resource['@id'] ?? null;
        if (is_string($resourceId)) {
            $pattern = '#^(.*?/iiif/2/[^/]+)#';
            if (preg_match($pattern, $resourceId, $match)) {
                return $match[1];
            }
        }

        return null;
    }

    /**
     * Extract the service base URL from a `service` field.
     * Handles both a single service object and an array of services.
     */
    private function extractServiceIdFromField(mixed $service): ?string
    {
        if (!is_array($service)) {
            return null;
        }

        // Single service object with @id or id key.
        $id = $service['@id'] ?? $service['id'] ?? null;
        if (is_string($id)) {
            return rtrim($id, '/');
        }

        // Array of service objects — use the first entry.
        $first = $service[0] ?? null;
        if (is_array($first)) {
            $id = $first['@id'] ?? $first['id'] ?? null;
            if (is_string($id)) {
                return rtrim($id, '/');
            }
        }

        return null;
    }

    /**
     * Extract canvas dimensions for a given page.
     *
     * @param array<string, mixed> $manifest
     * @return array{0: int, 1: int}
     */
    private function extractCanvasDimensions(array $manifest, int $page): array
    {
        $canvases = $this->getCanvases($manifest);
        $idx = max(0, $page - 1);
        if (!isset($canvases[$idx]) || !is_array($canvases[$idx])) {
            return [0, 0];
        }
        $canvas = $canvases[$idx];

        $width = (int) ($canvas['width'] ?? 0);
        $height = (int) ($canvas['height'] ?? 0);
        return [$width, $height];
    }

    /**
     * Preferred languages for resolving IIIF language maps / arrays.
     *
     * Picks (in order): the wiki's content language, then English as a
     * sensible fallback (IIIF manifests in the wild almost always carry
     * an English translation).
     *
     * @return list<string>
     */
    protected function preferredLanguages(): array
    {
        $langs = [];
        try {
            $contentLang = MediaWikiServices::getInstance()
                ->getContentLanguage()
                ->getCode();
            if ($contentLang !== '') {
                $langs[] = $contentLang;
            }
        } catch (\Throwable $unused) {
            // Services unavailable (extreme bootstrap failure) — fall
            // through to English.
            unset($unused);
        }
        if (!in_array('en', $langs, true)) {
            $langs[] = 'en';
        }
        return $langs;
    }

    /**
     * Resolve a IIIF value that may be a plain string, a v2 language object
     * (`{ "@value": "...", "@language": "..." }`), a v3 language map
     * (`{ "en": ["..."], "de": ["..."] }`), or an array of language objects.
     *
     * Falls back through the wiki's content language to English when
     * multiple translations are available.
     */
    private function stringFromMaybeLangMap(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (!is_array($value)) {
            return '';
        }
        // v2 single language object.
        if (isset($value['@value']) && is_string($value['@value'])) {
            return $value['@value'];
        }
        $preferred = $this->preferredLanguages();
        // v2 list of language objects.
        if (isset($value[0]) && is_array($value[0]) && isset($value[0]['@value'])) {
            return $this->pickFromLanguageObjectList($value, $preferred);
        }
        // v3 language map.
        return $this->pickFromLanguageMap($value, $preferred);
    }

    /**
     * v2 list of language objects: `[{"@language":"de","@value":"…"}, …]`.
     *
     * @param array<int, mixed> $entries
     * @param list<string> $preferred
     */
    private function pickFromLanguageObjectList(array $entries, array $preferred): string
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
     * v3 language map: `{"de": ["…"], "en": ["…"]}`.
     *
     * @param array<string, mixed> $value
     * @param list<string> $preferred
     */
    private function pickFromLanguageMap(array $value, array $preferred): string
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
}
