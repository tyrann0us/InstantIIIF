<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF\Tests\Unit\Domain;

use MediaWiki\Extension\InstantIIIF\Domain\Page;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Page::class)]
class PageTest extends TestCase
{
    /**
     * @return array<string, array{mixed, int}>
     */
    public static function normalizeProvider(): array
    {
        return [
            'integer 5' => [5, 5],
            'string "5"' => ['5', 5],
            'zero' => [0, 1],
            'negative' => [-3, 1],
            'string negative' => ['-7', 1],
            'non-numeric string' => ['Some caption text', 1],
            'float-like string' => ['3.5', 3],
            'empty string' => ['', 1],
            'null' => [null, 1],
        ];
    }

    /**
     * Page::normalize underpins every page-aware code path; junky inputs
     * from wikitext / URL params all settle on page 1.
     *
     * @param mixed $input
     */
    #[DataProvider('normalizeProvider')]
    public function testNormalize(mixed $input, int $expected): void
    {
        self::assertSame($expected, Page::normalize($input)->value);
    }

    public function testZeroIndexed(): void
    {
        self::assertSame(0, Page::normalize(1)->zeroIndexed());
        self::assertSame(4, Page::normalize(5)->zeroIndexed());
    }
}
