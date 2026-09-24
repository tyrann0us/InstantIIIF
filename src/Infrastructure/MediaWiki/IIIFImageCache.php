<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF\Infrastructure\MediaWiki;

use MediaWiki\Http\HttpRequestFactory;

/**
 * Stores remote IIIF image bytes in the repo's `thumb` zone on first use and
 * serves every later request from the local copy, so each distinct
 * (image, size) URL is fetched from the provider at most once.
 *
 * Reuses the storage primitives the repo already inherits from FileRepo
 * (`getBackend()`, `getZonePath()`, `getZoneUrl()`, `getHashPath()`) rather
 * than MediaWiki's ForeignAPIRepo caching, which is welded to the MediaWiki
 * API path InstantIIIF doesn't use.
 *
 * The cache is write-once. Unlike ForeignAPIRepo's cache, it never
 * revalidates against the provider on a hit: IIIF object bytes are
 * immutable, and caching here exists to minimise outbound requests to the
 * provider, which revalidation would undo.
 *
 * The local path and URL come from the sha1 of the *full* remote URL. The
 * basename won't do: every IIIF URL ends in `default.jpg`, so it would
 * collide across every page, region and size.
 */
class IIIFImageCache
{
    /** Spoofed extension so the stored object looks like the JPEG it is. */
    private const FILE_EXTENSION = '.jpg';

    public function __construct(
        private Repo $repo,
        private HttpRequestFactory $httpFactory,
        private int $expiry,
        private int $timeout
    ) {
    }

    /**
     * Local URL for a cached copy of $remoteUrl, or null when caching is
     * disabled or fetching or storing fails. Callers then hotlink the
     * remote URL.
     */
    public function localUrlFor(string $remoteUrl): ?string
    {
        if ($this->expiry <= 0) {
            return null;
        }

        $zonePath = $this->repo->getZonePath('thumb');
        $zoneUrl = $this->repo->getZoneUrl('thumb');
        if (!is_string($zonePath) || $zonePath === '' || !is_string($zoneUrl) || $zoneUrl === '') {
            // No writable, served cache zone configured: fall back to remote.
            return null;
        }

        $name = sha1($remoteUrl) . self::FILE_EXTENSION;
        $relPath = $this->repo->getHashPath($name) . $name;
        $localFile = rtrim($zonePath, '/') . '/' . $relPath;
        $localUrl = rtrim($zoneUrl, '/') . '/' . $relPath;

        $backend = $this->repo->getBackend();
        if ($backend->fileExists(['src' => $localFile])) {
            // Hit: serve the stored copy without contacting the provider.
            return $localUrl;
        }

        $bytes = $this->fetch($remoteUrl);
        if ($bytes === null) {
            return null;
        }

        $backend->prepare(['dir' => dirname($localFile)]);
        if (!$backend->quickCreate(['dst' => $localFile, 'content' => $bytes])->isOK()) {
            return null;
        }

        return $localUrl;
    }

    /**
     * Download the image bytes. Null on transport failure or an empty body.
     */
    private function fetch(string $url): ?string
    {
        $req = $this->httpFactory->create($url, ['timeout' => $this->timeout], __METHOD__);
        if (!$req->execute()->isOK()) {
            return null;
        }
        $body = $req->getContent();
        return $body === '' ? null : $body;
    }
}
