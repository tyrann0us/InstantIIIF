<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF\Tests\Unit\Domain\ValueObject;

use MediaWiki\Extension\InstantIIIF\Domain\ValueObject\ImageDimensions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ImageDimensions::class)]
final class ImageDimensionsTest extends TestCase
{
    #[Test]
    public function itCreatesWithDimensions(): void
    {
        $dims = new ImageDimensions(800, 600);

        self::assertSame(800, $dims->width());
        self::assertSame(600, $dims->height());
    }

    #[Test]
    public function itClampsNegativeToZero(): void
    {
        $dims = new ImageDimensions(-100, -50);

        self::assertSame(0, $dims->width());
        self::assertSame(0, $dims->height());
    }

    #[Test]
    public function itCreatesEmpty(): void
    {
        $dims = ImageDimensions::empty();

        self::assertSame(0, $dims->width());
        self::assertSame(0, $dims->height());
        self::assertTrue($dims->isEmpty());
    }

    #[Test]
    public function itDetectsEmpty(): void
    {
        self::assertTrue((new ImageDimensions(0, 0))->isEmpty());
        self::assertTrue((new ImageDimensions(100, 0))->isEmpty());
        self::assertTrue((new ImageDimensions(0, 100))->isEmpty());
        self::assertFalse((new ImageDimensions(100, 100))->isEmpty());
    }

    #[Test]
    public function itDetectsHasWidth(): void
    {
        self::assertTrue((new ImageDimensions(100, 0))->hasWidth());
        self::assertFalse((new ImageDimensions(0, 100))->hasWidth());
    }

    #[Test]
    public function itDetectsHasHeight(): void
    {
        self::assertTrue((new ImageDimensions(0, 100))->hasHeight());
        self::assertFalse((new ImageDimensions(100, 0))->hasHeight());
    }

    #[Test]
    public function itDetectsHasBoth(): void
    {
        self::assertTrue((new ImageDimensions(100, 100))->hasBoth());
        self::assertFalse((new ImageDimensions(100, 0))->hasBoth());
        self::assertFalse((new ImageDimensions(0, 100))->hasBoth());
    }

    #[Test]
    public function itCalculatesArea(): void
    {
        $dims = new ImageDimensions(800, 600);

        self::assertSame(480000, $dims->area());
    }

    #[Test]
    public function itCalculatesAspectRatio(): void
    {
        $dims = new ImageDimensions(800, 600);

        self::assertEqualsWithDelta(1.333, $dims->aspectRatio(), 0.001);
    }

    #[Test]
    public function itReturnsZeroAspectRatioForZeroHeight(): void
    {
        $dims = new ImageDimensions(800, 0);

        self::assertSame(0.0, $dims->aspectRatio());
    }

    #[Test]
    public function itScalesToWidth(): void
    {
        $dims = new ImageDimensions(800, 600);
        $scaled = $dims->scaledToWidth(400);

        self::assertSame(400, $scaled->width());
        self::assertSame(300, $scaled->height());
    }

    #[Test]
    public function itScalesToHeight(): void
    {
        $dims = new ImageDimensions(800, 600);
        $scaled = $dims->scaledToHeight(300);

        self::assertSame(400, $scaled->width());
        self::assertSame(300, $scaled->height());
    }

    #[Test]
    public function itScalesToFit(): void
    {
        $dims = new ImageDimensions(1000, 500);

        // Constrained by width
        $scaled1 = $dims->scaledToFit(400, 400);
        self::assertSame(400, $scaled1->width());
        self::assertSame(200, $scaled1->height());

        // Constrained by height
        $scaled2 = $dims->scaledToFit(800, 200);
        self::assertSame(400, $scaled2->width());
        self::assertSame(200, $scaled2->height());
    }

    #[Test]
    public function itDoesNotUpscaleWhenFitting(): void
    {
        $dims = new ImageDimensions(200, 100);
        $scaled = $dims->scaledToFit(800, 600);

        self::assertSame(200, $scaled->width());
        self::assertSame(100, $scaled->height());
    }

    #[Test]
    public function itScalesToArea(): void
    {
        $dims = new ImageDimensions(1000, 1000); // Area = 1,000,000
        $scaled = $dims->scaledToArea(250000); // Max area = 250,000

        // Should scale to 500x500
        self::assertSame(500, $scaled->width());
        self::assertSame(500, $scaled->height());
        self::assertLessThanOrEqual(250000, $scaled->area());
    }

    #[Test]
    public function itDoesNotScaleWhenAreaWithinLimit(): void
    {
        $dims = new ImageDimensions(100, 100);
        $scaled = $dims->scaledToArea(100000);

        self::assertSame(100, $scaled->width());
        self::assertSame(100, $scaled->height());
    }

    #[Test]
    public function itCreatesNewInstanceWithWidth(): void
    {
        $dims = new ImageDimensions(800, 600);
        $newDims = $dims->withWidth(400);

        self::assertSame(400, $newDims->width());
        self::assertSame(600, $newDims->height());
        self::assertSame(800, $dims->width()); // Original unchanged
    }

    #[Test]
    public function itCreatesNewInstanceWithHeight(): void
    {
        $dims = new ImageDimensions(800, 600);
        $newDims = $dims->withHeight(300);

        self::assertSame(800, $newDims->width());
        self::assertSame(300, $newDims->height());
        self::assertSame(600, $dims->height()); // Original unchanged
    }

    #[Test]
    public function itComparesEquality(): void
    {
        $dims1 = new ImageDimensions(800, 600);
        $dims2 = new ImageDimensions(800, 600);
        $dims3 = new ImageDimensions(400, 300);

        self::assertTrue($dims1->equals($dims2));
        self::assertFalse($dims1->equals($dims3));
    }
}
