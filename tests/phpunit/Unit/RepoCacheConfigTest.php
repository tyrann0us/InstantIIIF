<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF\Tests\Unit;

use MediaWiki\Extension\InstantIIIF\Infrastructure\MediaWiki\Repo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for Repo's image-cache configuration: the on-by-default
 * expiry, the disable switch, a custom TTL, and the early-return when a
 * `thumb` zone is configured explicitly (which withCacheZone() must not
 * override). Construction also exercises withCacheZone() itself.
 *
 * Zone/URL resolution against a real FileBackend lives in the integration
 * RepoTest, and the real-FSFileBackend cache round-trip in the integration
 * IIIFImageCacheTest; here the standalone FileRepo stub only stores the
 * $info. Each repo is given a stub `backend` object so the constructor's
 * withCacheBackend() short-circuits instead of reaching for MW's backend
 * service wiring (absent from the standalone suite).
 */
#[CoversClass(Repo::class)]
class RepoCacheConfigTest extends TestCase
{
    /**
     * @param array<string, mixed> $extra
     */
    private function makeRepo(array $extra = []): Repo
    {
        return new Repo(array_merge([
            'name' => 'iiif',
            'class' => Repo::class,
            'backend' => new \FileBackend(),
            'directory' => '/tmp/iiif',
            'iiifSources' => [
                ['id' => 'fotothek', 'idPattern' => '/^df_/'],
            ],
        ], $extra));
    }

    public function testImageCachingEnabledByDefault(): void
    {
        $repo = $this->makeRepo();

        self::assertTrue($repo->cacheImagesEnabled());
        self::assertSame(Repo::DEFAULT_IMAGE_CACHE_EXPIRY, $repo->imageCacheExpiry());
    }

    public function testImageCachingDisabledWhenExpiryZero(): void
    {
        $repo = $this->makeRepo(['imageCacheExpiry' => 0]);

        self::assertFalse($repo->cacheImagesEnabled());
        self::assertSame(0, $repo->imageCacheExpiry());
    }

    public function testCustomExpiryIsHonoured(): void
    {
        $repo = $this->makeRepo(['imageCacheExpiry' => 1234]);

        self::assertTrue($repo->cacheImagesEnabled());
        self::assertSame(1234, $repo->imageCacheExpiry());
    }

    public function testConstructsWithExplicitThumbZoneWhenCachingEnabled(): void
    {
        // An explicit thumb zone must be respected (withCacheZone early-returns);
        // construction must still succeed and report caching enabled.
        $repo = $this->makeRepo([
            'zones' => ['thumb' => ['container' => 'custom', 'url' => '/custom']],
        ]);

        self::assertTrue($repo->cacheImagesEnabled());
    }

    public function testNonArrayZonesConfigIsCoercedBeforeAddingCacheZone(): void
    {
        // A malformed (non-array) `zones` value must be replaced rather than
        // crashing when the cache zone is assembled.
        $repo = $this->makeRepo(['zones' => 'not-an-array']);

        self::assertTrue($repo->cacheImagesEnabled());
    }

    public function testDefaultsDirectoryAndStillConfiguresCache(): void
    {
        // Omit `directory` so the upload-dir default branch runs alongside
        // the (default-on) cache-zone assembly.
        $repo = new Repo([
            'name' => 'iiif',
            'class' => Repo::class,
            'backend' => new \FileBackend(),
            'iiifSources' => [],
        ]);

        self::assertTrue($repo->cacheImagesEnabled());
    }
}
