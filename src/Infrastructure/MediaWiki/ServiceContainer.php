<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF\Infrastructure\MediaWiki;

use MediaWiki\Extension\InstantIIIF\Application\Service\ResourceResolver;
use MediaWiki\Extension\InstantIIIF\Application\Service\ThumbnailService;
use MediaWiki\Extension\InstantIIIF\Domain\Parser\ManifestParserInterface;
use MediaWiki\Extension\InstantIIIF\Domain\Repository\ImageInfoRepositoryInterface;
use MediaWiki\Extension\InstantIIIF\Domain\Repository\ManifestRepositoryInterface;
use MediaWiki\Extension\InstantIIIF\Domain\Service\ImageUrlBuilder;
use MediaWiki\Extension\InstantIIIF\Domain\Service\ServiceLimitCalculator;
use MediaWiki\Extension\InstantIIIF\Domain\ValueObject\ProviderConfig;
use MediaWiki\Extension\InstantIIIF\Infrastructure\Parser\NativeManifestParser;
use MediaWiki\Extension\InstantIIIF\Infrastructure\Repository\CachedHttpImageInfoRepository;
use MediaWiki\Extension\InstantIIIF\Infrastructure\Repository\CachedHttpManifestRepository;
use MediaWiki\MediaWikiServices;

/**
 * Service container for InstantIIIF extension.
 *
 * Provides lazy-loaded instances of all services, wired together with their dependencies.
 */
final class ServiceContainer
{
    private static ?self $instance = null;

    private ?ManifestRepositoryInterface $manifestRepository = null;
    private ?ImageInfoRepositoryInterface $imageInfoRepository = null;
    private ?ManifestParserInterface $manifestParser = null;
    private ?ImageUrlBuilder $urlBuilder = null;
    private ?ServiceLimitCalculator $limitCalculator = null;
    private ?ResourceResolver $resourceResolver = null;
    private ?ThumbnailService $thumbnailService = null;

    /** @var array<int, ProviderConfig>|null */
    private ?array $providerConfigs = null;

    private function __construct()
    {
        // Private constructor for singleton
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Reset the container (useful for testing).
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    public function manifestRepository(): ManifestRepositoryInterface
    {
        if ($this->manifestRepository === null) {
            $services = MediaWikiServices::getInstance();
            $timeout = $this->getTimeout();

            $this->manifestRepository = new CachedHttpManifestRepository(
                $services->getHttpRequestFactory(),
                $services->getMainWANObjectCache(),
                $timeout
            );
        }
        return $this->manifestRepository;
    }

    public function imageInfoRepository(): ImageInfoRepositoryInterface
    {
        if ($this->imageInfoRepository === null) {
            $services = MediaWikiServices::getInstance();
            $timeout = $this->getTimeout();

            $this->imageInfoRepository = new CachedHttpImageInfoRepository(
                $services->getHttpRequestFactory(),
                $services->getMainWANObjectCache(),
                $timeout
            );
        }
        return $this->imageInfoRepository;
    }

    public function manifestParser(): ManifestParserInterface
    {
        if ($this->manifestParser === null) {
            $this->manifestParser = new NativeManifestParser();
        }
        return $this->manifestParser;
    }

    public function urlBuilder(): ImageUrlBuilder
    {
        if ($this->urlBuilder === null) {
            $this->urlBuilder = new ImageUrlBuilder();
        }
        return $this->urlBuilder;
    }

    public function limitCalculator(): ServiceLimitCalculator
    {
        if ($this->limitCalculator === null) {
            $this->limitCalculator = new ServiceLimitCalculator();
        }
        return $this->limitCalculator;
    }

    /**
     * @param array<int, ProviderConfig> $providers
     */
    public function resourceResolver(array $providers): ResourceResolver
    {
        // ResourceResolver depends on provider config, so we create it fresh
        // if providers change (or use cached if same)
        $configHash = md5(serialize($providers));
        static $lastHash = '';

        if ($this->resourceResolver === null || $configHash !== $lastHash) {
            $this->resourceResolver = new ResourceResolver(
                $this->manifestRepository(),
                $this->manifestParser(),
                $providers
            );
            $lastHash = $configHash;
        }

        return $this->resourceResolver;
    }

    public function thumbnailService(): ThumbnailService
    {
        if ($this->thumbnailService === null) {
            $this->thumbnailService = new ThumbnailService(
                $this->imageInfoRepository(),
                $this->urlBuilder(),
                $this->limitCalculator()
            );
        }
        return $this->thumbnailService;
    }

    private function getTimeout(): int
    {
        try {
            $config = MediaWikiServices::getInstance()->getMainConfig();
            $timeout = $config->get('InstantIIIFDefaultTimeout');
            return is_numeric($timeout) ? (int) $timeout : 5;
        } catch (\Throwable) {
            return 5;
        }
    }
}
