<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF\Domain\Repository;

use MediaWiki\Extension\InstantIIIF\Domain\ValueObject\ServiceId;
use MediaWiki\Extension\InstantIIIF\Domain\ValueObject\ServiceLimits;

/**
 * Interface for fetching IIIF Image API info.json.
 */
interface ImageInfoRepositoryInterface
{
    /**
     * Fetch and parse info.json for a given service.
     *
     * Returns ServiceLimits with the image dimensions and optional maxWidth/maxHeight/maxArea.
     */
    public function fetchServiceLimits(ServiceId $serviceId): ServiceLimits;
}
