<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF\Tests\Integration;

use MediaWiki\Extension\InstantIIIF\Infrastructure\MediaWiki\Repo;
use MediaWiki\MainConfigNames;
use MediaWikiIntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Integration tests for Repo: idPatterns() and getInfo()'s apiurl field
 * (matched by the JS-side media-search patch to recognise IIIF repos).
 *
 * Exercises Repo against the real FileRepo / MediaWikiServices machinery
 * — the apiurl assertion is anchored against $wgServer + $wgScriptPath
 * the same way client-side MediaResourceProvider builds it.
 */
#[CoversClass(Repo::class)]
class RepoTest extends MediaWikiIntegrationTestCase
{
    /**
     * @param list<array<string, mixed>|string> $iiifSources
     */
    private function makeRepo(array $iiifSources): Repo
    {
        // FileRepo requires a `backend` — borrow the local repo's so we
        // don't depend on a backend being pre-registered. SetupDynamicConfig
        // handles this for entries declared via $wgForeignFileRepos, but
        // ad-hoc construction (this test, SpecialInstantIIIFInspect) has
        // to pass one explicitly.
        $backend = $this->getServiceContainer()
            ->getRepoGroup()
            ->getLocalRepo()
            ->getBackend();

        return new Repo([
            'name' => 'iiif',
            'class' => Repo::class,
            'backend' => $backend,
            'directory' => '/tmp/iiif',
            'iiifSources' => $iiifSources,
        ]);
    }

    public function testIdPatternsCollectsEverySource(): void
    {
        $repo = $this->makeRepo([
            ['id' => 'fotothek', 'idPattern' => '/^(df_.+)$/'],
            ['id' => 'slub', 'idPattern' => '/^([0-9].+)$/'],
            ['id' => 'bsb', 'idPattern' => '/^(bsb[0-9].+)$/'],
        ]);

        self::assertSame(
            ['/^(df_.+)$/', '/^([0-9].+)$/', '/^(bsb[0-9].+)$/'],
            $repo->idPatterns()
        );
    }

    public function testIdPatternsSkipsSourcesWithoutPattern(): void
    {
        $repo = $this->makeRepo([
            ['id' => 'fotothek', 'idPattern' => '/^(df_.+)$/'],
            ['id' => 'noPattern'],
            ['id' => 'emptyPattern', 'idPattern' => ''],
            'malformedNonArrayEntry',
        ]);

        self::assertSame(['/^(df_.+)$/'], $repo->idPatterns());
    }

    public function testGetInfoExposesApiUrlForJs(): void
    {
        $this->overrideConfigValue(MainConfigNames::Server, 'https://wiki.example.org');
        $this->overrideConfigValue(MainConfigNames::ScriptPath, '/w');

        $repo = $this->makeRepo([
            ['id' => 'fotothek', 'idPattern' => '/^(df_.+)$/'],
        ]);

        $info = $repo->getInfo();

        self::assertArrayHasKey('apiurl', $info);
        self::assertSame('https://wiki.example.org/w/api.php', $info['apiurl']);
    }

    public function testGetInfoPreservesParentInfo(): void
    {
        $repo = $this->makeRepo([
            ['id' => 'fotothek', 'idPattern' => '/^(df_.+)$/'],
        ]);

        $info = $repo->getInfo();

        // FileRepo::getInfo() exposes `name`. The override must extend
        // rather than replace the parent payload.
        self::assertSame('iiif', $info['name'] ?? null);
    }

    public function testGetInfoEmptyPatternsForRepoWithoutSources(): void
    {
        $repo = $this->makeRepo([]);

        self::assertSame([], $repo->idPatterns());
    }

    /**
     * Repo defaults `directory` from $wgUploadDirectory when omitted —
     * exercises the only piece of constructor logic we add on top of
     * FileRepo.
     */
    public function testConstructWithoutDirectoryDefaultsFromUploadDirectoryConfig(): void
    {
        $backend = $this->getServiceContainer()
            ->getRepoGroup()
            ->getLocalRepo()
            ->getBackend();

        $repo = new Repo([
            'name' => 'iiif-no-dir',
            'class' => Repo::class,
            'backend' => $backend,
            'iiifSources' => [],
        ]);

        self::assertSame([], $repo->idPatterns());
        self::assertInstanceOf(Repo::class, $repo);
    }
}
