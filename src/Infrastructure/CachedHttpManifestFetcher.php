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
    /**
     * Fallback TTL used when the caller does not pass one (e.g. when image
     * caching is disabled, so the repo has no configured expiry). Manifests
     * and info.json change rarely, but this keeps a conservative refresh.
     */
    public const DEFAULT_TTL_SECONDS = 3600;

    private int $ttl;

    public function __construct(
        private WANObjectCache $cache,
        private HttpRequestFactory $httpFactory,
        private Config $config,
        ?int $ttl = null
    ) {

        $this->ttl = $ttl !== null && $ttl > 0 ? $ttl : self::DEFAULT_TTL_SECONDS;
    }

    public function fetch(string $url): ?array
    {
        $key = $this->cache->makeKey('InstantIIIF', 'json', md5($url));

        $value = $this->cache->getWithSetCallback(
            $key,
            $this->ttl,
            function () use ($url): ?array {
                return $this->fetchAndDecode($url);
            },
            ['pcTTL' => $this->ttl]
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
