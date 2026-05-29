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

    public function testV2SingleLanguageObjectWithNonStringValueFallsThrough(): void
    {
        // `@value` not a string: not treated as v2 single object; no `[0]` either,
        // so it routes to v3 map which finds no string entries.
        $value = ['@value' => 123, '@language' => 'en'];
        self::assertSame('', LocalizedText::resolve($value, ['en']));
    }

    public function testV2ListDetectionRequiresAtValueKeyOnFirstEntry(): void
    {
        // `$value[0]` is an array but lacks `@value`, so the v2-list branch is
        // skipped and the input is treated as a v3 map (numeric key 0).
        $value = [['foo' => 'bar']];
        self::assertSame('', LocalizedText::resolve($value, ['en']));
    }

    public function testV2ListDetectionRequiresArrayFirstEntry(): void
    {
        // A numerically indexed list of scalars is not a v2 list — falls through
        // to the v3-map branch; numeric keys do not match any preferred code and
        // string values fail the `is_array($langValues)` fallback guard.
        $value = ['Hello', 'Hallo'];
        self::assertSame('', LocalizedText::resolve($value, ['en']));
    }

    public function testV2ListPreferredMatchWithNonStringValueIsSkipped(): void
    {
        // Preferred-language match exists but its `@value` is not a string;
        // the type guard must skip it and the fallback picks the next valid entry.
        $value = [
            ['@language' => 'en', '@value' => 123],
            ['@language' => 'de', '@value' => 'Hallo'],
        ];
        self::assertSame('Hallo', LocalizedText::resolve($value, ['en']));
    }

    public function testV2ListReturnsEmptyWhenNoEntryHasStringValue(): void
    {
        // Preferred miss, and the fallback loop finds no entry with a string
        // `@value` — final `return ''`.
        $value = [
            ['@value' => 123],
            ['@value' => null],
        ];
        self::assertSame('', LocalizedText::resolve($value, ['en']));
    }

    public function testV3MapPreferredCandidateNotArrayFallsThrough(): void
    {
        // `'en' => 'Hello'` (string, not list) fails the `is_array` guard;
        // fallback picks the first valid entry.
        $value = [
            'en' => 'Hello',
            'de' => ['Hallo'],
        ];
        self::assertSame('Hallo', LocalizedText::resolve($value, ['en']));
    }

    public function testV3MapPreferredCandidateWithNonStringFirstElement(): void
    {
        // `[0]` is not a string — guard skips it; fallback finds the next valid entry.
        $value = [
            'en' => [123],
            'de' => ['Hallo'],
        ];
        self::assertSame('Hallo', LocalizedText::resolve($value, ['en']));
    }

    public function testV3MapReturnsEmptyWhenNoEntryHasStringFirstElement(): void
    {
        // Neither preferred nor fallback finds a `string` at `[0]` — final `return ''`.
        $value = [
            'en' => 'Hello',
            'de' => [123],
            'fr' => [],
        ];
        self::assertSame('', LocalizedText::resolve($value, ['en']));
    }
}
