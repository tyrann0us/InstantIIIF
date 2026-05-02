<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF\Application\Service;

use MediaWiki\Extension\InstantIIIF\Application\Query\ResolveResourceQuery;
use MediaWiki\Extension\InstantIIIF\Application\ReadModel\ResolvedResource;
use MediaWiki\Extension\InstantIIIF\Domain\Entity\IIIFResource;
use MediaWiki\Extension\InstantIIIF\Domain\Parser\ManifestParserInterface;
use MediaWiki\Extension\InstantIIIF\Domain\Repository\ManifestRepositoryInterface;
use MediaWiki\Extension\InstantIIIF\Domain\ValueObject\ObjectId;
use MediaWiki\Extension\InstantIIIF\Domain\ValueObject\ProviderConfig;

/**
 * Application service for resolving IIIF resources.
 *
 * Coordinates manifest fetching and parsing to produce a resolved resource.
 */
final class ResourceResolver
{
    private ManifestRepositoryInterface $manifestRepository;
    private ManifestParserInterface $manifestParser;
    /** @var array<int, ProviderConfig> */
    private array $providers;

    /**
     * @param array<int, ProviderConfig> $providers
     */
    public function __construct(
        ManifestRepositoryInterface $manifestRepository,
        ManifestParserInterface $manifestParser,
        array $providers
    ) {

        $this->manifestRepository = $manifestRepository;
        $this->manifestParser = $manifestParser;
        $this->providers = $providers;
    }

    public function resolve(ResolveResourceQuery $query): ?ResolvedResource
    {
        try {
            $objectId = new ObjectId($query->objectId());
        } catch (\InvalidArgumentException) {
            return null;
        }

        foreach ($this->providers as $provider) {
            $resolved = $this->tryResolveWithProvider($objectId, $provider);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        return null;
    }

    private function tryResolveWithProvider(ObjectId $objectId, ProviderConfig $provider): ?ResolvedResource
    {
        if (!$provider->matchesObjectId($objectId)) {
            return null;
        }

        $manifestUrl = $provider->buildManifestUrl($objectId);

        try {
            $json = $this->manifestRepository->fetchManifest($manifestUrl);
        } catch (\RuntimeException) {
            return null;
        }

        if (!$this->manifestParser->supports($json)) {
            return null;
        }

        $serviceIds = $this->manifestParser->extractServiceIds($json);
        if ($serviceIds === []) {
            return null;
        }

        $homepageUrl = $this->manifestParser->extractHomepageUrl($json)
            ?? $provider->buildLandingUrl($objectId);

        $resource = new IIIFResource(
            $objectId,
            $provider->id(),
            $manifestUrl,
            $serviceIds,
            $this->manifestParser->extractCanvasDimensions($json),
            $homepageUrl
        );

        return ResolvedResource::fromEntity($resource);
    }
}
