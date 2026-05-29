<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF\Tests\Unit\Domain;

use MediaWiki\Extension\InstantIIIF\Domain\ImageService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ImageService::class)]
class ImageServiceTest extends TestCase
{
    private const BASE = 'https://example.org/iiif/2/foo';

    public function testFullUrlForStaticDoesNotNeedInfoJson(): void
    {
        self::assertSame(
            self::BASE . '/full/full/0/default.jpg',
            ImageService::fullUrlFor(self::BASE)
        );
    }

    public function testFullUrlForStripsTrailingSlash(): void
    {
        self::assertSame(
            self::BASE . '/full/full/0/default.jpg',
            ImageService::fullUrlFor(self::BASE . '/')
        );
    }

    public function testFromInfoJsonStripsTrailingSlash(): void
    {
        $service = ImageService::fromInfoJson(self::BASE . '/', [
            'width' => 1000,
            'height' => 800,
        ]);
        self::assertSame(self::BASE, $service->baseUrl);
    }

    public function testOriginalDimsParsed(): void
    {
        $service = ImageService::fromInfoJson(self::BASE, [
            'width' => 5000,
            'height' => 7000,
        ]);
        self::assertSame(5000, $service->originalDims->width);
        self::assertSame(7000, $service->originalDims->height);
    }

    public function testFullUrlInstance(): void
    {
        $service = ImageService::fromInfoJson(self::BASE, ['width' => 1, 'height' => 1]);
        self::assertSame(self::BASE . '/full/full/0/default.jpg', $service->fullUrl());
    }

    public function testSizedUrlBothDimensions(): void
    {
        $service = ImageService::fromInfoJson(self::BASE, []);
        self::assertSame(
            self::BASE . '/full/800,600/0/default.jpg',
            $service->sizedUrl(800, 600)
        );
    }

    public function testSizedUrlWidthOnly(): void
    {
        $service = ImageService::fromInfoJson(self::BASE, []);
        self::assertSame(
            self::BASE . '/full/800,/0/default.jpg',
            $service->sizedUrl(800, 0)
        );
    }

    public function testSizedUrlHeightOnly(): void
    {
        $service = ImageService::fromInfoJson(self::BASE, []);
        self::assertSame(
            self::BASE . '/full/,600/0/default.jpg',
            $service->sizedUrl(0, 600)
        );
    }

    public function testSizedUrlZeroFallsBackToFull(): void
    {
        $service = ImageService::fromInfoJson(self::BASE, []);
        self::assertSame($service->fullUrl(), $service->sizedUrl(0, 0));
    }

    public function testClampWithoutLimitsReturnsInput(): void
    {
        $service = ImageService::fromInfoJson(self::BASE, []);
        self::assertSame([1000, 800], $service->clamp(1000, 800));
    }

    public function testClampWidthAgainstMaxWidth(): void
    {
        $service = ImageService::fromInfoJson(self::BASE, [
            'width' => 5000,
            'height' => 4000,
            'maxWidth' => 1000,
        ]);
        // Width-only clamp.
        self::assertSame([1000, 0], $service->clamp(2000, 0));
    }

    public function testClampHeightAgainstMaxHeight(): void
    {
        $service = ImageService::fromInfoJson(self::BASE, [
            'width' => 5000,
            'height' => 4000,
            'maxHeight' => 800,
        ]);
        self::assertSame([0, 800], $service->clamp(0, 2000));
    }

    public function testClampBothScalesProportionally(): void
    {
        $service = ImageService::fromInfoJson(self::BASE, [
            'width' => 5000,
            'height' => 4000,
            'maxWidth' => 1000,
            'maxHeight' => 1000,
        ]);
        // Asking for 2000×1600 — scale down to fit maxWidth, height scales too.
        [$w, $h] = $service->clamp(2000, 1600);
        self::assertSame(1000, $w);
        self::assertSame(800, $h);
    }

    public function testClampHonoursMaxArea(): void
    {
        $service = ImageService::fromInfoJson(self::BASE, [
            'width' => 10000,
            'height' => 10000,
            'maxArea' => 1_000_000, // 1MP cap
        ]);
        [$w, $h] = $service->clamp(2000, 2000); // 4MP — must scale down.
        self::assertLessThanOrEqual(1_000_000, $w * $h);
        self::assertGreaterThan(0, $w);
        self::assertGreaterThan(0, $h);
    }

    public function testSizedUrlAppliesClamp(): void
    {
        $service = ImageService::fromInfoJson(self::BASE, [
            'width' => 5000,
            'height' => 4000,
            'maxWidth' => 1000,
        ]);
        // Asked for 2000-wide; service caps at 1000. URL must reflect cap.
        self::assertSame(
            self::BASE . '/full/1000,/0/default.jpg',
            $service->sizedUrl(2000, 0)
        );
    }

    public function testClampZeroByZeroReturnsZeroPair(): void
    {
        $service = ImageService::fromInfoJson(self::BASE, [
            'width' => 5000,
            'height' => 4000,
            'maxWidth' => 1000,
            'maxHeight' => 1000,
            'maxArea' => 1_000_000,
        ]);
        // clamp(0, 0) short-circuits before any limit is consulted.
        self::assertSame([0, 0], $service->clamp(0, 0));
    }

    public function testClampWidthOnlyShrinksToMaxAreaSqrt(): void
    {
        $service = ImageService::fromInfoJson(self::BASE, [
            'width' => 10000,
            'height' => 10000,
            'maxArea' => 1_000_000, // 1MP cap, square original.
        ]);
        [$w, $h] = $service->clamp(2000, 0);
        // Estimated height equals width for a square source, so 2000*2000 > 1MP triggers shrink.
        // sqrt(1MP * 10000/10000) = 1000.
        self::assertSame(1000, $w);
        self::assertSame(0, $h);
    }

    public function testClampHeightOnlyShrinksToMaxAreaSqrt(): void
    {
        $service = ImageService::fromInfoJson(self::BASE, [
            'width' => 10000,
            'height' => 10000,
            'maxArea' => 1_000_000,
        ]);
        [$w, $h] = $service->clamp(0, 2000);
        self::assertSame(0, $w);
        self::assertSame(1000, $h);
    }

    public function testClampSkipsMaxAreaWhenOriginalDimsMissing(): void
    {
        // maxArea is set, but width/height absent → maxArea cannot be applied.
        $service = ImageService::fromInfoJson(self::BASE, [
            'maxArea' => 1_000_000,
        ]);
        self::assertSame([2000, 2000], $service->clamp(2000, 2000));
    }

    public function testClampLeavesAreaUnchangedWhenUnderCap(): void
    {
        $service = ImageService::fromInfoJson(self::BASE, [
            'width' => 10000,
            'height' => 10000,
            'maxArea' => 4_000_000,
        ]);
        // 500*500 = 250k well under 4MP cap → returned unchanged.
        self::assertSame([500, 500], $service->clamp(500, 500));
    }

    public function testClampBothWithinLimitsReturnsInput(): void
    {
        $service = ImageService::fromInfoJson(self::BASE, [
            'width' => 5000,
            'height' => 4000,
            'maxWidth' => 2000,
            'maxHeight' => 2000,
        ]);
        // Both dims already under maxWidth/maxHeight → scale stays 1.0.
        self::assertSame([800, 600], $service->clamp(800, 600));
    }

    public function testClampBothScalesByBindingHeight(): void
    {
        $service = ImageService::fromInfoJson(self::BASE, [
            'width' => 5000,
            'height' => 4000,
            'maxWidth' => 2000,
            'maxHeight' => 500,
        ]);
        // 1000x2000 — maxHeight/height = 0.25 is tighter than maxWidth/width = 2.0.
        [$w, $h] = $service->clamp(1000, 2000);
        self::assertSame(250, $w);
        self::assertSame(500, $h);
    }

    public function testClampWidthOnlyWithoutMaxWidthReturnsInput(): void
    {
        // No width/height/maxWidth in info.json → maxWidth defaults to 0 (unset).
        $service = ImageService::fromInfoJson(self::BASE, []);
        self::assertSame([2000, 0], $service->clamp(2000, 0));
    }

    public function testClampWidthOnlyUnderMaxWidthReturnsInput(): void
    {
        $service = ImageService::fromInfoJson(self::BASE, [
            'width' => 5000,
            'height' => 4000,
            'maxWidth' => 3000,
        ]);
        // 1000 ≤ maxWidth 3000 → no clamp.
        self::assertSame([1000, 0], $service->clamp(1000, 0));
    }

    public function testClampHeightOnlyWithoutMaxHeightReturnsInput(): void
    {
        $service = ImageService::fromInfoJson(self::BASE, []);
        self::assertSame([0, 2000], $service->clamp(0, 2000));
    }

    public function testClampHeightOnlyUnderMaxHeightReturnsInput(): void
    {
        $service = ImageService::fromInfoJson(self::BASE, [
            'width' => 5000,
            'height' => 4000,
            'maxHeight' => 3000,
        ]);
        self::assertSame([0, 1000], $service->clamp(0, 1000));
    }
}
