<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF\Infrastructure\Repository;

use MediaWiki\Extension\InstantIIIF\Domain\Repository\ImageInfoRepositoryInterface;
use MediaWiki\Extension\InstantIIIF\Domain\ValueObject\ServiceId;
use MediaWiki\Extension\InstantIIIF\Domain\ValueObject\ServiceLimits;
use MediaWiki\Http\HttpRequestFactory;
use WANObjectCache;

/**
 * Fetches IIIF Image API info.json via HTTP with WANObjectCache caching.
 */
final class CachedHttpImageInfoRepository implements ImageInfoRepositoryInterface
{
    private HttpRequestFactory $httpFactory;
    private WANObjectCache $cache;
    private int $timeout;
    private int $cacheTtl;

    public function __construct(
        HttpRequestFactory $httpFactory,
        WANObjectCache $cache,
        int $timeout = 5,
        int $cacheTtl = 3600
    ) {

        $this->httpFactory = $httpFactory;
        $this->cache = $cache;
        $this->timeout = $timeout;
        $this->cacheTtl = $cacheTtl;
    }

    public function fetchServiceLimits(ServiceId $serviceId): ServiceLimits
    {
        $url = $serviceId->infoJsonUrl();
        $key = $this->cache->makeKey('InstantIIIF', 'info', md5($url));

        /** @var array<string, mixed>|false $infoJson */
        $infoJson = $this->cache->getWithSetCallback(
            $key,
            $this->cacheTtl,
            function () use ($url): array|false {
                return $this->doFetch($url);
            },
            ['pcTTL' => $this->cacheTtl]
        );

        if ($infoJson === false) {
            return ServiceLimits::unlimited();
        }

        return ServiceLimits::fromInfoJson($infoJson);
    }

    /**
     * @return array<string, mixed>|false
     */
    private function doFetch(string $url): array|false
    {
        $request = $this->httpFactory->create($url, [
            'timeout' => $this->timeout,
            'followRedirects' => true,
        ]);

        $status = $request->execute();

        if (!$status->isOK()) {
            return false;
        }

        $content = $request->getContent();
        if ($content === '' || $content === null) {
            return false;
        }

        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            return is_array($data) ? $data : false;
        } catch (\JsonException) {
            return false;
        }
    }
}
