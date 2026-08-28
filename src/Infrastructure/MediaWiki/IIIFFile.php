<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF\Infrastructure\MediaWiki;

use File;
use MediaHandler;
use MediaTransformError;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\InstantIIIF\Domain\Dimensions;
use MediaWiki\Extension\InstantIIIF\Domain\ImageService;
use MediaWiki\Extension\InstantIIIF\Domain\Manifest;
use MediaWiki\Extension\InstantIIIF\Domain\ManifestFetcher;
use MediaWiki\Extension\InstantIIIF\Domain\Page;
use MediaWiki\Extension\InstantIIIF\Domain\ProviderQuirks;
use MediaWiki\Extension\InstantIIIF\Infrastructure\CachedHttpManifestFetcher;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use ThumbnailImage;

/**
 * Virtual File that hotlinks images from a remote IIIF Image Service using
 * the IIIF Presentation API (v2/v3) manifest.
 *
 * Thin MediaWiki adapter: this class implements the File interface MW core
 * expects (transform, getWidth, getUrl, …) and delegates the actual IIIF
 * parsing to the Domain layer (Manifest, ImageService, Page).
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
        return $this->manifest() !== null;
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

    public function pageCount(): int
    {
        return $this->manifest()?->pageCount() ?? 0;
    }

    /**
     * @param int $page
     */
    // phpcs:ignore Syde.Classes.DisallowGetterSetter.GetterFound, Syde.Functions.ArgumentTypeDeclaration.NoArgumentType -- MediaWiki File override
    public function getWidth($page = 1): int
    {
        return $this->dimensionsForPage($page)->width;
    }

    /**
     * @param int $page
     */
    // phpcs:ignore Syde.Classes.DisallowGetterSetter.GetterFound, Syde.Functions.ArgumentTypeDeclaration.NoArgumentType -- MediaWiki File override
    public function getHeight($page = 1): int
    {
        return $this->dimensionsForPage($page)->height;
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
        $serviceId = $this->serviceIdForPage($this->resolveCurrentPage());
        if ($serviceId === null) {
            return '';
        }
        return $this->cachedImageUrl(ImageService::fullUrlFor($serviceId));
    }

    /**
     * Resolve the page number to use for non-thumb URL methods.
     */
    private function resolveCurrentPage(): Page
    {
        $request = RequestContext::getMain()->getRequest();
        $requestPage = (int) $request->getVal('page', '0');
        if ($requestPage > 0) {
            return Page::normalize($requestPage);
        }
        return Page::normalize($this->lastTransformPage);
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
        $serviceId = $this->serviceIdForPage(Page::normalize($page));
        if ($serviceId === null) {
            return '';
        }
        return $this->cachedImageUrl(ImageService::fullUrlFor($serviceId));
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
        $query = $page->value > 1 ? 'page=' . $page->value : '';
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
        $manifest = $this->manifest();
        if ($manifest === null) {
            return '';
        }

        $landing = $manifest->landingUrl();
        if ($landing !== null) {
            return $landing;
        }

        $provider = $this->resolved['provider'] ?? '';
        $labels = ProviderQuirks::landingLabelsFor($provider);
        if ($labels === []) {
            return '';
        }
        return $manifest->findUrlInMetadataByLabels($labels, $this->preferredLanguages());
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
        $provider = $resolved['provider'] ?? '';
        $labels = ProviderQuirks::licenseLabelsFor($provider);
        if ($labels === []) {
            return '';
        }
        $raw = $resolved['manifestRaw'] ?? [];
        if (!is_array($raw)) {
            return '';
        }
        return Manifest::from($raw)->findUrlInMetadataByLabels(
            $labels,
            $this->preferredLanguages()
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
        $page = Page::normalize($params['page'] ?? 1);
        $this->lastTransformPage = $page->value;

        $serviceId = $this->serviceIdForPage($page);
        if ($serviceId === null) {
            return new MediaTransformError(
                'iiif-unresolved',
                (int) ($params['width'] ?? 0),
                (int) ($params['height'] ?? 0)
            );
        }

        $width = max(0, (int) ($params['width'] ?? $params['w'] ?? 0));
        $height = max(0, (int) ($params['height'] ?? $params['h'] ?? 0));

        $service = ImageService::fromInfoJson($serviceId, $this->ensureInfoJsonFor($serviceId));
        $originalDims = $this->originalDimensionsFor($page, $service);

        return $this->buildThumbnail($service, $width, $height, $originalDims, $page);
    }

    private function originalDimensionsFor(Page $page, ImageService $service): Dimensions
    {
        $canvasDims = $this->manifest()?->canvasDimensionsFor($page) ?? Dimensions::unknown();
        if ($canvasDims->isKnown()) {
            return $canvasDims;
        }
        return Dimensions::of(
            $canvasDims->width ?: $service->originalDims->width,
            $canvasDims->height ?: $service->originalDims->height
        );
    }

    private function buildThumbnail(
        ImageService $service,
        int $width,
        int $height,
        Dimensions $original,
        Page $page
    ): ThumbnailImage {

        if (!$width && !$height) {
            return new ThumbnailImage($this, $this->cachedImageUrl($service->fullUrl()), false, [
                'width' => $original->width,
                'height' => $original->height,
                'page' => $page->value,
            ]);
        }

        [$clampedW, $clampedH] = $service->clamp($width, $height);

        // Fill in the missing axis using the original aspect ratio so the
        // ThumbnailImage carries correct rendered dimensions for MW's
        // <img width/height> attributes.
        if ($width && !$height && $original->width) {
            $clampedH = $clampedH ?: (int) round($original->height * ($clampedW / $original->width));
        } elseif ($height && !$width && $original->height) {
            $clampedW = $clampedW ?: (int) round($original->width * ($clampedH / $original->height));
        }

        $url = $this->cachedImageUrl($service->sizedUrl($width, $height));
        return new ThumbnailImage($this, $url, false, [
            'width' => $clampedW,
            'height' => $clampedH,
            'page' => $page->value,
        ]);
    }

    /* -------------------- Local image cache -------------------- */

    /**
     * Route a remote IIIF Image API URL through the local cache, returning a
     * local wiki URL when the bytes are cached (or could be fetched + stored)
     * and falling back to the remote URL otherwise. Applied to both thumbnail
     * transforms and the full-resolution URL methods so that — once warmed —
     * neither the inline thumbnails nor MMV's full-size image nor the
     * imageinfo `url` field send traffic to the provider.
     */
    private function cachedImageUrl(string $remoteUrl): string
    {
        return $this->imageCache()?->localUrlFor($remoteUrl) ?? $remoteUrl;
    }

    /**
     * Build the image cache for this file's repo, or null when the repo is
     * not an InstantIIIF Repo or has caching disabled. Protected so tests can
     * override it without a real FileBackend (mirrors manifestFetcher()).
     */
    protected function imageCache(): ?ImageCache
    {
        $repo = $this->repo;
        if (!$repo instanceof Repo || !$repo->cacheImagesEnabled()) {
            return null;
        }
        $services = MediaWikiServices::getInstance();
        return new IIIFImageCache(
            $repo,
            $services->getHttpRequestFactory(),
            $repo->imageCacheExpiry(),
            (int) $services->getMainConfig()->get('InstantIIIFDefaultTimeout')
        );
    }

    /* -------------------- Resolution helpers -------------------- */

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

    // phpcs:ignore Syde.Classes.DisallowGetterSetter.GetterFound -- public for SpecialInstantIIIFInspect
    public function getServiceIdForPage(int $page): ?string
    {
        return $this->serviceIdForPage(Page::normalize($page));
    }

    /**
     * @return array{0: int, 1: int}
     */
    // phpcs:ignore Syde.Classes.DisallowGetterSetter.GetterFound -- public for SpecialInstantIIIFInspect
    public function getCanvasDimensions(int $page): array
    {
        $dims = $this->manifest()?->canvasDimensionsFor(Page::normalize($page)) ?? Dimensions::unknown();
        return [$dims->width, $dims->height];
    }

    /**
     * Width/height for a given page, falling back through canvas dims →
     * info.json. Returns (0, 0) when neither is available.
     *
     * Accepts `mixed` because MediaWiki's File::getWidth($page = 1) /
     * File::getHeight($page = 1) signatures are untyped — MW core calls
     * them with `false` to mean "no page specified" — so anything callers
     * forward to here must go through Page::normalize for coercion.
     */
    private function dimensionsForPage(mixed $page): Dimensions
    {
        $manifest = $this->manifest();
        if ($manifest === null) {
            return Dimensions::unknown();
        }
        $pageVo = Page::normalize($page);
        $dims = $manifest->canvasDimensionsFor($pageVo);
        if ($dims->isKnown()) {
            return $dims;
        }
        $serviceId = $manifest->imageServiceIdFor($pageVo);
        if ($serviceId === null) {
            return $dims;
        }
        $info = $this->ensureInfoJsonFor($serviceId);
        return Dimensions::of(
            $dims->width ?: (int) ($info['width'] ?? 0),
            $dims->height ?: (int) ($info['height'] ?? 0)
        );
    }

    private function serviceIdForPage(Page $page): ?string
    {
        return $this->manifest()?->imageServiceIdFor($page);
    }

    private function manifest(): ?Manifest
    {
        $resolved = $this->ensureResolved();
        if ($resolved === null) {
            return null;
        }
        $raw = $resolved['manifestRaw'] ?? null;
        return is_array($raw) ? Manifest::from($raw) : null;
    }

    /**
     * @return array{
     *     provider: string,
     *     objectId: string,
     *     manifestUrl: string,
     *     manifestRaw: array<string, mixed>
     * }|null
     */
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

        foreach ($this->getProviderConfig() as $src) {
            $resolved = $this->tryProvider($src, $objId);
            if ($resolved !== null) {
                $this->resolved = $resolved;
                return $this->resolved;
            }
        }

        $this->resolved = null;
        return null;
    }

    /**
     * @param array<string, mixed> $src
     * @return array{
     *     provider: string,
     *     objectId: string,
     *     manifestUrl: string,
     *     manifestRaw: array<string, mixed>
     * }|null
     */
    private function tryProvider(array $src, string $objId): ?array
    {
        // A source must declare an idPattern (enforced at Repo construction)
        // and the id must match it. Bail before any HTTP fetch otherwise, so
        // a provider is only queried for ids that belong to it.
        $pattern = $src['idPattern'] ?? null;
        if (!is_string($pattern) || $pattern === '' || !preg_match($pattern, $objId)) {
            return null;
        }
        $manifestUrl = str_replace('$1', $objId, $src['manifestPattern'] ?? '');
        if (!$manifestUrl) {
            return null;
        }

        $raw = $this->fetchJsonCached($manifestUrl);
        if ($raw === null) {
            return null;
        }
        if (Manifest::from($raw)->isError()) {
            return null;
        }

        return [
            'provider' => (string) ($src['id'] ?? 'default'),
            'objectId' => $objId,
            'manifestUrl' => $manifestUrl,
            'manifestRaw' => $raw,
        ];
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

    /**
     * Fetch and decode a IIIF JSON document, going through the MW
     * WAN cache. Protected so tests can override (no HTTP in unit tests).
     *
     * @return array<string, mixed>|null
     */
    protected function fetchJsonCached(string $url): ?array
    {
        return $this->manifestFetcher()->fetch($url);
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
        $info = $this->fetchJsonCached($url) ?? [];
        $this->infoJsonMap[$serviceId] = $info;
        return $info;
    }

    private function manifestFetcher(): ManifestFetcher
    {
        $services = MediaWikiServices::getInstance();
        return new CachedHttpManifestFetcher(
            $services->getMainWANObjectCache(),
            $services->getHttpRequestFactory(),
            $services->getMainConfig(),
            $this->manifestCacheTtl()
        );
    }

    /**
     * TTL for the manifest/info.json WAN cache. Reuses the repo's image-cache
     * expiry so a single `imageCacheExpiry` setting governs all IIIF caching;
     * falls back to the fetcher default when caching is off or the repo is
     * not an InstantIIIF Repo.
     */
    private function manifestCacheTtl(): int
    {
        $repo = $this->repo;
        if ($repo instanceof Repo && $repo->imageCacheExpiry() > 0) {
            return $repo->imageCacheExpiry();
        }
        return CachedHttpManifestFetcher::DEFAULT_TTL_SECONDS;
    }

    /**
     * Preferred languages for resolving IIIF language maps / arrays in
     * metadata label/value extraction (provider-URL & license-URL
     * lookups). Picks the wiki content language, then English.
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
}
