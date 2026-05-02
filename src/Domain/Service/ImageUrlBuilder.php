<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF\Domain\Service;

use MediaWiki\Extension\InstantIIIF\Domain\ValueObject\ImageDimensions;
use MediaWiki\Extension\InstantIIIF\Domain\ValueObject\ServiceId;

/**
 * Builds IIIF Image API v2/v3 URLs.
 *
 * URL format: {scheme}://{server}{/prefix}/{identifier}/{region}/{size}/{rotation}/{quality}.{format}
 *
 * @see https://iiif.io/api/image/3.0/#4-image-requests
 */
final class ImageUrlBuilder
{
    private const DEFAULT_REGION = 'full';
    private const DEFAULT_ROTATION = '0';
    private const DEFAULT_QUALITY = 'default';
    private const DEFAULT_FORMAT = 'jpg';

    /**
     * Build a full-size image URL.
     */
    public function buildFullUrl(ServiceId $serviceId): string
    {
        return $this->buildUrl($serviceId, 'full');
    }

    /**
     * Build a sized image URL.
     */
    public function buildSizedUrl(ServiceId $serviceId, ImageDimensions $dimensions): string
    {
        $sizeParam = $this->buildSizeParameter($dimensions);
        return $this->buildUrl($serviceId, $sizeParam);
    }

    /**
     * Build a width-constrained image URL.
     */
    public function buildWidthUrl(ServiceId $serviceId, int $width): string
    {
        $sizeParam = $width . ',';
        return $this->buildUrl($serviceId, $sizeParam);
    }

    /**
     * Build a height-constrained image URL.
     */
    public function buildHeightUrl(ServiceId $serviceId, int $height): string
    {
        $sizeParam = ',' . $height;
        return $this->buildUrl($serviceId, $sizeParam);
    }

    private function buildUrl(ServiceId $serviceId, string $sizeParam): string
    {
        return sprintf(
            '%s/%s/%s/%s/%s.%s',
            $serviceId->asString(),
            self::DEFAULT_REGION,
            $sizeParam,
            self::DEFAULT_ROTATION,
            self::DEFAULT_QUALITY,
            self::DEFAULT_FORMAT
        );
    }

    private function buildSizeParameter(ImageDimensions $dimensions): string
    {
        $width = $dimensions->width();
        $height = $dimensions->height();

        if ($width > 0 && $height > 0) {
            return $width . ',' . $height;
        }
        if ($width > 0) {
            return $width . ',';
        }
        if ($height > 0) {
            return ',' . $height;
        }

        return 'full';
    }
}
