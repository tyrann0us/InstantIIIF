<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF\Tests\Unit\Domain;

use MediaWiki\Extension\InstantIIIF\Domain\Dimensions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Dimensions::class)]
class DimensionsTest extends TestCase
{
    public function testOfHoldsWidthAndHeight(): void
    {
        $d = Dimensions::of(1000, 800);
        self::assertSame(1000, $d->width);
        self::assertSame(800, $d->height);
    }

    public function testNegativeValuesAreClampedToZero(): void
    {
        $d = Dimensions::of(-100, -50);
        self::assertSame(0, $d->width);
        self::assertSame(0, $d->height);
    }

    public function testUnknownIsZeroByZero(): void
    {
        $d = Dimensions::unknown();
        self::assertSame(0, $d->width);
        self::assertSame(0, $d->height);
        self::assertFalse($d->isKnown());
    }

    public function testIsKnownRequiresBothAxes(): void
    {
        self::assertFalse(Dimensions::of(0, 500)->isKnown());
        self::assertFalse(Dimensions::of(500, 0)->isKnown());
        self::assertTrue(Dimensions::of(500, 800)->isKnown());
    }
}
