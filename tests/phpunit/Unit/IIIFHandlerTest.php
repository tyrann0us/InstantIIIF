<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF\Tests\Unit;

use MediaWiki\Extension\InstantIIIF\IIIFHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * AC 8: IIIFHandler parses and produces "page{N}-{W}px" param strings
 *        correctly, including the `page` parameter for multi-page docs.
 */
#[CoversClass(IIIFHandler::class)]
class IIIFHandlerTest extends TestCase
{
    private IIIFHandler $handler;

    protected function setUp(): void
    {
        $this->handler = new IIIFHandler();
    }

    // ─── getParamMap ───────────────────────────────────────────────

    public function testParamMapIncludesPageAndWidth(): void
    {
        $map = $this->handler->getParamMap();

        self::assertArrayHasKey('img_width', $map);
        self::assertArrayHasKey('img_page', $map);
        self::assertSame('width', $map['img_width']);
        self::assertSame('page', $map['img_page']);
    }

    // ─── makeParamString ───────────────────────────────────────────

    public function testMakeParamStringDefaultPage(): void
    {
        $result = $this->handler->makeParamString(['width' => 800]);
        self::assertSame('page1-800px', $result);
    }

    public function testMakeParamStringExplicitPage(): void
    {
        $result = $this->handler->makeParamString(['width' => 600, 'page' => 3]);
        self::assertSame('page3-600px', $result);
    }

    public function testMakeParamStringReturnsFalseWithoutWidth(): void
    {
        $result = $this->handler->makeParamString(['page' => 2]);
        self::assertFalse($result);
    }

    // ─── parseParamString ──────────────────────────────────────────

    /**
     * @param array{width: int, page: int} $expected
     */
    #[DataProvider('parseParamStringProvider')]
    public function testParseParamString(string $input, array $expected): void
    {
        $result = $this->handler->parseParamString($input);
        self::assertSame($expected, $result);
    }

    /**
     * @return array<string, array{string, array{width: int, page: int}}>
     */
    public static function parseParamStringProvider(): array
    {
        return [
            'page 1, 800px' => ['page1-800px', ['width' => 800, 'page' => 1]],
            'page 3, 600px' => ['page3-600px', ['width' => 600, 'page' => 3]],
            'page 12, 1200px' => ['page12-1200px', ['width' => 1200, 'page' => 12]],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidParamStringProvider(): array
    {
        return [
            'no page prefix' => ['800px'],
            'wrong format' => ['800x600'],
            'empty' => [''],
            'missing px suffix' => ['page1-800'],
            'negative page' => ['page-1-800px'],
        ];
    }

    #[DataProvider('invalidParamStringProvider')]
    public function testParseParamStringReturnsFalseForInvalid(string $input): void
    {
        self::assertFalse($this->handler->parseParamString($input));
    }

    /**
     * Roundtrip: makeParamString → parseParamString must be lossless.
     */
    public function testMakeAndParseRoundtrip(): void
    {
        $params = ['width' => 1024, 'page' => 5];
        $str = $this->handler->makeParamString($params);
        self::assertIsString($str);
        $parsed = $this->handler->parseParamString($str);
        self::assertSame($params, $parsed);
    }

    // ─── validateParam ─────────────────────────────────────────────

    /**
     * @return array<string, array{string, mixed, bool}>
     */
    public static function validateParamProvider(): array
    {
        return [
            'width positive' => ['width', 800, true],
            'width zero' => ['width', 0, false],
            'width negative' => ['width', -1, false],
            'page positive' => ['page', 3, true],
            'page zero' => ['page', 0, false],
            'page string integer' => ['page', '3', true],
            'page float-like string' => ['page', '3.5', false],
            'page text caption' => ['page', 'Some caption text', false],
            'height positive' => ['height', 600, true],
            'unknown param' => ['quality', 80, false],
        ];
    }

    #[DataProvider('validateParamProvider')]
    public function testValidateParam(string $name, mixed $value, bool $expected): void
    {
        self::assertSame($expected, $this->handler->validateParam($name, $value));
    }

    // ─── Other handler properties ──────────────────────────────────

    public function testMustRenderReturnsTrue(): void
    {
        $file = $this->createMock(\File::class);
        self::assertTrue($this->handler->mustRender($file));
    }

    public function testIsExpensiveToThumbnailReturnsFalse(): void
    {
        $file = $this->createMock(\File::class);
        self::assertFalse($this->handler->isExpensiveToThumbnail($file));
    }

    public function testGetSizeAndMetadataReturnsEmptyArray(): void
    {
        self::assertSame([], $this->handler->getSizeAndMetadata(null, ''));
    }
}
