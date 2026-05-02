<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF\Application\ReadModel;

use MediaWiki\Extension\InstantIIIF\Domain\Entity\IIIFResource;
use MediaWiki\Extension\InstantIIIF\Domain\ValueObject\PageNumber;

/**
 * Read model for a resolved IIIF resource.
 *
 * Provides a simplified view of the IIIFResource entity for consumption
 * by the Infrastructure layer.
 */
final class ResolvedResource
{
    private IIIFResource $resource;

    private function __construct(IIIFResource $resource)
    {
        $this->resource = $resource;
    }

    public static function fromEntity(IIIFResource $resource): self
    {
        return new self($resource);
    }

    public function objectId(): string
    {
        return $this->resource->objectId()->asString();
    }

    public function providerId(): string
    {
        return $this->resource->providerId();
    }

    public function manifestUrl(): string
    {
        return $this->resource->manifestUrl();
    }

    public function serviceIdForPage(int $page): ?string
    {
        $serviceId = $this->resource->serviceIdForPage(new PageNumber($page));
        return $serviceId?->asString();
    }

    public function widthForPage(int $page): int
    {
        return $this->resource->dimensionsForPage(new PageNumber($page))->width();
    }

    public function heightForPage(int $page): int
    {
        return $this->resource->dimensionsForPage(new PageNumber($page))->height();
    }

    public function homepageUrl(): ?string
    {
        return $this->resource->homepageUrl();
    }

    public function pageCount(): int
    {
        return $this->resource->pageCount();
    }

    public function hasPage(int $page): bool
    {
        return $this->resource->hasPage(new PageNumber($page));
    }

    public function isMultiPage(): bool
    {
        return $this->resource->isMultiPage();
    }

    /**
     * Get the underlying entity for advanced operations.
     *
     * @internal Should only be used by Application services
     */
    public function entity(): IIIFResource
    {
        return $this->resource;
    }
}
