<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF\Tests\Integration;

use MediaWiki\Extension\InstantIIIF\Infrastructure\MediaWiki\IIIFImageCache;
use MediaWiki\Extension\InstantIIIF\Infrastructure\MediaWiki\Repo;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\MainConfigNames;
use MediaWikiIntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Wikimedia\FileBackend\FSFileBackend;

/**
 * Real-backend integration test for the local image cache.
 */
#[CoversClass(IIIFImageCache::class)]
#[CoversClass(Repo::class)]
class IIIFImageCacheTest extends MediaWikiIntegrationTestCase
{
    private const REMOTE = 'https://provider.example/iiif/2/df_x/full/800,/0/default.jpg';
    private const BYTES = 'jpeg-bytes-payload';

    private string $tmpDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = wfTempDir() . '/instantiiif-cache-' . wfRandomString(8);
        $this->overrideConfigValue(MainConfigNames::UploadPath, '/images');
    }

    protected function tearDown(): void
    {
        if ($this->tmpDir !== '' && is_dir($this->tmpDir)) {
            $this->removeRecursive($this->tmpDir);
        }
        parent::tearDown();
    }

    /**
     * A repo with caching on (the default) and no injected backend builds its
     * own FSFileBackend that knows the dedicated `iiif-cache` container and
     * roots it at an absolute path under `directory`.
     */
    public function testRepoBuildsFsBackendWithAbsoluteCacheContainer(): void
    {
        $repo = $this->makeRepo();

        $backend = $repo->getBackend();
        self::assertInstanceOf(FSFileBackend::class, $backend);

        $zonePath = $repo->getZonePath('thumb');
        self::assertIsString($zonePath);
        self::assertStringContainsString('iiif-cache', $zonePath);
        // The thumb zone must resolve to a real, preparable filesystem
        // location — the bug was that it resolved to nowhere.
        self::assertTrue($backend->prepare(['dir' => $zonePath])->isOK());
        self::assertSame('/images/iiif-cache', $repo->getZoneUrl('thumb'));
    }

    /**
     * On a miss the bytes are fetched once and written through the real
     * backend; the returned URL is served from the local cache zone and the
     * file physically lands under `<directory>/iiif-cache`.
     */
    public function testFetchesStoresAndServesFromRealBackend(): void
    {
        $repo = $this->makeRepo();
        $cache = new IIIFImageCache(
            $repo,
            $this->httpFactoryReturning(true, self::BYTES),
            Repo::DEFAULT_IMAGE_CACHE_EXPIRY,
            5
        );

        $url = $cache->localUrlFor(self::REMOTE);

        self::assertIsString($url);
        self::assertStringStartsWith('/images/iiif-cache/', $url);

        $stored = $this->findStoredFile();
        self::assertNotNull($stored, 'cache write did not reach the filesystem');
        self::assertSame(self::BYTES, file_get_contents($stored));
    }

    /**
     * A second lookup is served from the stored copy without any provider
     * traffic — proven by wiring an HTTP factory that would fail if called.
     */
    public function testSecondLookupServesFromDiskWithoutFetching(): void
    {
        $repo = $this->makeRepo();

        $miss = new IIIFImageCache(
            $repo,
            $this->httpFactoryReturning(true, self::BYTES),
            Repo::DEFAULT_IMAGE_CACHE_EXPIRY,
            5
        );
        $first = $miss->localUrlFor(self::REMOTE);
        self::assertIsString($first);

        // execute() would report failure if reached — a hit must not fetch.
        $hit = new IIIFImageCache(
            $repo,
            $this->httpFactoryReturning(false, ''),
            Repo::DEFAULT_IMAGE_CACHE_EXPIRY,
            5
        );

        self::assertSame($first, $hit->localUrlFor(self::REMOTE));
    }

    private function makeRepo(): Repo
    {
        return new Repo([
            'name' => 'iiif',
            'class' => Repo::class,
            'directory' => $this->tmpDir,
            'iiifSources' => [],
        ]);
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

    /**
     * Locate the single cached `.jpg` written under the cache container.
     */
    private function findStoredFile(): ?string
    {
        $root = $this->tmpDir . '/iiif-cache';
        if (!is_dir($root)) {
            return null;
        }
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iter as $file) {
            if ($file->isFile() && $file->getExtension() === 'jpg') {
                return $file->getPathname();
            }
        }
        return null;
    }

    private function removeRecursive(string $dir): void
    {
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iter as $path) {
            $path->isDir() ? rmdir($path->getPathname()) : unlink($path->getPathname());
        }
        rmdir($dir);
    }
}
