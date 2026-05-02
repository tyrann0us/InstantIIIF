<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF\Tests\Unit\Domain\ValueObject;

use MediaWiki\Extension\InstantIIIF\Domain\ValueObject\ObjectId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ObjectId::class)]
final class ObjectIdTest extends TestCase
{
    #[Test]
    public function itCreatesFromValidString(): void
    {
        $objectId = new ObjectId('df_bs_0007727_postkarte');

        self::assertSame('df_bs_0007727_postkarte', $objectId->asString());
    }

    #[Test]
    public function itNormalizesToLowerCaseFirst(): void
    {
        $objectId = new ObjectId('Df_bs_0007727');

        self::assertSame('df_bs_0007727', $objectId->asString());
    }

    #[Test]
    #[DataProvider('provideImageExtensions')]
    public function itRemovesSpoofedImageExtensions(string $input, string $expected): void
    {
        $objectId = new ObjectId($input);

        self::assertSame($expected, $objectId->asString());
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function provideImageExtensions(): array
    {
        return [
            'jpg extension' => ['df_bs_0007727.jpg', 'df_bs_0007727'],
            'jpeg extension' => ['df_bs_0007727.jpeg', 'df_bs_0007727'],
            'png extension' => ['df_bs_0007727.png', 'df_bs_0007727'],
            'gif extension' => ['df_bs_0007727.gif', 'df_bs_0007727'],
            'webp extension' => ['df_bs_0007727.webp', 'df_bs_0007727'],
            'uppercase extension' => ['df_bs_0007727.JPG', 'df_bs_0007727'],
            'mixed case extension' => ['df_bs_0007727.Jpg', 'df_bs_0007727'],
            'no extension' => ['df_bs_0007727', 'df_bs_0007727'],
            'extension in middle' => ['df.jpg.test', 'df.jpg.test'],
        ];
    }

    #[Test]
    public function itThrowsOnEmptyString(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Object ID cannot be empty');

        new ObjectId('');
    }

    #[Test]
    public function itThrowsOnWhitespaceOnly(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ObjectId('   ');
    }

    #[Test]
    public function itThrowsWhenExtensionRemovalLeavesEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ObjectId('.jpg');
    }

    #[Test]
    public function itMatchesPattern(): void
    {
        $objectId = new ObjectId('df_bs_0007727');

        self::assertTrue($objectId->matchesPattern('^[a-z0-9_]+$'));
        self::assertFalse($objectId->matchesPattern('^[A-Z]+$'));
    }

    #[Test]
    public function itMatchesPatternWithSlashDelimiters(): void
    {
        $objectId = new ObjectId('df_bs_0007727');

        self::assertTrue($objectId->matchesPattern('/^df_/'));
        self::assertFalse($objectId->matchesPattern('/^xyz/'));
    }

    #[Test]
    public function itComparesEquality(): void
    {
        $objectId1 = new ObjectId('df_bs_0007727');
        $objectId2 = new ObjectId('df_bs_0007727');
        $objectId3 = new ObjectId('df_bs_other');

        self::assertTrue($objectId1->equals($objectId2));
        self::assertFalse($objectId1->equals($objectId3));
    }

    #[Test]
    public function itCreatesFromDbKey(): void
    {
        $objectId = ObjectId::fromDbKey('Df_bs_0007727.jpg');

        self::assertSame('df_bs_0007727', $objectId->asString());
    }
}
