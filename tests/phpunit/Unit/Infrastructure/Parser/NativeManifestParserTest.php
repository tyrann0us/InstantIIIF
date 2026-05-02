<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF\Tests\Unit\Infrastructure\Parser;

use MediaWiki\Extension\InstantIIIF\Domain\ValueObject\ServiceId;
use MediaWiki\Extension\InstantIIIF\Infrastructure\Parser\NativeManifestParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NativeManifestParser::class)]
final class NativeManifestParserTest extends TestCase
{
    private NativeManifestParser $parser;
    private string $fixturesPath;

    protected function setUp(): void
    {
        $this->parser = new NativeManifestParser();
        $this->fixturesPath = __DIR__ . '/../../../Fixtures';
    }

    #[Test]
    public function itSupportsV2Manifest(): void
    {
        $json = $this->loadFixture('manifest-v2.json');

        self::assertTrue($this->parser->supports($json));
    }

    #[Test]
    public function itSupportsV3Manifest(): void
    {
        $json = $this->loadFixture('manifest-v3.json');

        self::assertTrue($this->parser->supports($json));
    }

    #[Test]
    public function itDoesNotSupportInvalidJson(): void
    {
        self::assertFalse($this->parser->supports('not valid json'));
        self::assertFalse($this->parser->supports(''));
        self::assertFalse($this->parser->supports('{"foo": "bar"}'));
    }

    #[Test]
    public function itExtractsServiceIdsFromV2Manifest(): void
    {
        $json = $this->loadFixture('manifest-v2.json');
        $serviceIds = $this->parser->extractServiceIds($json);

        self::assertCount(2, $serviceIds);
        self::assertInstanceOf(ServiceId::class, $serviceIds[0]);
        self::assertSame('https://example.org/iiif/image/12345', $serviceIds[0]->asString());
        self::assertSame('https://example.org/iiif/image/12346', $serviceIds[1]->asString());
    }

    #[Test]
    public function itExtractsServiceIdsFromV3Manifest(): void
    {
        $json = $this->loadFixture('manifest-v3.json');
        $serviceIds = $this->parser->extractServiceIds($json);

        self::assertCount(2, $serviceIds);
        self::assertInstanceOf(ServiceId::class, $serviceIds[0]);
        self::assertSame('https://example.org/iiif/image/67890', $serviceIds[0]->asString());
        self::assertSame('https://example.org/iiif/image/67891', $serviceIds[1]->asString());
    }

    #[Test]
    public function itExtractsCanvasDimensionsFromV2Manifest(): void
    {
        $json = $this->loadFixture('manifest-v2.json');
        $dimensions = $this->parser->extractCanvasDimensions($json);

        self::assertCount(2, $dimensions);
        self::assertSame(2000, $dimensions[0]->width());
        self::assertSame(3000, $dimensions[0]->height());
        self::assertSame(1800, $dimensions[1]->width());
        self::assertSame(2700, $dimensions[1]->height());
    }

    #[Test]
    public function itExtractsCanvasDimensionsFromV3Manifest(): void
    {
        $json = $this->loadFixture('manifest-v3.json');
        $dimensions = $this->parser->extractCanvasDimensions($json);

        self::assertCount(2, $dimensions);
        self::assertSame(4000, $dimensions[0]->width());
        self::assertSame(6000, $dimensions[0]->height());
        self::assertSame(3500, $dimensions[1]->width());
        self::assertSame(5250, $dimensions[1]->height());
    }

    #[Test]
    public function itExtractsHomepageFromV2Related(): void
    {
        $json = $this->loadFixture('manifest-v2.json');
        $homepage = $this->parser->extractHomepageUrl($json);

        self::assertSame('https://example.org/viewer/12345', $homepage);
    }

    #[Test]
    public function itExtractsHomepageFromV3Homepage(): void
    {
        $json = $this->loadFixture('manifest-v3.json');
        $homepage = $this->parser->extractHomepageUrl($json);

        self::assertSame('https://example.org/viewer/67890', $homepage);
    }

    #[Test]
    public function itCountsCanvasesInV2Manifest(): void
    {
        $json = $this->loadFixture('manifest-v2.json');

        self::assertSame(2, $this->parser->getCanvasCount($json));
    }

    #[Test]
    public function itCountsCanvasesInV3Manifest(): void
    {
        $json = $this->loadFixture('manifest-v3.json');

        self::assertSame(2, $this->parser->getCanvasCount($json));
    }

    #[Test]
    public function itReturnsEmptyArraysForInvalidJson(): void
    {
        self::assertSame([], $this->parser->extractServiceIds('invalid'));
        self::assertSame([], $this->parser->extractCanvasDimensions('invalid'));
        self::assertNull($this->parser->extractHomepageUrl('invalid'));
        self::assertSame(0, $this->parser->getCanvasCount('invalid'));
    }

    #[Test]
    public function itHandlesManifestWithStringRelated(): void
    {
        $json = json_encode([
            '@type' => 'sc:Manifest',
            'related' => 'https://example.org/related',
            'sequences' => [['canvases' => []]],
        ]);

        self::assertSame('https://example.org/related', $this->parser->extractHomepageUrl($json));
    }

    #[Test]
    public function itHandlesManifestWithArrayRelated(): void
    {
        $json = json_encode([
            '@type' => 'sc:Manifest',
            'related' => [
                ['@id' => 'https://example.org/related-array'],
            ],
            'sequences' => [['canvases' => []]],
        ]);

        self::assertSame('https://example.org/related-array', $this->parser->extractHomepageUrl($json));
    }

    #[Test]
    public function itHandlesServiceAsArray(): void
    {
        $json = json_encode([
            '@type' => 'sc:Manifest',
            'sequences' => [[
                'canvases' => [[
                    'width' => 100,
                    'height' => 100,
                    'images' => [[
                        'resource' => [
                            'service' => [
                                ['@id' => 'https://example.org/iiif/image/123'],
                            ],
                        ],
                    ],],
                ],],
            ],],
        ]);

        $serviceIds = $this->parser->extractServiceIds($json);

        self::assertCount(1, $serviceIds);
        self::assertSame('https://example.org/iiif/image/123', $serviceIds[0]->asString());
    }

    private function loadFixture(string $filename): string
    {
        $path = $this->fixturesPath . '/' . $filename;
        $content = file_get_contents($path);

        if ($content === false) {
            self::fail("Could not load fixture: {$filename}");
        }

        return $content;
    }
}
