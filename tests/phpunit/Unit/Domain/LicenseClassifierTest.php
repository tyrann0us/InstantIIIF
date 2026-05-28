<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF\Tests\Unit\Domain;

use MediaWiki\Extension\InstantIIIF\Domain\LicenseClassifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(LicenseClassifier::class)]
class LicenseClassifierTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function shortNameProvider(): array
    {
        return [
            'CC BY 4.0' => ['https://creativecommons.org/licenses/by/4.0/', 'CC BY 4.0'],
            'CC BY-SA 3.0' => ['https://creativecommons.org/licenses/by-sa/3.0/', 'CC BY-SA 3.0'],
            'CC BY-NC-ND 2.0' => ['https://creativecommons.org/licenses/by-nc-nd/2.0/', 'CC BY-NC-ND 2.0'],
            'CC0' => ['https://creativecommons.org/publicdomain/zero/1.0/', 'CC0'],
            'Public Domain Mark' => ['https://creativecommons.org/publicdomain/mark/1.0/', 'Public Domain'],
            'RightsStatements InC' => ['https://rightsstatements.org/vocab/InC/1.0/', 'InC'],
            'RightsStatements NoC-US' => ['https://rightsstatements.org/vocab/NoC-US/1.0/', 'NoC US'],
            'Unknown URL' => ['https://example.org/license', ''],
            'Empty string' => ['', ''],
        ];
    }

    #[DataProvider('shortNameProvider')]
    public function testShortNameFor(string $url, string $expected): void
    {
        self::assertSame($expected, LicenseClassifier::shortNameFor($url));
    }
}
