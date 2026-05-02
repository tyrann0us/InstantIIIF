<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF\Application\Service;

use MediaWiki\Extension\InstantIIIF\Application\Query\GetThumbnailQuery;
use MediaWiki\Extension\InstantIIIF\Application\ReadModel\ResolvedResource;
use MediaWiki\Extension\InstantIIIF\Application\ReadModel\ThumbnailResult;
use MediaWiki\Extension\InstantIIIF\Domain\Repository\ImageInfoRepositoryInterface;
use MediaWiki\Extension\InstantIIIF\Domain\Service\ImageUrlBuilder;
use MediaWiki\Extension\InstantIIIF\Domain\Service\ServiceLimitCalculator;
use MediaWiki\Extension\InstantIIIF\Domain\ValueObject\ImageDimensions;
use MediaWiki\Extension\InstantIIIF\Domain\ValueObject\PageNumber;
use MediaWiki\Extension\InstantIIIF\Domain\ValueObject\ServiceId;
use MediaWiki\Extension\InstantIIIF\Domain\ValueObject\ServiceLimits;

/**
 * Application service for generating IIIF thumbnails.
 *
 * Coordinates URL building with service limit calculations.
 */
final class ThumbnailService
{
    private ImageInfoRepositoryInterface $imageInfoRepository;
    private ImageUrlBuilder $urlBuilder;
    private ServiceLimitCalculator $limitCalculator;

    public function __construct(
        ImageInfoRepositoryInterface $imageInfoRepository,
        ImageUrlBuilder $urlBuilder,
        ServiceLimitCalculator $limitCalculator
    ) {

        $this->imageInfoRepository = $imageInfoRepository;
        $this->urlBuilder = $urlBuilder;
        $this->limitCalculator = $limitCalculator;
    }

    public function getThumbnail(ResolvedResource $resource, GetThumbnailQuery $query): ?ThumbnailResult
    {
        $page = new PageNumber($query->page());
        $serviceId = $resource->entity()->serviceIdForPage($page);

        if ($serviceId === null) {
            return null;
        }

        $canvasDimensions = $resource->entity()->dimensionsForPage($page);
        $limits = $this->getServiceLimits($serviceId, $canvasDimensions);

        return $this->buildThumbnailResult($serviceId, $query, $limits, $canvasDimensions);
    }

    private function getServiceLimits(ServiceId $serviceId, ImageDimensions $canvasDimensions): ServiceLimits
    {
        $limits = $this->imageInfoRepository->fetchServiceLimits($serviceId);

        // If canvas dimensions are known but info.json dimensions are not,
        // use canvas dimensions as the original
        if ($limits->originalDimensions()->isEmpty() && !$canvasDimensions->isEmpty()) {
            return new ServiceLimits(
                $canvasDimensions,
                $limits->maxWidth() ?: null,
                $limits->maxHeight() ?: null,
                $limits->maxArea()
            );
        }

        return $limits;
    }

    private function buildThumbnailResult(
        ServiceId $serviceId,
        GetThumbnailQuery $query,
        ServiceLimits $limits,
        ImageDimensions $canvasDimensions
    ): ThumbnailResult {

        // No dimensions requested: return full size
        if (!$query->hasDimensions()) {
            return $this->buildFullSizeResult($serviceId, $limits, $canvasDimensions);
        }

        // Width only
        if ($query->hasWidth() && !$query->hasHeight()) {
            return $this->buildWidthOnlyResult($serviceId, $query->width(), $limits);
        }

        // Height only
        if ($query->hasHeight() && !$query->hasWidth()) {
            return $this->buildHeightOnlyResult($serviceId, $query->height(), $limits);
        }

        // Both dimensions
        return $this->buildBothDimensionsResult($serviceId, $query->width(), $query->height(), $limits);
    }

    private function buildFullSizeResult(
        ServiceId $serviceId,
        ServiceLimits $limits,
        ImageDimensions $canvasDimensions
    ): ThumbnailResult {

        $url = $this->urlBuilder->buildFullUrl($serviceId);

        $dimensions = $limits->originalDimensions();
        if ($dimensions->isEmpty()) {
            $dimensions = $canvasDimensions;
        }

        return ThumbnailResult::create($url, $dimensions->width(), $dimensions->height());
    }

    private function buildWidthOnlyResult(
        ServiceId $serviceId,
        int $width,
        ServiceLimits $limits
    ): ThumbnailResult {

        $dimensions = $this->limitCalculator->calculateForWidth($width, $limits);
        $url = $this->urlBuilder->buildWidthUrl($serviceId, $dimensions->width());

        return ThumbnailResult::create($url, $dimensions->width(), $dimensions->height());
    }

    private function buildHeightOnlyResult(
        ServiceId $serviceId,
        int $height,
        ServiceLimits $limits
    ): ThumbnailResult {

        $dimensions = $this->limitCalculator->calculateForHeight($height, $limits);
        $url = $this->urlBuilder->buildHeightUrl($serviceId, $dimensions->height());

        return ThumbnailResult::create($url, $dimensions->width(), $dimensions->height());
    }

    private function buildBothDimensionsResult(
        ServiceId $serviceId,
        int $width,
        int $height,
        ServiceLimits $limits
    ): ThumbnailResult {

        $requested = new ImageDimensions($width, $height);
        $clamped = $this->limitCalculator->clampToLimits($requested, $limits);
        $url = $this->urlBuilder->buildSizedUrl($serviceId, $clamped);

        return ThumbnailResult::create($url, $clamped->width(), $clamped->height());
    }
}
