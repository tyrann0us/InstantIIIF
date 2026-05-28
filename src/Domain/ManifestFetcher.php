<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF\Domain;

/**
 * Port for fetching IIIF JSON documents (manifests and info.json).
 *
 * Domain code depends on this interface so the resolver and the
 * URL-builder are testable without an HTTP stack. The MediaWiki
 * adapter (Infrastructure\CachedHttpManifestFetcher) wraps the HTTP
 * client and the WAN cache.
 */
interface ManifestFetcher
{
    /**
     * Fetch and decode JSON at $url. Returns null on any failure
     * (HTTP error, non-JSON body, decode error). Callers should treat
     * null as "resource unavailable" and not retry within the same
     * request.
     *
     * @return array<string, mixed>|null
     */
    public function fetch(string $url): ?array;
}
