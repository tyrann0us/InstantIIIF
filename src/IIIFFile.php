<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF;

use File;
use MediaHandler;
use MediaTransformError;
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
     * Read by Hooks::onThumbnailBeforeProduceHTML to set data-iiif-page.
     */
    protected int $lastTransformPage = 1;

    /** Provider-specific label mapping to find landing/homepage URL in `metadata` */
    /** @var array<string, list<string>> */
    private const LANDING_META_KEYS = [
        'deutsche-fotothek' => ['Link zum Werk'],
    ];

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
        $svc = $this->getServiceIdForPage(1);
        return $svc ? $this->buildImageUrl($svc, 'full') : '';
    }

    // phpcs:ignore Syde.Classes.DisallowGetterSetter.GetterFound -- MediaWiki File override
    public function getUrl(): string
    {
        return $this->getFullUrl();
    }

    /**
     * Human-readable landing page for the object at the IIIF provider.
     * v3: `homepage`, v2: `related`, fallback: provider metadata label mapping.
     *
     * Exposed by the API as `descriptionurl`; MMV uses it for the share
     * link, embed credit link, and "More details" button.
     */
    // phpcs:ignore Syde.Classes.DisallowGetterSetter.GetterFound -- MediaWiki File override
    public function getDescriptionUrl(): string
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

    /**
     * Return the same URL as getDescriptionUrl().
     *
     * The base File class returns null, which causes the API to omit the
     * `descriptionshorturl` field.  MMV then passes `undefined` into
     * HtmlUtils.wrapAndJquerify(), crashing with "unknown type undefined".
     *
     * Called by ApiQueryImageInfo — not referenced directly in this extension.
     *
     * @noinspection PhpUnused
     * @return string
     */
    // phpcs:ignore Syde.Classes.DisallowGetterSetter.GetterFound -- MediaWiki File override
    public function getDescriptionShortUrl(): string
    {
        return $this->getDescriptionUrl();
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

        $labels = array_map('mb_strtolower', self::LANDING_META_KEYS[$provider]);
        foreach (($manifest['metadata'] ?? []) as $metadataItem) {
            $label = $this->stringFromMaybeLangMap($metadataItem['label'] ?? '');
            $value = $metadataItem['value'] ?? '';
            if (
                in_array(mb_strtolower($label), $labels, true)
                && is_string($value)
                && preg_match('~^https?://~', $value)
            ) {
                return $value;
            }
        }

        return '';
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

        return $this->createThumbnail($svc, $width, $height, $originalDims);
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
        array $originalDims
    ): ThumbnailImage {

        [$origWidth, $origHeight] = $originalDims;

        if (!$width && !$height) {
            $url = $this->buildImageUrl($svc, 'full');
            return new ThumbnailImage($this, $url, false, [
                'width' => $origWidth,
                'height' => $origHeight,
            ]);
        }

        if ($width && !$height) {
            return $this->createWidthOnlyThumbnail($svc, $width, $origWidth, $origHeight);
        }

        if ($height && !$width) {
            return $this->createHeightOnlyThumbnail($svc, $height, $origWidth, $origHeight);
        }

        return $this->createBothDimensionsThumbnail($svc, $width, $height);
    }

    private function createWidthOnlyThumbnail(
        string $svc,
        int $width,
        int $origWidth,
        int $origHeight
    ): ThumbnailImage {

        [$clampedWidth, $clampedHeight] = $this->clampSizeToService($svc, $width, 0);
        $clampedHeight = $clampedHeight
            ?: ($origWidth ? (int) round($origHeight * ($clampedWidth / $origWidth)) : 0);
        $url = $this->buildImageUrl($svc, ['w' => $clampedWidth]);
        return new ThumbnailImage($this, $url, false, [
            'width' => $clampedWidth,
            'height' => $clampedHeight,
        ]);
    }

    private function createHeightOnlyThumbnail(
        string $svc,
        int $height,
        int $origWidth,
        int $origHeight
    ): ThumbnailImage {

        [$clampedWidth, $clampedHeight] = $this->clampSizeToService($svc, 0, $height);
        $clampedWidth = $clampedWidth
            ?: ($origHeight ? (int) round($origWidth * ($clampedHeight / $origHeight)) : 0);
        $url = $this->buildImageUrl($svc, ['h' => $clampedHeight]);
        return new ThumbnailImage($this, $url, false, [
            'width' => $clampedWidth,
            'height' => $clampedHeight,
        ]);
    }

    private function createBothDimensionsThumbnail(
        string $svc,
        int $width,
        int $height
    ): ThumbnailImage {

        [$clampedWidth, $clampedHeight] = $this->clampSizeToService($svc, $width, $height);
        $url = $this->buildImageUrl($svc, ['w' => $clampedWidth, 'h' => $clampedHeight]);
        return new ThumbnailImage($this, $url, false, [
            'width' => $clampedWidth,
            'height' => $clampedHeight,
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
     * Used by Hooks::onThumbnailBeforeProduceHTML to set data-iiif-page.
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
        // Remove the spoofed image extension we had to add because of MMV.
        $objId = $this->removeImageExtension($objId);

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

    protected function removeImageExtension(string $filename): string
    {
        $pattern = '/\.(jpg|jpeg|png|gif|bmp|webp)$/i';

        if (!preg_match($pattern, $filename)) {
            return $filename;
        }

        return preg_replace($pattern, '', $filename) ?? $filename;
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

    // phpcs:ignore Syde.Classes.DisallowGetterSetter.GetterFound -- internal accessor
    protected function getServiceIdForPage(int $page): ?string
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
    // phpcs:ignore Syde.Classes.DisallowGetterSetter.GetterFound -- internal accessor
    protected function getCanvasDimensions(int $page): array
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
     * Resolve a IIIF value that may be a plain string, a v2 language object
     * (`{ "@value": "...", "@language": "..." }`), a v3 language map
     * (`{ "en": ["..."], "de": ["..."] }`), or an array of language objects.
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

        // v2 array of language objects.
        $first = $value[0] ?? null;
        if (is_array($first) && isset($first['@value']) && is_string($first['@value'])) {
            return $first['@value'];
        }

        // v3 language map — pick the first available translation.
        foreach ($value as $langValues) {
            if (is_array($langValues) && isset($langValues[0]) && is_string($langValues[0])) {
                return $langValues[0];
            }
        }

        return '';
    }
}
