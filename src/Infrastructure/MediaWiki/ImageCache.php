<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF\Infrastructure\MediaWiki;

/**
 * Port for a local cache of remote IIIF image bytes.
 *
 * Implementations take a remote IIIF Image API URL and return a local wiki
 * URL serving a cached copy of those bytes, fetching and storing them on
 * first use. Returning null signals "not cached / caching unavailable", so
 * callers fall back to hotlinking the remote URL — the cache is always an
 * optimisation, never a hard dependency.
 */
interface ImageCache
{
    /**
     * Local URL for a cached copy of $remoteUrl, or null when caching is
     * disabled or any step (fetch/store) fails.
     */
    public function localUrlFor(string $remoteUrl): ?string;
}
