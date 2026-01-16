<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF;

use File;
use MediaTransformError;
use MediaWiki\MediaWikiServices;
use ThumbnailImage;
use Title;
use Ubl\Iiif\Tools\IiifHelper;

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
     *     manifestRaw: array<string, mixed>,
     *     manifestObj: object
     * }|null
     */
    protected ?array $resolved = null;

    /** @var array<string, array<string, mixed>> Cache for info.json per image service id */
    protected array $infoJsonMap = [];

    /** Provider-specific label mapping to find landing/homepage URL in `metadata` */
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
     * @param int $page
     */
    // phpcs:ignore Syde.Classes.DisallowGetterSetter.GetterFound, Syde.Functions.ArgumentTypeDeclaration.NoArgumentType -- MediaWiki File override
    public function getWidth($page = 1): int
    {
        $resolved = $this->ensureResolved();
        if (!$resolved) {
            return 0;
        }
        $dims = $this->getCanvasDimensions($this->normalizePage($page));
        if ($dims[0] && $dims[1]) {
            return (int) $dims[0];
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
        $dims = $this->getCanvasDimensions($this->normalizePage($page));
        if ($dims[0] && $dims[1]) {
            return (int) $dims[1];
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
     * Human-readable landing page for the object.
     * v3: `homepage`, v2: `related`, fallback: provider metadata label mapping
     */
    // phpcs:ignore Syde.Classes.DisallowGetterSetter.GetterFound -- MediaWiki File override
    public function getDescriptionUrl(): string
    {
        $resolved = $this->ensureResolved();
        if (!$resolved) {
            return '';
        }

        $manifestObj = $resolved['manifestObj'];

        $homepageUrl = $this->extractHomepageFromManifest($manifestObj);
        if ($homepageUrl !== null) {
            return $homepageUrl;
        }

        $relatedUrl = $this->extractRelatedFromManifest($manifestObj);
        if ($relatedUrl !== null) {
            return $relatedUrl;
        }

        return $this->extractUrlFromMetadata($resolved);
    }

    /**
     * Extract homepage URL from a manifest object (v3 style).
     */
    private function extractHomepageFromManifest(object $manifestObj): ?string
    {
        if (!method_exists($manifestObj, 'getHomepage')) {
            return null;
        }

        try {
            $homepage = $manifestObj->getHomepage();
            return $this->extractIdFromResource($homepage);
        } catch (\Throwable $exception) {
            return null;
        }
    }

    /**
     * Extract related URL from a manifest object (v2 style).
     */
    private function extractRelatedFromManifest(object $manifestObj): ?string
    {
        if (!method_exists($manifestObj, 'getRelated')) {
            return null;
        }

        try {
            $related = $manifestObj->getRelated();
            if (is_string($related) && preg_match('~^https?://~', $related)) {
                return $related;
            }
            return $this->extractIdFromResource($related);
        } catch (\Throwable $exception) {
            return null;
        }
    }

    /**
     * Extract ID from a resource object or array.
     */
    private function extractIdFromResource(mixed $resource): ?string
    {
        if (is_object($resource) && method_exists($resource, 'getId')) {
            $resourceId = $resource->getId();
            if (is_string($resourceId) && preg_match('~^https?://~', $resourceId)) {
                return $resourceId;
            }
        }

        if (is_array($resource) && isset($resource[0])) {
            $first = $resource[0];
            if (is_object($first) && method_exists($first, 'getId')) {
                $resourceId = $first->getId();
                if (is_string($resourceId) && preg_match('~^https?://~', $resourceId)) {
                    return $resourceId;
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
    public function transform($params, $flags = 0)
    {
        $page = $this->normalizePage($params['page'] ?? 1);
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
        return $pageNum >= 1 ? $pageNum : 1;
    }

    /**
     * @return array{
     *     provider: string,
     *     objectId: string,
     *     manifestUrl: string,
     *     manifestRaw: array<string, mixed>,
     *     manifestObj: object
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

            // Reader is mandatory; parse once.
            $manifestObj = IiifHelper::loadIiifResource($json);
            if ($manifestObj === null) {
                continue;
            }
            $manifestRaw = json_decode($json, true) ?: [];

            $this->resolved = [
                'provider' => (string) ($src['id'] ?? 'default'),
                'objectId' => $objId,
                'manifestUrl' => $manifestUrl,
                'manifestRaw' => $manifestRaw,
                'manifestObj' => $manifestObj,
            ];
            return $this->resolved;
        }

        $this->resolved = null;
        return null;
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
        /** @var Repo $repo */
        $repo = $this->repo;
        return $repo->iiifSources();
    }

    // phpcs:ignore Syde.Classes.DisallowGetterSetter.GetterFound -- internal accessor
    protected function getServiceIdForPage(int $page): ?string
    {
        $resolved = $this->ensureResolved();
        if (!$resolved) {
            return null;
        }
        return $this->extractServiceFromReader($resolved['manifestObj'], $page);
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
        return $this->extractCanvasDimsFromReader($resolved['manifestObj'], $page);
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
     * Passt angefragte w/h an die vom Dienst unterstützten Limits an.
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

    /* -------------------- Reader-based extraction -------------------- */

    private function extractServiceFromReader(object $manifestObj, int $page): ?string
    {
        $idx = max(0, $page - 1);
        $canvases = $this->getCanvasesFromManifest($manifestObj);

        if (!$canvases || !isset($canvases[$idx])) {
            return null;
        }
        $canvas = $canvases[$idx];

        // Try v3 path first.
        $serviceId = $this->extractServiceFromV3Canvas($canvas);
        if ($serviceId !== null) {
            return $serviceId;
        }

        // Fall back to v2 path.
        return $this->extractServiceFromV2Canvas($canvas);
    }

    /**
     * @return array<mixed>|null
     */
    // phpcs:ignore Syde.Classes.DisallowGetterSetter.GetterFound -- internal accessor
    private function getCanvasesFromManifest(object $manifestObj): ?array
    {
        $canvases = method_exists($manifestObj, 'getItems')
            ? $manifestObj->getItems()
            : null;

        if (!$canvases && method_exists($manifestObj, 'getSequences')) {
            $seq = $manifestObj->getSequences()[0] ?? null;
            $canvases = $seq ? $seq->getCanvases() : null;
        }

        return $canvases;
    }

    private function extractServiceFromV3Canvas(object $canvas): ?string
    {
        $items = method_exists($canvas, 'getItems') ? $canvas->getItems() : null;
        if (!$items) {
            return null;
        }

        $annopage = $items[0] ?? null;
        if (!is_object($annopage)) {
            return null;
        }
        $annos = method_exists($annopage, 'getItems') ? $annopage->getItems() : null;
        $anno = $annos[0] ?? null;
        if (!is_object($anno)) {
            return null;
        }
        $body = method_exists($anno, 'getBody') ? $anno->getBody() : null;

        if (is_object($body) && method_exists($body, 'getService')) {
            $svc = $body->getService();
            if (is_object($svc) && method_exists($svc, 'getId')) {
                return rtrim($svc->getId(), '/');
            }
        }

        return null;
    }

    private function extractServiceFromV2Canvas(object $canvas): ?string
    {
        if (!method_exists($canvas, 'getImages')) {
            return null;
        }

        $img = $canvas->getImages()[0] ?? null;
        if (!is_object($img) || !method_exists($img, 'getResource')) {
            return null;
        }

        $res = $img->getResource();
        if (!is_object($res)) {
            return null;
        }

        if (method_exists($res, 'getService')) {
            $svc = $res->getService();
            if (is_object($svc) && method_exists($svc, 'getId')) {
                return rtrim($svc->getId(), '/');
            }
        }

        if (method_exists($res, 'getId')) {
            $resourceId = $res->getId();
            $pattern = '#^(.*?/iiif/2/[^/]+)#';
            if (is_string($resourceId) && preg_match($pattern, $resourceId, $match)) {
                return $match[1];
            }
        }

        return null;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function extractCanvasDimsFromReader(object $manifestObj, int $page): array
    {
        $idx = max(0, $page - 1);
        $canvases = $this->getCanvasesFromManifest($manifestObj);

        if (!$canvases || !isset($canvases[$idx])) {
            return [0, 0];
        }
        $canvas = $canvases[$idx];
        if (!is_object($canvas)) {
            return [0, 0];
        }

        $width = method_exists($canvas, 'getWidth') ? (int) $canvas->getWidth() : 0;
        $height = method_exists($canvas, 'getHeight') ? (int) $canvas->getHeight() : 0;
        return [$width, $height];
    }

    private function stringFromMaybeLangMap(mixed $value): string
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

        foreach ($value as $langVals) {
            if (is_array($langVals) && isset($langVals[0]) && is_string($langVals[0])) {
                return $langVals[0];
            }
        }

        return '';
    }
}
