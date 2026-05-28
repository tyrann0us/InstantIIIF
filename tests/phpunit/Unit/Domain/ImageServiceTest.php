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
}
