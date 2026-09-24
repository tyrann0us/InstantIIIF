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
 * $info. Each repo is given a stub `backend` object so FileRepo never
 * reaches for MW's backend service wiring (absent from the standalone suite).
 * registerCacheBackends() — the extension.json callback's worker — is pure
 * array juggling and covered directly.
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

    public function testNamedBackendIsLeftAloneWhenThumbZoneIsRelocated(): void
    {
        // Caching on, but the admin relocated the `thumb` zone to a container
        // they back themselves and named their backend as a string (the usual
        // $wgForeignFileRepos shape). Construction must succeed.
        $repo = new Repo([
            'name' => 'iiif',
            'class' => Repo::class,
            'backend' => 'admin-managed-backend',
            'directory' => '/tmp/iiif',
            'zones' => ['thumb' => ['container' => 'custom', 'url' => '/custom']],
            'iiifSources' => [
                ['id' => 'fotothek', 'idPattern' => '/^df_/'],
            ],
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

    /**
     * @param array<string, mixed> $repo
     * @param array<int, array<string, mixed>> $backends
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    private function register(array $repo, array $backends = []): array
    {
        $repos = [
            ['name' => 'shared', 'class' => 'ForeignAPIRepo', 'backend' => 'shared-backend'],
            array_merge(['name' => 'iiif', 'class' => Repo::class, 'backend' => 'iiif-backend'], $repo),
        ];
        Repo::registerCacheBackends($repos, $backends, '/srv/images', 0755);
        return [$repos, $backends];
    }

    public function testRegistrationDefaultsDirectoryAndRegistersCacheBackend(): void
    {
        [$repos, $backends] = $this->register([]);

        self::assertSame('/srv/images', $repos[1]['directory']);
        self::assertArrayNotHasKey('directory', $repos[0]);
        self::assertCount(1, $backends);
        self::assertSame('iiif-backend', $backends[0]['name']);
        self::assertSame('/srv/images/iiif-cache', $backends[0]['containerPaths']['iiif-cache']);
        self::assertSame('/srv/images', $backends[0]['containerPaths']['iiif-public']);
        self::assertSame(0755, $backends[0]['directoryMode']);
    }

    public function testRegistrationRecognisesSubclasses(): void
    {
        $subclass = get_class(new class (['name' => 'iiif', 'backend' => new \FileBackend()]) extends Repo {
        });

        [$repos, $backends] = $this->register(['class' => $subclass, 'directory' => '/data/']);

        self::assertSame('/data/iiif-cache', $backends[0]['containerPaths']['iiif-cache']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array<int, array<string, mixed>>}>
     */
    public static function unmanagedBackends(): iterable
    {
        yield 'relocated thumb zone' => [['zones' => ['thumb' => ['container' => 'custom']]], []];
        yield 'caching disabled' => [['imageCacheExpiry' => 0], []];
        yield 'injected backend object' => [['backend' => new \FileBackend()], []];
        yield 'backend already registered' => [[], [['name' => 'iiif-backend', 'class' => 'X']]];
    }

    /**
     * @param array<string, mixed> $repo
     * @param array<int, array<string, mixed>> $backends
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unmanagedBackends')]
    public function testRegistrationLeavesUnmanagedBackendsAlone(array $repo, array $backends): void
    {
        [$repos, $after] = $this->register($repo, $backends);

        self::assertSame($backends, $after);
        // 'directory' is still defaulted: FileBackendGroup's auto-backend reads it.
        self::assertSame('/srv/images', $repos[1]['directory']);
    }
}
