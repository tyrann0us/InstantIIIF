<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF\Tests\Unit;

use MediaWiki\Extension\InstantIIIF\Infrastructure\MediaWiki\IIIFTitle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for IIIFTitle: spoof/unspoof for the only extension we ever add (.jpg).
 */
#[CoversClass(IIIFTitle::class)]
class IIIFTitleTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function unspoofProvider(): array
    {
        return [
            'lowercase .jpg' => ['Df_dk_0007450.jpg', 'Df_dk_0007450'],
            'uppercase .JPG' => ['Df_dk_0007450.JPG', 'Df_dk_0007450'],
            'no extension' => ['Df_dk_0007450', 'Df_dk_0007450'],
            // Only the trailing .jpg is stripped; other suffixes pass through.
            'unrecognised .png' => ['Df_dk_0007450.png', 'Df_dk_0007450.png'],
            'unrecognised .tif' => ['Df_dk_0007450.tif', 'Df_dk_0007450.tif'],
            'strips one .jpg, preserves prior extension' => [
                'Df_dk_0007450.tif.jpg',
                'Df_dk_0007450.tif',
            ],
            'empty' => ['', ''],
        ];
    }

    #[DataProvider('unspoofProvider')]
    public function testUnspoofStripsOnlyJpg(string $input, string $expected): void
    {
        self::assertSame($expected, IIIFTitle::unspoof($input));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function spoofProvider(): array
    {
        return [
            'extension-less ID' => ['Bsb11610364', 'Bsb11610364.jpg'],
            'ID with underscore' => ['Df_dk_0007450', 'Df_dk_0007450.jpg'],
            'already .jpg' => ['Foo.jpg', 'Foo.jpg'],
            'already uppercase .JPG' => ['Foo.JPG', 'Foo.JPG'],
            'shelfmark with dash' => ['1741646995-18800000', '1741646995-18800000.jpg'],
            // Only .jpg is recognised — a .png suffix gets a .jpg appended.
            'ID with unrecognised .png suffix' => ['foo.png', 'foo.png.jpg'],
        ];
    }

    #[DataProvider('spoofProvider')]
    public function testSpoofAppendsJpgWhenMissing(string $input, string $expected): void
    {
        self::assertSame($expected, IIIFTitle::spoof($input));
    }

    public function testSpoofIsIdempotent(): void
    {
        $spoofed = IIIFTitle::spoof('Bsb11610364');
        self::assertSame($spoofed, IIIFTitle::spoof($spoofed));
    }

    public function testUnspoofIsIdempotent(): void
    {
        $unspoofed = IIIFTitle::unspoof('Df_dk_0007450.jpg');
        self::assertSame($unspoofed, IIIFTitle::unspoof($unspoofed));
    }

    public function testSpoofUnspoofRoundtripsForExtensionlessId(): void
    {
        self::assertSame('Bsb11610364', IIIFTitle::unspoof(IIIFTitle::spoof('Bsb11610364')));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function isSpoofedProvider(): array
    {
        return [
            'spoofed jpg' => ['Foo.jpg', true],
            'spoofed JPG' => ['Foo.JPG', true],
            'no extension' => ['Bsb11610364', false],
            'unrecognised .png' => ['Foo.png', false],
            'unrecognised .tif' => ['Foo.tif', false],
            'empty' => ['', false],
        ];
    }

    #[DataProvider('isSpoofedProvider')]
    public function testIsSpoofedRecognisesOnlyJpg(string $input, bool $expected): void
    {
        self::assertSame($expected, IIIFTitle::isSpoofed($input));
    }

    public function testSpoofExtensionConstant(): void
    {
        self::assertSame('jpg', IIIFTitle::SPOOF_EXTENSION);
    }
}
