<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF\Infrastructure\Parser;

use MediaWiki\Extension\InstantIIIF\Domain\Parser\ManifestParserInterface;
use MediaWiki\Extension\InstantIIIF\Domain\ValueObject\ImageDimensions;
use MediaWiki\Extension\InstantIIIF\Domain\ValueObject\ServiceId;

/**
 * Native IIIF Presentation API manifest parser.
 *
 * Handles both v2 (sequences/canvases) and v3 (items) formats without external dependencies.
 *
 * @see https://iiif.io/api/presentation/2.1/
 * @see https://iiif.io/api/presentation/3.0/
 */
final class NativeManifestParser implements ManifestParserInterface
{
    private const MANIFEST_TYPES = [
        'sc:Manifest', // v2
        'Manifest', // v3
    ];

    public function supports(string $json): bool
    {
        $data = $this->decode($json);
        if ($data === null) {
            return false;
        }

        $type = $data['@type'] ?? $data['type'] ?? null;
        if ($type === null) {
            return false;
        }

        return in_array($type, self::MANIFEST_TYPES, true);
    }

    public function extractServiceIds(string $json): array
    {
        $data = $this->decode($json);
        if ($data === null) {
            return [];
        }

        $canvases = $this->getCanvases($data);
        $serviceIds = [];

        foreach ($canvases as $index => $canvas) {
            $serviceId = $this->extractServiceIdFromCanvas($canvas);
            if ($serviceId !== null) {
                $serviceIds[$index] = $serviceId;
            }
        }

        return $serviceIds;
    }

    public function extractCanvasDimensions(string $json): array
    {
        $data = $this->decode($json);
        if ($data === null) {
            return [];
        }

        $canvases = $this->getCanvases($data);
        $dimensions = [];

        foreach ($canvases as $index => $canvas) {
            $width = $this->extractInt($canvas, 'width');
            $height = $this->extractInt($canvas, 'height');
            $dimensions[$index] = new ImageDimensions($width, $height);
        }

        return $dimensions;
    }

    public function extractHomepageUrl(string $json): ?string
    {
        $data = $this->decode($json);
        if ($data === null) {
            return null;
        }

        // Try v3 homepage first
        $homepage = $this->extractV3Homepage($data);
        if ($homepage !== null) {
            return $homepage;
        }

        // Try v2 related
        $related = $this->extractV2Related($data);
        if ($related !== null) {
            return $related;
        }

        // Try seeAlso
        return $this->extractSeeAlso($data);
    }

    public function getCanvasCount(string $json): int
    {
        $data = $this->decode($json);
        if ($data === null) {
            return 0;
        }

        return count($this->getCanvases($data));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(string $json): ?array
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            return is_array($data) ? $data : null;
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * Get canvases from either v2 or v3 manifest structure.
     *
     * @param array<string, mixed> $data
     * @return array<int, array<string, mixed>>
     */
    private function getCanvases(array $data): array
    {
        // v3: items array directly on manifest
        if (isset($data['items']) && is_array($data['items'])) {
            return array_values($data['items']);
        }

        // v2: sequences[0].canvases
        if (isset($data['sequences'][0]['canvases']) && is_array($data['sequences'][0]['canvases'])) {
            return array_values($data['sequences'][0]['canvases']);
        }

        return [];
    }

    /**
     * @param array<string, mixed> $canvas
     */
    private function extractServiceIdFromCanvas(array $canvas): ?ServiceId
    {
        // Try v3 path: items[0].items[0].body.service
        $serviceId = $this->extractServiceIdV3($canvas);
        if ($serviceId !== null) {
            return $serviceId;
        }

        // Try v2 path: images[0].resource.service
        return $this->extractServiceIdV2($canvas);
    }

    /**
     * v3: canvas.items[0].items[0].body.service
     *
     * @param array<string, mixed> $canvas
     */
    private function extractServiceIdV3(array $canvas): ?ServiceId
    {
        // Get annotation page
        $annoPage = $canvas['items'][0] ?? null;
        if (!is_array($annoPage)) {
            return null;
        }

        // Get annotation
        $annotation = $annoPage['items'][0] ?? null;
        if (!is_array($annotation)) {
            return null;
        }

        // Get body (the image resource)
        $body = $annotation['body'] ?? null;
        if (!is_array($body)) {
            return null;
        }

        return $this->extractServiceFromResource($body);
    }

    /**
     * v2: canvas.images[0].resource.service
     *
     * @param array<string, mixed> $canvas
     */
    private function extractServiceIdV2(array $canvas): ?ServiceId
    {
        $image = $canvas['images'][0] ?? null;
        if (!is_array($image)) {
            return null;
        }

        $resource = $image['resource'] ?? null;
        if (!is_array($resource)) {
            return null;
        }

        $serviceId = $this->extractServiceFromResource($resource);
        if ($serviceId !== null) {
            return $serviceId;
        }

        // Fallback: try to extract service ID from resource @id
        return $this->extractServiceIdFromResourceId($resource);
    }

    /**
     * Extract service ID from a resource's service property.
     *
     * @param array<string, mixed> $resource
     */
    private function extractServiceFromResource(array $resource): ?ServiceId
    {
        $service = $resource['service'] ?? null;

        // service can be an array of services or a single service
        if (is_array($service)) {
            // If it's an array of services, take the first one
            if (isset($service[0]) && is_array($service[0])) {
                $service = $service[0];
            }

            $id = $service['@id'] ?? $service['id'] ?? null;
            if (is_string($id) && $id !== '') {
                return ServiceId::tryFrom($id);
            }
        }

        return null;
    }

    /**
     * Try to extract service ID from resource @id URL pattern.
     *
     * Some v2 manifests don't have explicit service, but the resource @id
     * contains the IIIF Image API URL.
     *
     * @param array<string, mixed> $resource
     */
    private function extractServiceIdFromResourceId(array $resource): ?ServiceId
    {
        $resourceId = $resource['@id'] ?? $resource['id'] ?? null;
        if (!is_string($resourceId)) {
            return null;
        }

        // Pattern: extract base URL up to the identifier
        // Example: https://example.org/iiif/2/image123/full/full/0/default.jpg
        // Should become: https://example.org/iiif/2/image123
        if (preg_match('#^(https?://[^/]+(?:/[^/]+)*/iiif/\d+/[^/]+)#', $resourceId, $matches)) {
            return ServiceId::tryFrom($matches[1]);
        }

        // Alternative pattern without /iiif/ in path
        if (preg_match('#^(https?://.+?)/full/[^/]+/\d+/#', $resourceId, $matches)) {
            return ServiceId::tryFrom($matches[1]);
        }

        return null;
    }

    /**
     * v3 homepage extraction.
     *
     * @param array<string, mixed> $data
     */
    private function extractV3Homepage(array $data): ?string
    {
        $homepage = $data['homepage'] ?? null;

        if (is_string($homepage) && $this->isValidUrl($homepage)) {
            return $homepage;
        }

        if (is_array($homepage)) {
            // Can be array of homepage objects
            $first = $homepage[0] ?? $homepage;
            if (is_array($first)) {
                $id = $first['id'] ?? $first['@id'] ?? null;
                if (is_string($id) && $this->isValidUrl($id)) {
                    return $id;
                }
            }
        }

        return null;
    }

    /**
     * v2 related extraction.
     *
     * @param array<string, mixed> $data
     */
    private function extractV2Related(array $data): ?string
    {
        $related = $data['related'] ?? null;

        if (is_string($related) && $this->isValidUrl($related)) {
            return $related;
        }

        if (is_array($related)) {
            // Can be array of related resources
            $first = $related[0] ?? $related;
            if (is_string($first) && $this->isValidUrl($first)) {
                return $first;
            }
            if (is_array($first)) {
                $id = $first['@id'] ?? $first['id'] ?? null;
                if (is_string($id) && $this->isValidUrl($id)) {
                    return $id;
                }
            }
        }

        return null;
    }

    /**
     * Extract seeAlso URL (often links to external metadata).
     *
     * @param array<string, mixed> $data
     */
    private function extractSeeAlso(array $data): ?string
    {
        $seeAlso = $data['seeAlso'] ?? null;

        if (is_string($seeAlso) && $this->isValidUrl($seeAlso)) {
            return $seeAlso;
        }

        if (is_array($seeAlso)) {
            $first = $seeAlso[0] ?? $seeAlso;
            if (is_string($first) && $this->isValidUrl($first)) {
                return $first;
            }
            if (is_array($first)) {
                $id = $first['@id'] ?? $first['id'] ?? null;
                if (is_string($id) && $this->isValidUrl($id)) {
                    return $id;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function extractInt(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        return is_numeric($value) ? (int) $value : 0;
    }

    private function isValidUrl(string $url): bool
    {
        return (bool) preg_match('#^https?://#i', $url);
    }
}
