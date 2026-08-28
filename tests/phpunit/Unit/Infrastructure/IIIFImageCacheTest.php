<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF\Tests\Unit\Infrastructure;

use MediaWiki\Extension\InstantIIIF\Infrastructure\MediaWiki\IIIFImageCache;
use MediaWiki\Extension\InstantIIIF\Infrastructure\MediaWiki\Repo;
use MediaWiki\Http\HttpRequestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for IIIFImageCache: the disabled / no-zone short-circuits, the
 * cache-hit path (no provider traffic), the miss path (fetch + store), and
 * the failure fall-backs (fetch failure, empty body, store failure) — all of
 * which must return null so IIIFFile falls back to hotlinking the remote URL.
 */
#[CoversClass(IIIFImageCache::class)]
class IIIFImageCacheTest extends TestCase
{
    private const REMOTE = 'https://provider.example/iiif/2/df_x/full/800,/0/default.jpg';
    private const ZONE_PATH = 'mwstore://iiif-backend/iiif-cache';
    private const ZONE_URL = '/images/iiif-cache';

    /**
     * @param string|false $zoneUrl
     */
    private function repoStub(
        ?string $zonePath = self::ZONE_PATH,
        $zoneUrl = self::ZONE_URL,
        string $hashPath = '',
        ?\FileBackend $backend = null
    ): Repo {
        $repo = $this->createStub(Repo::class);
        $repo->method('getZonePath')->willReturn($zonePath);
        $repo->method('getZoneUrl')->willReturn($zoneUrl);
        $repo->method('getHashPath')->willReturn($hashPath);
        $repo->method('getBackend')->willReturn($backend ?? new \FileBackend());
        return $repo;
    }

    private function httpFactoryReturning(bool $ok, string $body): HttpRequestFactory
    {
        $request = $this->createStub(\MWHttpRequest::class);
        $request->method('execute')->willReturn(new \StatusValue($ok));
        $request->method('getContent')->willReturn($body);

        $factory = $this->createStub(HttpRequestFactory::class);
        $factory->method('create')->willReturn($request);
        return $factory;
    }

    private function backend(bool $exists, bool $storeOk = true): \FileBackend
    {
        $backend = $this->createStub(\FileBackend::class);
        $backend->method('fileExists')->willReturn($exists);
        $backend->method('prepare')->willReturn(new \StatusValue(true));
        $backend->method('quickCreate')->willReturn(new \StatusValue($storeOk));
        return $backend;
    }

    private function expectedUrl(string $hashPath = ''): string
    {
        return self::ZONE_URL . '/' . $hashPath . sha1(self::REMOTE) . '.jpg';
    }

    public function testReturnsNullWhenCachingDisabled(): void
    {
        $cache = new IIIFImageCache($this->repoStub(), $this->httpFactoryReturning(true, 'x'), 0, 5);

        self::assertNull($cache->localUrlFor(self::REMOTE));
    }

    public function testReturnsNullWhenZoneUrlNotConfigured(): void
    {
        // Default FileRepo `thumb` zone with no `url` ⇒ getZoneUrl() === false.
        $cache = new IIIFImageCache(
            $this->repoStub(self::ZONE_PATH, false),
            $this->httpFactoryReturning(true, 'x'),
            3600,
            5
        );

        self::assertNull($cache->localUrlFor(self::REMOTE));
    }

    public function testReturnsNullWhenZonePathMissing(): void
    {
        $cache = new IIIFImageCache(
            $this->repoStub(null, self::ZONE_URL),
            $this->httpFactoryReturning(true, 'x'),
            3600,
            5
        );

        self::assertNull($cache->localUrlFor(self::REMOTE));
    }

    public function testReturnsLocalUrlOnCacheHitWithoutFetching(): void
    {
        // execute() would fail if called — proves a hit never hits the network.
        $cache = new IIIFImageCache(
            $this->repoStub(backend: $this->backend(exists: true)),
            $this->httpFactoryReturning(false, ''),
            3600,
            5
        );

        self::assertSame($this->expectedUrl(), $cache->localUrlFor(self::REMOTE));
    }

    public function testFetchesAndStoresOnMiss(): void
    {
        $cache = new IIIFImageCache(
            $this->repoStub(backend: $this->backend(exists: false, storeOk: true)),
            $this->httpFactoryReturning(true, 'jpeg-bytes'),
            3600,
            5
        );

        self::assertSame($this->expectedUrl(), $cache->localUrlFor(self::REMOTE));
    }

    public function testIncludesHashPathInLocalUrl(): void
    {
        $cache = new IIIFImageCache(
            $this->repoStub(hashPath: 'a/ab/', backend: $this->backend(exists: true)),
            $this->httpFactoryReturning(false, ''),
            3600,
            5
        );

        self::assertSame($this->expectedUrl('a/ab/'), $cache->localUrlFor(self::REMOTE));
    }

    public function testReturnsNullWhenFetchFails(): void
    {
        $cache = new IIIFImageCache(
            $this->repoStub(backend: $this->backend(exists: false)),
            $this->httpFactoryReturning(false, ''),
            3600,
            5
        );

        self::assertNull($cache->localUrlFor(self::REMOTE));
    }

    public function testReturnsNullWhenFetchBodyEmpty(): void
    {
        $cache = new IIIFImageCache(
            $this->repoStub(backend: $this->backend(exists: false)),
            $this->httpFactoryReturning(true, ''),
            3600,
            5
        );

        self::assertNull($cache->localUrlFor(self::REMOTE));
    }

    public function testReturnsNullWhenStoreFails(): void
    {
        $cache = new IIIFImageCache(
            $this->repoStub(backend: $this->backend(exists: false, storeOk: false)),
            $this->httpFactoryReturning(true, 'jpeg-bytes'),
            3600,
            5
        );

        self::assertNull($cache->localUrlFor(self::REMOTE));
    }
}
