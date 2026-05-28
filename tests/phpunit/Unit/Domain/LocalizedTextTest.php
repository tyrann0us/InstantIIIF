<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF\Tests\Unit\Domain;

use MediaWiki\Extension\InstantIIIF\Domain\LocalizedText;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LocalizedText::class)]
class LocalizedTextTest extends TestCase
{
    public function testPlainStringReturnsItself(): void
    {
        self::assertSame('Foo', LocalizedText::resolve('Foo', ['en']));
    }

    public function testNonArrayNonStringReturnsEmpty(): void
    {
        self::assertSame('', LocalizedText::resolve(123, ['en']));
        self::assertSame('', LocalizedText::resolve(null, ['en']));
        self::assertSame('', LocalizedText::resolve(false, ['en']));
    }

    public function testV2SingleLanguageObject(): void
    {
        $value = ['@value' => 'Hello', '@language' => 'en'];
        self::assertSame('Hello', LocalizedText::resolve($value, []));
    }

    public function testV2ListPicksPreferredLanguage(): void
    {
        $value = [
            ['@language' => 'de', '@value' => 'Hallo'],
            ['@language' => 'en', '@value' => 'Hello'],
            ['@language' => 'fr', '@value' => 'Bonjour'],
        ];
        self::assertSame('Hello', LocalizedText::resolve($value, ['en', 'de']));
        self::assertSame('Hallo', LocalizedText::resolve($value, ['de', 'en']));
    }

    public function testV2ListFallsBackToFirstWhenNoMatch(): void
    {
        $value = [
            ['@language' => 'de', '@value' => 'Hallo'],
            ['@language' => 'fr', '@value' => 'Bonjour'],
        ];
        self::assertSame('Hallo', LocalizedText::resolve($value, ['ja', 'ko']));
    }

    public function testV3LanguageMapPicksPreferredLanguage(): void
    {
        $value = [
            'de' => ['Hallo'],
            'en' => ['Hello'],
        ];
        self::assertSame('Hello', LocalizedText::resolve($value, ['en']));
        self::assertSame('Hallo', LocalizedText::resolve($value, ['de']));
    }

    public function testV3LanguageMapFallsBackToFirstWhenNoMatch(): void
    {
        $value = [
            'de' => ['Hallo'],
            'fr' => ['Bonjour'],
        ];
        $result = LocalizedText::resolve($value, ['ja']);
        // First value present.
        self::assertContains($result, ['Hallo', 'Bonjour']);
    }

    public function testEmptyPreferredListStillResolves(): void
    {
        $value = ['en' => ['Hello']];
        self::assertSame('Hello', LocalizedText::resolve($value, []));
    }

    public function testEmptyValueReturnsEmpty(): void
    {
        self::assertSame('', LocalizedText::resolve('', ['en']));
        self::assertSame('', LocalizedText::resolve([], ['en']));
    }
}
