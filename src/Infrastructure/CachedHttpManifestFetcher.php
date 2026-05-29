<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF\Infrastructure;

use Config;
use MediaWiki\Extension\InstantIIIF\Domain\ManifestFetcher;
use MediaWiki\Http\HttpRequestFactory;
use WANObjectCache;

/**
 * MediaWiki adapter for the ManifestFetcher port. Goes through the
 * main WAN cache (with an in-process pcTTL so a single request that
 * fetches a manifest and its info.json doesn't re-decode JSON).
 *
 * Returns the decoded array directly so callers don't repeat the
 * json_decode + is_array dance.
 */
final class CachedHttpManifestFetcher implements ManifestFetcher
{
    private const CACHE_TTL_SECONDS = 3600;

    public function __construct(
        private WANObjectCache $cache,
        private HttpRequestFactory $httpFactory,
        private Config $config
    ) {
    }

    public function fetch(string $url): ?array
    {
        $key = $this->cache->makeKey('InstantIIIF', 'json', md5($url));

        $value = $this->cache->getWithSetCallback(
            $key,
            self::CACHE_TTL_SECONDS,
            function () use ($url): ?array {
                return $this->fetchAndDecode($url);
            },
            ['pcTTL' => self::CACHE_TTL_SECONDS]
        );

        return is_array($value) ? $value : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchAndDecode(string $url): ?array
    {
        $timeout = (int) $this->config->get('InstantIIIFDefaultTimeout');
        $req = $this->httpFactory->create($url, ['timeout' => $timeout]);
        $status = $req->execute();
        if (!$status->isOK()) {
            return null;
        }
        $body = $req->getContent();
        if ($body === '') {
            return null;
        }
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : null;
    }
}
