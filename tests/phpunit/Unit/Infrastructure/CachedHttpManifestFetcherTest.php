<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF\Tests\Unit\Infrastructure;

use Config;
use MediaWiki\Extension\InstantIIIF\Infrastructure\CachedHttpManifestFetcher;
use MediaWiki\Http\HttpRequestFactory;
use MWHttpRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use StatusValue;
use WANObjectCache;

#[CoversClass(CachedHttpManifestFetcher::class)]
class CachedHttpManifestFetcherTest extends TestCase
{
    private function makeFetcher(
        MWHttpRequest $request,
        ?WANObjectCache $cache = null
    ): CachedHttpManifestFetcher {

        $httpFactory = $this->createStub(HttpRequestFactory::class);
        $httpFactory->method('create')->willReturn($request);

        $config = $this->createStub(Config::class);
        $config->method('get')->willReturn(5);

        return new CachedHttpManifestFetcher(
            $cache ?? new WANObjectCache(),
            $httpFactory,
            $config
        );
    }

    public function testFetchReturnsDecodedJson(): void
    {
        $request = $this->createStub(MWHttpRequest::class);
        $request->method('execute')->willReturn(new StatusValue(true));
        $request->method('getContent')->willReturn('{"label":"Foo","items":[]}');

        $result = $this->makeFetcher($request)->fetch('https://example.org/manifest.json');

        self::assertSame(['label' => 'Foo', 'items' => []], $result);
    }

    public function testFetchReturnsNullOnHttpError(): void
    {
        $request = $this->createStub(MWHttpRequest::class);
        $request->method('execute')->willReturn(new StatusValue(false));
        $request->method('getContent')->willReturn('');

        $result = $this->makeFetcher($request)->fetch('https://example.org/missing.json');

        self::assertNull($result);
    }

    public function testFetchReturnsNullOnEmptyBody(): void
    {
        $request = $this->createStub(MWHttpRequest::class);
        $request->method('execute')->willReturn(new StatusValue(true));
        $request->method('getContent')->willReturn('');

        $result = $this->makeFetcher($request)->fetch('https://example.org/empty.json');

        self::assertNull($result);
    }

    public function testFetchReturnsNullOnMalformedJson(): void
    {
        $request = $this->createStub(MWHttpRequest::class);
        $request->method('execute')->willReturn(new StatusValue(true));
        $request->method('getContent')->willReturn('{this is not json}');

        $result = $this->makeFetcher($request)->fetch('https://example.org/bad.json');

        self::assertNull($result);
    }

    public function testFetchReturnsNullOnScalarJson(): void
    {
        // Decodable but not an array — IIIF docs are always objects.
        $request = $this->createStub(MWHttpRequest::class);
        $request->method('execute')->willReturn(new StatusValue(true));
        $request->method('getContent')->willReturn('"just a string"');

        self::assertNull(
            $this->makeFetcher($request)->fetch('https://example.org/scalar.json')
        );
    }

    public function testCustomTtlIsPassedToWanCache(): void
    {
        $cache = $this->recordingCache();
        $this->fetcherWithTtl($cache, 99999)->fetch('https://example.org/manifest.json');

        self::assertSame(99999, $cache->lastTtl);
    }

    public function testNonPositiveTtlFallsBackToDefault(): void
    {
        $cache = $this->recordingCache();
        $this->fetcherWithTtl($cache, 0)->fetch('https://example.org/manifest.json');

        self::assertSame(CachedHttpManifestFetcher::DEFAULT_TTL_SECONDS, $cache->lastTtl);
    }

    public function testOmittedTtlFallsBackToDefault(): void
    {
        $cache = $this->recordingCache();
        $this->fetcherWithTtl($cache, null)->fetch('https://example.org/manifest.json');

        self::assertSame(CachedHttpManifestFetcher::DEFAULT_TTL_SECONDS, $cache->lastTtl);
    }

    private function recordingCache(): WANObjectCache
    {
        return new class extends WANObjectCache {
            public int $lastTtl = -1;

            public function getWithSetCallback(string $key, int $ttl, callable $callback, array $opts = []): mixed
            {
                $this->lastTtl = $ttl;
                return $callback();
            }
        };
    }

    private function fetcherWithTtl(WANObjectCache $cache, ?int $ttl): CachedHttpManifestFetcher
    {
        $request = $this->createStub(MWHttpRequest::class);
        $request->method('execute')->willReturn(new StatusValue(true));
        $request->method('getContent')->willReturn('{"label":"Foo"}');

        $httpFactory = $this->createStub(HttpRequestFactory::class);
        $httpFactory->method('create')->willReturn($request);

        $config = $this->createStub(Config::class);
        $config->method('get')->willReturn(5);

        return new CachedHttpManifestFetcher($cache, $httpFactory, $config, $ttl);
    }
}
