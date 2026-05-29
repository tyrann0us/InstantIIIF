<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF\Tests\Unit\Domain;

use MediaWiki\Extension\InstantIIIF\Domain\ProviderQuirks;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProviderQuirks::class)]
class ProviderQuirksTest extends TestCase
{
    public function testFotothekHasLandingLabel(): void
    {
        self::assertSame(['Link zum Werk'], ProviderQuirks::landingLabelsFor('deutsche-fotothek'));
    }

    public function testSlubHasLandingLabels(): void
    {
        self::assertSame(
            ['PURL', 'Persistent URL'],
            ProviderQuirks::landingLabelsFor('slub-dresden')
        );
    }

    public function testSlubHasLicenseLabels(): void
    {
        self::assertSame(
            ['Rechteinformationen', 'Rights'],
            ProviderQuirks::licenseLabelsFor('slub-dresden')
        );
    }

    public function testUnknownProviderHasNoLandingLabels(): void
    {
        self::assertSame([], ProviderQuirks::landingLabelsFor('unknown-xyz'));
    }

    public function testUnknownProviderHasNoLicenseLabels(): void
    {
        self::assertSame([], ProviderQuirks::licenseLabelsFor('unknown-xyz'));
    }

    public function testEmptyProviderIdYieldsNoLabels(): void
    {
        self::assertSame([], ProviderQuirks::landingLabelsFor(''));
        self::assertSame([], ProviderQuirks::licenseLabelsFor(''));
    }

    public function testFotothekHasNoLicenseLabels(): void
    {
        // Fotothek has no license metadata fallback — only SLUB does.
        self::assertSame([], ProviderQuirks::licenseLabelsFor('deutsche-fotothek'));
    }
}
