<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF\Domain\Repository;

/**
 * Interface for fetching IIIF manifests.
 */
interface ManifestRepositoryInterface
{
    /**
     * Fetch manifest JSON from the given URL.
     *
     * @throws \RuntimeException If the manifest cannot be fetched
     */
    public function fetchManifest(string $url): string;
}
