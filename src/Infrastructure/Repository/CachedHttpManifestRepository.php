<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF\Infrastructure\Repository;

use MediaWiki\Extension\InstantIIIF\Domain\Repository\ManifestRepositoryInterface;
use MediaWiki\Http\HttpRequestFactory;
use WANObjectCache;

/**
 * Fetches IIIF manifests via HTTP with WANObjectCache caching.
 */
final class CachedHttpManifestRepository implements ManifestRepositoryInterface
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

    public function fetchManifest(string $url): string
    {
        $key = $this->cache->makeKey('InstantIIIF', 'manifest', md5($url));

        /** @var string|false $result */
        $result = $this->cache->getWithSetCallback(
            $key,
            $this->cacheTtl,
            function () use ($url): string|false {
                return $this->doFetch($url);
            },
            ['pcTTL' => $this->cacheTtl]
        );

        if ($result === false) {
            throw new \RuntimeException(
                sprintf('Failed to fetch manifest from: %s', $url)
            );
        }

        return $result;
    }

    private function doFetch(string $url): string|false
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

        return $content;
    }
}
