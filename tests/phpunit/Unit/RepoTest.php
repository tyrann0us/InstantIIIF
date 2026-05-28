<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF\Tests\Unit;

use MediaWiki\Extension\InstantIIIF\Repo;
use MediaWiki\MediaWikiServices;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Repo: idPatterns() and getInfo()'s apiurl field, which the
 * JS-side media-search patch matches against to recognise IIIF repos.
 */
#[CoversClass(Repo::class)]
class RepoTest extends TestCase
{
    protected function setUp(): void
    {
        MediaWikiServices::reset();
    }

    protected function tearDown(): void
    {
        MediaWikiServices::reset();
    }

    private function makeRepo(array $iiifSources): Repo
    {
        return new Repo([
            'name' => 'iiif',
            'class' => Repo::class,
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

        // Parent FileRepo::getInfo() (stubbed) returns `name`. The override
        // must extend rather than replace the parent payload.
        self::assertSame('iiif', $info['name'] ?? null);
    }

    public function testGetInfoEmptyPatternsForRepoWithoutSources(): void
    {
        $repo = $this->makeRepo([]);

        self::assertSame([], $repo->idPatterns());
    }
}
