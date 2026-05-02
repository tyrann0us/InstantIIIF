<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF\Domain\Entity;

use MediaWiki\Extension\InstantIIIF\Domain\ValueObject\ImageDimensions;
use MediaWiki\Extension\InstantIIIF\Domain\ValueObject\ObjectId;
use MediaWiki\Extension\InstantIIIF\Domain\ValueObject\PageNumber;
use MediaWiki\Extension\InstantIIIF\Domain\ValueObject\ServiceId;

/**
 * Represents a resolved IIIF resource with all extracted metadata.
 *
 * This is the aggregate root for IIIF resource data.
 */
final class IIIFResource
{
    private ObjectId $objectId;
    private string $providerId;
    private string $manifestUrl;
    /** @var array<int, ServiceId> */
    private array $serviceIds;
    /** @var array<int, ImageDimensions> */
    private array $canvasDimensions;
    private ?string $homepageUrl;

    /**
     * @param array<int, ServiceId> $serviceIds 0-indexed
     * @param array<int, ImageDimensions> $canvasDimensions 0-indexed
     */
    public function __construct(
        ObjectId $objectId,
        string $providerId,
        string $manifestUrl,
        array $serviceIds,
        array $canvasDimensions,
        ?string $homepageUrl = null
    ) {

        $this->objectId = $objectId;
        $this->providerId = $providerId;
        $this->manifestUrl = $manifestUrl;
        $this->serviceIds = array_values($serviceIds);
        $this->canvasDimensions = array_values($canvasDimensions);
        $this->homepageUrl = $homepageUrl;
    }

    public function objectId(): ObjectId
    {
        return $this->objectId;
    }

    public function providerId(): string
    {
        return $this->providerId;
    }

    public function manifestUrl(): string
    {
        return $this->manifestUrl;
    }

    public function serviceIdForPage(PageNumber $page): ?ServiceId
    {
        return $this->serviceIds[$page->asIndex()] ?? null;
    }

    public function dimensionsForPage(PageNumber $page): ImageDimensions
    {
        return $this->canvasDimensions[$page->asIndex()] ?? ImageDimensions::empty();
    }

    public function homepageUrl(): ?string
    {
        return $this->homepageUrl;
    }

    public function pageCount(): int
    {
        return count($this->serviceIds);
    }

    public function hasPage(PageNumber $page): bool
    {
        return isset($this->serviceIds[$page->asIndex()]);
    }

    public function isMultiPage(): bool
    {
        return $this->pageCount() > 1;
    }

    /**
     * @return array<int, ServiceId>
     */
    public function allServiceIds(): array
    {
        return $this->serviceIds;
    }
}
