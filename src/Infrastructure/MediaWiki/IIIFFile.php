<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF\Infrastructure\MediaWiki;

use File;
use MediaTransformError;
use MediaWiki\Extension\InstantIIIF\Application\Query\GetThumbnailQuery;
use MediaWiki\Extension\InstantIIIF\Application\Query\ResolveResourceQuery;
use MediaWiki\Extension\InstantIIIF\Application\ReadModel\ResolvedResource;
use MediaWiki\Extension\InstantIIIF\Application\Service\ResourceResolver;
use MediaWiki\Extension\InstantIIIF\Application\Service\ThumbnailService;
use MediaWiki\Extension\InstantIIIF\Domain\ValueObject\ServiceId;
use ThumbnailImage;
use Title;

/**
 * MediaWiki File implementation for IIIF resources.
 *
 * This is a thin wrapper that delegates all business logic to Application services.
 */
class IIIFFile extends File
{
    private ResourceResolver $resourceResolver;
    private ThumbnailService $thumbnailService;
    private ?ResolvedResource $resolved = null;
    private bool $resolutionAttempted = false;

    /**
     * @param string|false $time
     */
    public function __construct(
        Repo $repo,
        Title $title,
        ResourceResolver $resourceResolver,
        ThumbnailService $thumbnailService,
        $time = false
    ) {

        parent::__construct($title, $repo, $time);
        $this->resourceResolver = $resourceResolver;
        $this->thumbnailService = $thumbnailService;
    }

    public function exists(): bool
    {
        return $this->ensureResolved() !== null;
    }

    // phpcs:ignore Syde.Classes.DisallowGetterSetter.GetterFound -- MediaWiki File override
    public function getSize(): int
    {
        // We do not fetch binaries; report unknown
        return 0;
    }

    // phpcs:ignore Syde.Classes.DisallowGetterSetter.GetterFound -- MediaWiki File override
    public function getMimeType(): string
    {
        return 'image/jpeg';
    }

    // phpcs:ignore Syde.Classes.DisallowGetterSetter.GetterFound -- MediaWiki File override
    public function getMediaType(): string
    {
        return MEDIATYPE_BITMAP;
    }

    /**
     * @param int $page
     */
    // phpcs:ignore Syde.Classes.DisallowGetterSetter.GetterFound, Syde.Functions.ArgumentTypeDeclaration.NoArgumentType -- MediaWiki File override
    public function getWidth($page = 1): int
    {
        $resolved = $this->ensureResolved();
        return $resolved?->widthForPage((int) $page) ?? 0;
    }

    /**
     * @param int $page
     */
    // phpcs:ignore Syde.Classes.DisallowGetterSetter.GetterFound, Syde.Functions.ArgumentTypeDeclaration.NoArgumentType -- MediaWiki File override
    public function getHeight($page = 1): int
    {
        $resolved = $this->ensureResolved();
        return $resolved?->heightForPage((int) $page) ?? 0;
    }

    // phpcs:ignore Syde.Classes.DisallowGetterSetter.GetterFound -- MediaWiki File override
    public function getUrl(): string
    {
        return $this->getFullUrl();
    }

    // phpcs:ignore Syde.Classes.DisallowGetterSetter.GetterFound -- MediaWiki File override
    public function getFullUrl(): string
    {
        $resolved = $this->ensureResolved();
        if ($resolved === null) {
            return '';
        }

        $serviceId = $resolved->serviceIdForPage(1);
        if ($serviceId === null) {
            return '';
        }

        $container = ServiceContainer::getInstance();
        return $container->urlBuilder()->buildFullUrl(
            new ServiceId($serviceId)
        );
    }

    // phpcs:ignore Syde.Classes.DisallowGetterSetter.GetterFound -- MediaWiki File override
    public function getDescriptionUrl(): string
    {
        $resolved = $this->ensureResolved();
        return $resolved?->homepageUrl() ?? '';
    }

    /**
     * @param array<string, mixed> $params
     * @param int $flags
     * @return ThumbnailImage|MediaTransformError
     */
    // phpcs:ignore Syde.Functions.ArgumentTypeDeclaration.NoArgumentType, Syde.Functions.ReturnTypeDeclaration.NoReturnType -- MediaWiki File override
    public function transform($params, $flags = 0)
    {
        $resolved = $this->ensureResolved();
        if ($resolved === null) {
            return new MediaTransformError(
                'iiif-unresolved',
                (int) ($params['width'] ?? 0),
                (int) ($params['height'] ?? 0)
            );
        }

        $query = GetThumbnailQuery::fromTransformParams($params);

        if (!$resolved->hasPage($query->page())) {
            return new MediaTransformError(
                'iiif-unresolved',
                $query->width(),
                $query->height()
            );
        }

        $result = $this->thumbnailService->getThumbnail($resolved, $query);

        if ($result === null) {
            return new MediaTransformError(
                'iiif-unresolved',
                $query->width(),
                $query->height()
            );
        }

        return new ThumbnailImage(
            $this,
            $result->url(),
            false,
            [
                'width' => $result->width(),
                'height' => $result->height(),
            ]
        );
    }

    /**
     * Check if the file is multi-page (e.g., a multi-canvas IIIF document).
     */
    public function isMultiPage(): bool
    {
        $resolved = $this->ensureResolved();
        return $resolved?->isMultiPage() ?? false;
    }

    /**
     * Get the number of pages.
     */
    public function pageCount(): int
    {
        $resolved = $this->ensureResolved();
        return $resolved?->pageCount() ?? 0;
    }

    private function ensureResolved(): ?ResolvedResource
    {
        if ($this->resolutionAttempted) {
            return $this->resolved;
        }

        $this->resolutionAttempted = true;

        $title = $this->getTitle();
        if ($title === null) {
            return null;
        }

        $query = new ResolveResourceQuery($title->getDBkey());
        $this->resolved = $this->resourceResolver->resolve($query);

        return $this->resolved;
    }
}
