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
 * Deliberately write-once: unlike ForeignAPIRepo's cache it never revalidates
 * against the provider on a hit. IIIF object bytes are immutable, and the
 * point of caching here is to minimise outbound requests to the provider —
 * revalidation would defeat that.
 *
 * The local path/URL is derived from sha1 of the *full* remote URL (not its
 * basename — every IIIF URL ends in `default.jpg`, which would collide across
 * every page, region and size).
 */
final class IIIFImageCache implements ImageCache
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

    public function localUrlFor(string $remoteUrl): ?string
    {
        if ($this->expiry <= 0) {
            return null;
        }

        $zonePath = $this->repo->getZonePath('thumb');
        $zoneUrl = $this->repo->getZoneUrl('thumb');
        if (!is_string($zonePath) || $zonePath === '' || !is_string($zoneUrl) || $zoneUrl === '') {
            // No writable/served cache zone configured — fall back to remote.
            return null;
        }

        $name = sha1($remoteUrl) . self::FILE_EXTENSION;
        $relPath = $this->repo->getHashPath($name) . $name;
        $localFile = rtrim($zonePath, '/') . '/' . $relPath;
        $localUrl = rtrim($zoneUrl, '/') . '/' . $relPath;

        $backend = $this->repo->getBackend();
        if ($backend->fileExists(['src' => $localFile])) {
            // Hit: serve the stored copy, zero provider traffic.
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
     * Download the image bytes, or null on transport failure / empty body.
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
