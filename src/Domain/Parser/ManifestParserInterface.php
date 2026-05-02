<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF\Domain\Parser;

use MediaWiki\Extension\InstantIIIF\Domain\ValueObject\ImageDimensions;
use MediaWiki\Extension\InstantIIIF\Domain\ValueObject\ServiceId;

/**
 * Interface for parsing IIIF Presentation API manifests.
 *
 * Implementations must handle both v2 and v3 manifest formats.
 */
interface ManifestParserInterface
{
    /**
     * Check if this parser can handle the given manifest JSON.
     */
    public function supports(string $json): bool;

    /**
     * Extract service IDs for all canvases.
     *
     * @return array<int, ServiceId> 0-indexed array of service IDs
     */
    public function extractServiceIds(string $json): array;

    /**
     * Extract canvas dimensions for all canvases.
     *
     * @return array<int, ImageDimensions> 0-indexed array of dimensions
     */
    public function extractCanvasDimensions(string $json): array;

    /**
     * Extract homepage/landing page URL from manifest.
     *
     * Tries v3 `homepage`, v2 `related`, and provider metadata in that order.
     */
    public function extractHomepageUrl(string $json): ?string;

    /**
     * Get the number of canvases (pages) in the manifest.
     */
    public function getCanvasCount(string $json): int;
}
