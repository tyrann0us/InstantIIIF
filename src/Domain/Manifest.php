<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF\Domain;

/**
 * Read-only wrapper around a decoded IIIF Presentation manifest (v2 or v3).
 *
 * Centralises the v2/v3 dispatch and the metadata-label search that was
 * previously duplicated across IIIFFile and MetadataExtractor. Returns
 * raw values for fields that need locale-aware resolution (label,
 * attribution); callers should pipe those through LocalizedText with the
 * appropriate language preference.
 */
final class Manifest
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(public readonly array $raw)
    {
    }

    /**
     * @param array<string, mixed> $raw
     */
    public static function from(array $raw): self
    {
        return new self($raw);
    }

    /**
     * Some IIIF servers return a stub "error manifest" rather than HTTP
     * 404 when an object ID is unknown. Detect the two shapes we've seen
     * in the wild so the resolver moves on to the next provider.
     */
    public function isError(): bool
    {
        $label = $this->raw['label'] ?? '';
        if (is_string($label) && str_starts_with($label, 'error:')) {
            return true;
        }
        $canvasId = $this->raw['sequences'][0]['canvases'][0]['@id'] ?? '';
        if (is_string($canvasId) && str_starts_with($canvasId, 'error/')) {
            return true;
        }
        return false;
    }

    public function pageCount(): int
    {
        return count($this->canvases());
    }

    public function canvasDimensionsFor(Page $page): Dimensions
    {
        $canvas = $this->canvasAt($page);
        if ($canvas === null) {
            return Dimensions::unknown();
        }
        return Dimensions::of(
            (int) ($canvas['width'] ?? 0),
            (int) ($canvas['height'] ?? 0)
        );
    }

    public function imageServiceIdFor(Page $page): ?string
    {
        $canvas = $this->canvasAt($page);
        if ($canvas === null) {
            return null;
        }
        // v3 canvas: items[0].items[0].body.service
        $serviceId = $this->extractServiceFromV3Canvas($canvas);
        if ($serviceId !== null) {
            return $serviceId;
        }
        // v2 canvas: images[0].resource.service
        return $this->extractServiceFromV2Canvas($canvas);
    }

    /**
     * v3 `homepage`, v2 `related`. Returns null if neither is present.
     */
    public function landingUrl(): ?string
    {
        $homepage = $this->raw['homepage'] ?? null;
        if ($homepage !== null) {
            $url = self::extractHttpUrl($homepage);
            if ($url !== null) {
                return $url;
            }
        }
        $related = $this->raw['related'] ?? null;
        if ($related !== null) {
            $url = self::extractHttpUrl($related);
            if ($url !== null) {
                return $url;
            }
        }
        return null;
    }

    /**
     * Locate a label-matching metadata entry and return any HTTP(S) URL it
     * contains. Values may be plain URL strings, HTML fragments
     * (`<a href="…">…</a>`), language maps, or arrays thereof — providers
     * differ a lot here, especially SLUB which embeds links inside HTML.
     *
     * @param list<string> $labels Labels to match (case-insensitive)
     * @param list<string> $preferredLanguages Used to resolve multi-lingual
     *                                         labels/values
     */
    public function findUrlInMetadataByLabels(array $labels, array $preferredLanguages = []): string
    {
        $metadata = $this->raw['metadata'] ?? [];
        if (!is_array($metadata)) {
            return '';
        }
        $needles = array_map('mb_strtolower', $labels);
        foreach ($metadata as $item) {
            if (!is_array($item)) {
                continue;
            }
            $label = mb_strtolower(
                LocalizedText::resolve($item['label'] ?? '', $preferredLanguages)
            );
            if (!in_array($label, $needles, true)) {
                continue;
            }
            $url = self::extractUrlFromValue($item['value'] ?? '', $preferredLanguages);
            if ($url !== '') {
                return $url;
            }
        }
        return '';
    }

    /**
     * Raw `label` field, ready to pass through LocalizedText::resolve().
     */
    public function rawLabel(): mixed
    {
        return $this->raw['label'] ?? '';
    }

    /**
     * Raw attribution-shaped value. v2: `attribution`; v3:
     * `requiredStatement.value`. Caller resolves through LocalizedText.
     */
    public function rawAttribution(): mixed
    {
        $attribution = $this->raw['attribution'] ?? null;
        if ($attribution !== null && $attribution !== '') {
            return $attribution;
        }
        $stmt = $this->raw['requiredStatement'] ?? null;
        if (is_array($stmt) && isset($stmt['value'])) {
            return $stmt['value'];
        }
        return '';
    }

    /**
     * v2: `license`, v3: `rights`. Usually a URL string or list of strings.
     */
    public function rawLicense(): mixed
    {
        return $this->raw['rights'] ?? $this->raw['license'] ?? '';
    }

    /* -------------------- Internals -------------------- */

    /**
     * @return list<array<string, mixed>>
     */
    private function canvases(): array
    {
        // v3
        if (isset($this->raw['items']) && is_array($this->raw['items'])) {
            return array_values(array_filter(
                $this->raw['items'],
                static fn (mixed $canvas): bool => is_array($canvas)
            ));
        }
        // v2
        $canvases = $this->raw['sequences'][0]['canvases'] ?? null;
        if (is_array($canvases)) {
            return array_values(array_filter(
                $canvases,
                static fn (mixed $canvas): bool => is_array($canvas)
            ));
        }
        return [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function canvasAt(Page $page): ?array
    {
        $canvases = $this->canvases();
        $idx = $page->zeroIndexed();
        return $canvases[$idx] ?? null;
    }

    /**
     * @param array<string, mixed> $canvas
     */
    private function extractServiceFromV3Canvas(array $canvas): ?string
    {
        $body = $canvas['items'][0]['items'][0]['body'] ?? null;
        if (!is_array($body)) {
            return null;
        }
        return self::extractServiceIdFromField($body['service'] ?? null);
    }

    /**
     * @param array<string, mixed> $canvas
     */
    private function extractServiceFromV2Canvas(array $canvas): ?string
    {
        $resource = $canvas['images'][0]['resource'] ?? null;
        if (!is_array($resource)) {
            return null;
        }
        $id = self::extractServiceIdFromField($resource['service'] ?? null);
        if ($id !== null) {
            return $id;
        }
        // Fallback: derive service base from the resource @id URL.
        $resourceId = $resource['@id'] ?? null;
        if (is_string($resourceId) && preg_match('#^(.*?/iiif/2/[^/]+)#', $resourceId, $match)) {
            return $match[1];
        }
        return null;
    }

    private static function extractServiceIdFromField(mixed $service): ?string
    {
        if (!is_array($service)) {
            return null;
        }
        $id = $service['@id'] ?? $service['id'] ?? null;
        if (is_string($id)) {
            return rtrim($id, '/');
        }
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
     * Pull an HTTP(S) URL out of a v3 `homepage` / v2 `related` value, which
     * may be a string, an object with `@id`/`id`, or an array thereof.
     */
    private static function extractHttpUrl(mixed $value): ?string
    {
        if (is_string($value) && preg_match('~^https?://~', $value)) {
            return $value;
        }
        if (is_array($value)) {
            $id = $value['@id'] ?? $value['id'] ?? null;
            if (is_string($id) && preg_match('~^https?://~', $id)) {
                return $id;
            }
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
     * Pull the first HTTP(S) URL out of a metadata `value`. Accepts:
     *  - plain URL string ("https://…")
     *  - HTML containing <a href="…">
     *  - v2/v3 language object/map shapes
     *  - array of any of the above
     *
     * @param list<string> $preferredLanguages
     */
    private static function extractUrlFromValue(mixed $value, array $preferredLanguages): string
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
        $resolved = LocalizedText::resolve($value, $preferredLanguages);
        if ($resolved !== '') {
            $url = self::extractUrlFromValue($resolved, $preferredLanguages);
            if ($url !== '') {
                return $url;
            }
        }
        foreach ($value as $entry) {
            $url = self::extractUrlFromValue($entry, $preferredLanguages);
            if ($url !== '') {
                return $url;
            }
        }
        return '';
    }
}
