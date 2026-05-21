<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF\Tests\Unit;

use MediaTransformError;
use MediaWiki\Extension\InstantIIIF\IIIFFile;
use MediaWiki\Extension\InstantIIIF\Repo;
use MediaWiki\Title\Title;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ThumbnailImage;

/**
 * Tests for IIIFFile: manifest resolution, URL building, multi-page,
 * getDescriptionUrl, getProviderUrl, transform.
 *
 * Covers AC 2, 4, 6, 7, 10, 15, 16.
 */
#[CoversClass(IIIFFile::class)]
class IIIFFileTest extends TestCase
{
    private const FIXTURES_DIR = __DIR__ . '/../Fixtures/';

    // ─── Helpers ───────────────────────────────────────────────────

    /**
     * Create a testable IIIFFile sub-class that injects manifest JSON
     * without hitting the network.
     *
     * @param array<string, mixed>|null $manifestRaw  Decoded manifest (null = resolution fails)
     * @param string                    $provider     Provider ID
     * @param string                    $objectId     Object identifier
     * @param string                    $dbKey        Title DB key
     * @param string                    $nsText       Namespace text (e.g. "Datei", "File")
     */
    private function makeFile(
        ?array $manifestRaw,
        string $provider = 'deutsche-fotothek',
        string $objectId = 'df_dk_0007450',
        string $dbKey = 'Df_dk_0007450.jpg',
        string $nsText = 'Datei',
    ): IIIFFile {

        $title = new Title($dbKey, NS_FILE, $nsText);
        $repo = $this->createMock(Repo::class);
        $repo->method('iiifSources')->willReturn([
            [
                'id' => $provider,
                'manifestPattern' => 'https://fotothek.example/$1/manifest.json',
            ],
        ]);

        return new class ($repo, $title, $manifestRaw, $provider, $objectId) extends IIIFFile {
            private ?array $injectedManifest;
            private string $injectedProvider;
            private string $injectedObjectId;

            public function __construct(
                Repo $repo,
                Title $title,
                ?array $manifest,
                string $provider,
                string $objectId
            ) {

                parent::__construct($repo, $title);
                $this->injectedManifest = $manifest;
                $this->injectedProvider = $provider;
                $this->injectedObjectId = $objectId;
            }

            protected function ensureResolved(): ?array
            {
                if ($this->resolved !== null) {
                    return $this->resolved;
                }
                if ($this->injectedManifest === null) {
                    return null;
                }
                $this->resolved = [
                    'provider' => $this->injectedProvider,
                    'objectId' => $this->injectedObjectId,
                    'manifestUrl' => 'https://fotothek.example/' . $this->injectedObjectId . '/manifest.json',
                    'manifestRaw' => $this->injectedManifest,
                ];
                return $this->resolved;
            }

            protected function ensureInfoJsonFor(string $serviceId): array
            {
                // Return empty — canvas dimensions are used instead in tests.
                return [];
            }
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function loadFixture(string $name): array
    {
        $path = self::FIXTURES_DIR . $name;
        $json = file_get_contents($path);
        self::assertIsString($json, "Fixture {$name} not found");
        $data = json_decode($json, true);
        self::assertIsArray($data);
        return $data;
    }

    // ─── AC 2: getDescriptionUrl → local wiki URL ─────────────────

    public function testGetDescriptionUrlReturnsLocalWikiUrl(): void
    {
        $manifest = $this->loadFixture('manifest-fotothek-v2.json');
        $file = $this->makeFile($manifest);

        $url = $file->getDescriptionUrl();

        // Must be a full URL (with protocol), not a relative path.
        self::assertStringStartsWith('https://', $url);
        // Must contain the file page path, not the IIIF provider.
        self::assertStringContainsString('Datei:Df_dk_0007450.jpg', $url);
        self::assertStringNotContainsString('fotothek.slub-dresden.de', $url);
    }

    public function testGetDescriptionShortUrlMatchesDescriptionUrl(): void
    {
        $manifest = $this->loadFixture('manifest-fotothek-v2.json');
        $file = $this->makeFile($manifest);

        self::assertSame($file->getDescriptionUrl(), $file->getDescriptionShortUrl());
    }

    public function testGetDescriptionUrlReturnsEmptyWithoutTitle(): void
    {
        // Construct file with null title scenario
        $repo = $this->createMock(Repo::class);
        $repo->method('iiifSources')->willReturn([]);

        $file = new class ($repo) extends IIIFFile {
            public function __construct(Repo $repo)
            {
                // Intentionally skip parent constructor to keep title null
                $this->repo = $repo;
            }

            public function getTitle(): ?Title
            {
                return null;
            }

            protected function ensureResolved(): ?array
            {
                return null;
            }
        };

        self::assertSame('', $file->getDescriptionUrl());
    }

    // ─── AC 4: getProviderUrl → provider landing page ─────────────

    public function testGetProviderUrlFromFotothekMetadata(): void
    {
        $manifest = $this->loadFixture('manifest-fotothek-v2.json');
        $file = $this->makeFile($manifest);

        $url = $file->getProviderUrl();

        self::assertSame('https://www.deutschefotothek.de/documents/obj/12345678', $url);
    }

    public function testGetProviderUrlFromV2Related(): void
    {
        $manifest = $this->loadFixture('manifest-slub-v2.json');
        // SLUB provider doesn't have LANDING_META_KEYS, so it falls back to `related`
        $file = $this->makeFile($manifest, 'slub', 'slub_obj_001', 'Slub_obj_001.jpg');

        $url = $file->getProviderUrl();

        self::assertSame('https://digital.slub-dresden.de/werkansicht/dlf/12345', $url);
    }

    public function testGetProviderUrlFromV2RelatedString(): void
    {
        $manifest = $this->loadFixture('manifest-bsb-v2.json');
        $file = $this->makeFile($manifest, 'bsb', 'bsb10000001', 'Bsb10000001.jpg');

        $url = $file->getProviderUrl();

        self::assertSame('https://www.digitale-sammlungen.de/de/view/bsb10000001', $url);
    }

    public function testGetProviderUrlFromV3Homepage(): void
    {
        $manifest = $this->loadFixture('manifest-v3.json');
        $file = $this->makeFile($manifest, 'v3-provider', 'img001', 'Img001.jpg');

        $url = $file->getProviderUrl();

        self::assertSame('https://example.org/object/12345', $url);
    }

    public function testGetProviderUrlReturnsEmptyWhenUnresolved(): void
    {
        $file = $this->makeFile(null);

        self::assertSame('', $file->getProviderUrl());
    }

    // ─── AC 6, 7: Multi-page support ──────────────────────────────

    public function testSinglePageDocumentIsNotMultipage(): void
    {
        $manifest = $this->loadFixture('manifest-fotothek-v2.json');
        $file = $this->makeFile($manifest);

        self::assertFalse($file->isMultipage());
        self::assertSame(1, $file->pageCount());
    }

    public function testMultipageDocumentReportsCorrectPageCount(): void
    {
        $manifest = $this->loadFixture('manifest-multipage-v2.json');
        $file = $this->makeFile($manifest);

        self::assertTrue($file->isMultipage());
        self::assertSame(3, $file->pageCount());
    }

    public function testGetWidthReturnsCanvasDimensionsForPage(): void
    {
        $manifest = $this->loadFixture('manifest-multipage-v2.json');
        $file = $this->makeFile($manifest);

        // Page 1: 4000×5500, Page 2: 3800×5200, Page 3: 4100×5600
        self::assertSame(4000, $file->getWidth(1));
        self::assertSame(3800, $file->getWidth(2));
        self::assertSame(4100, $file->getWidth(3));
    }

    public function testGetHeightReturnsCanvasDimensionsForPage(): void
    {
        $manifest = $this->loadFixture('manifest-multipage-v2.json');
        $file = $this->makeFile($manifest);

        self::assertSame(5500, $file->getHeight(1));
        self::assertSame(5200, $file->getHeight(2));
        self::assertSame(5600, $file->getHeight(3));
    }

    // ─── AC 10: getUrl / getFullUrl → IIIF Image API URL ─────────

    public function testGetUrlReturnsIiifImageApiUrl(): void
    {
        $manifest = $this->loadFixture('manifest-fotothek-v2.json');
        $file = $this->makeFile($manifest);

        $url = $file->getUrl();

        self::assertStringContainsString('/full/full/0/default.jpg', $url);
        self::assertStringContainsString('df_dk_0007450', $url);
    }

    public function testGetFullUrlMatchesGetUrl(): void
    {
        $manifest = $this->loadFixture('manifest-fotothek-v2.json');
        $file = $this->makeFile($manifest);

        self::assertSame($file->getUrl(), $file->getFullUrl());
    }

    public function testGetUrlForPageReturnsCorrectServiceUrl(): void
    {
        $manifest = $this->loadFixture('manifest-multipage-v2.json');
        $file = $this->makeFile($manifest);

        $url1 = $file->getUrlForPage(1);
        $url2 = $file->getUrlForPage(2);
        $url3 = $file->getUrlForPage(3);

        self::assertStringContainsString('df_dk_multipage_001', $url1);
        self::assertStringContainsString('df_dk_multipage_002', $url2);
        self::assertStringContainsString('df_dk_multipage_003', $url3);

        // All must be full-resolution IIIF URLs.
        self::assertStringContainsString('/full/full/0/default.jpg', $url1);
        self::assertStringContainsString('/full/full/0/default.jpg', $url2);
        self::assertStringContainsString('/full/full/0/default.jpg', $url3);
    }

    public function testGetUrlAlwaysReturnsPage1(): void
    {
        $manifest = $this->loadFixture('manifest-multipage-v2.json');
        $file = $this->makeFile($manifest);

        // getUrl() always returns page 1, regardless of previous transforms.
        $url = $file->getUrl();
        self::assertStringContainsString('df_dk_multipage_001', $url);
    }

    // ─── AC 15: v3 manifest parsing ───────────────────────────────

    public function testV3ManifestServiceExtraction(): void
    {
        $manifest = $this->loadFixture('manifest-v3.json');
        $file = $this->makeFile($manifest, 'v3-provider', 'img001', 'Img001.jpg');

        $url = $file->getUrl();

        self::assertStringContainsString('example.org/iiif/image/v3/img001', $url);
        self::assertStringContainsString('/full/full/0/default.jpg', $url);
    }

    public function testV3ManifestDimensions(): void
    {
        $manifest = $this->loadFixture('manifest-v3.json');
        $file = $this->makeFile($manifest, 'v3-provider', 'img001', 'Img001.jpg');

        self::assertSame(5000, $file->getWidth(1));
        self::assertSame(7000, $file->getHeight(1));
    }

    // ─── AC 16: exists() / error manifests ────────────────────────

    public function testExistsReturnsTrueForValidManifest(): void
    {
        $manifest = $this->loadFixture('manifest-fotothek-v2.json');
        $file = $this->makeFile($manifest);

        self::assertTrue($file->exists());
    }

    public function testExistsReturnsFalseWhenUnresolved(): void
    {
        $file = $this->makeFile(null);

        self::assertFalse($file->exists());
    }

    // ─── Transform & lastTransformPage ────────────────────────────

    public function testTransformReturnsThumbWithCorrectUrl(): void
    {
        $manifest = $this->loadFixture('manifest-fotothek-v2.json');
        $file = $this->makeFile($manifest);

        $thumb = $file->transform(['width' => 800]);

        self::assertInstanceOf(ThumbnailImage::class, $thumb);
        self::assertStringContainsString('800,', $thumb->getUrl());
        self::assertStringContainsString('df_dk_0007450', $thumb->getUrl());
    }

    public function testTransformSetsLastTransformPage(): void
    {
        $manifest = $this->loadFixture('manifest-multipage-v2.json');
        $file = $this->makeFile($manifest);

        self::assertSame(1, $file->lastTransformPage());

        $file->transform(['width' => 400, 'page' => 2]);
        self::assertSame(2, $file->lastTransformPage());

        $file->transform(['width' => 400, 'page' => 3]);
        self::assertSame(3, $file->lastTransformPage());
    }

    public function testTransformPage2UsesCorrectService(): void
    {
        $manifest = $this->loadFixture('manifest-multipage-v2.json');
        $file = $this->makeFile($manifest);

        $thumb = $file->transform(['width' => 600, 'page' => 2]);

        self::assertInstanceOf(ThumbnailImage::class, $thumb);
        self::assertStringContainsString('df_dk_multipage_002', $thumb->getUrl());
    }

    public function testTransformReturnsErrorWhenUnresolved(): void
    {
        $file = $this->makeFile(null);

        $result = $file->transform(['width' => 800]);

        self::assertInstanceOf(MediaTransformError::class, $result);
    }

    public function testTransformFullResolutionWhenNoDimensionsGiven(): void
    {
        $manifest = $this->loadFixture('manifest-fotothek-v2.json');
        $file = $this->makeFile($manifest);

        $thumb = $file->transform([]);

        self::assertInstanceOf(ThumbnailImage::class, $thumb);
        self::assertStringContainsString('/full/full/0/default.jpg', $thumb->getUrl());
    }

    // ─── Static file properties ───────────────────────────────────

    public function testMimeTypeIsJpeg(): void
    {
        $manifest = $this->loadFixture('manifest-fotothek-v2.json');
        $file = $this->makeFile($manifest);

        self::assertSame('image/jpeg', $file->getMimeType());
    }

    public function testMediaTypeIsBitmap(): void
    {
        $manifest = $this->loadFixture('manifest-fotothek-v2.json');
        $file = $this->makeFile($manifest);

        self::assertSame(MEDIATYPE_BITMAP, $file->getMediaType());
    }

    public function testSizeIsZero(): void
    {
        $manifest = $this->loadFixture('manifest-fotothek-v2.json');
        $file = $this->makeFile($manifest);

        self::assertSame(0, $file->getSize());
    }

    public function testHandlerIsIiifHandler(): void
    {
        $manifest = $this->loadFixture('manifest-fotothek-v2.json');
        $file = $this->makeFile($manifest);

        $handler = $file->getHandler();
        self::assertInstanceOf(\MediaWiki\Extension\InstantIIIF\IIIFHandler::class, $handler);
    }

    public function testGetResolvedManifestExposesData(): void
    {
        $manifest = $this->loadFixture('manifest-fotothek-v2.json');
        $file = $this->makeFile($manifest);

        $resolved = $file->getResolvedManifest();

        self::assertIsArray($resolved);
        self::assertSame('deutsche-fotothek', $resolved['provider']);
        self::assertSame($manifest, $resolved['manifestRaw']);
    }

    // ─── Provider URL edge cases ──────────────────────────────────

    public function testGetProviderUrlFallsBackThroughStrategies(): void
    {
        // Manifest with no homepage, no related, and unknown provider → empty
        $manifest = [
            '@context' => 'http://iiif.io/api/presentation/2/context.json',
            'label' => 'No links',
            'sequences' => [['canvases' => [
                [
                    'width' => 100,
                    'height' => 100,
                    'images' => [['resource' => ['service' => ['@id' => 'https://example.org/svc']]]],
                ],
            ],],],
        ];
        $file = $this->makeFile($manifest, 'unknown-provider', 'test', 'Test.jpg');

        self::assertSame('', $file->getProviderUrl());
    }

    public function testGetProviderUrlFromV2RelatedObject(): void
    {
        // related as object with @id
        $manifest = $this->loadFixture('manifest-slub-v2.json');
        $file = $this->makeFile($manifest, 'slub', 'test', 'Test.jpg');

        $url = $file->getProviderUrl();
        self::assertSame('https://digital.slub-dresden.de/werkansicht/dlf/12345', $url);
    }

    public function testGetProviderUrlFromV2RelatedPlainString(): void
    {
        // related as plain string URL
        $manifest = $this->loadFixture('manifest-bsb-v2.json');
        $file = $this->makeFile($manifest, 'bsb', 'test', 'Test.jpg');

        $url = $file->getProviderUrl();
        self::assertSame('https://www.digitale-sammlungen.de/de/view/bsb10000001', $url);
    }

    // ─── Page normalization ───────────────────────────────────────

    public function testPageNormalizationClampsToOne(): void
    {
        $manifest = $this->loadFixture('manifest-multipage-v2.json');
        $file = $this->makeFile($manifest);

        // Page 0 and negative should be normalized to 1.
        self::assertSame($file->getWidth(1), $file->getWidth(0));
        self::assertSame($file->getWidth(1), $file->getWidth(-5));
    }

    public function testOutOfBoundsPageReturnsZeroDimensions(): void
    {
        $manifest = $this->loadFixture('manifest-multipage-v2.json');
        $file = $this->makeFile($manifest);

        // Page 99 is beyond the 3 canvases → 0.
        self::assertSame(0, $file->getWidth(99));
        self::assertSame(0, $file->getHeight(99));
    }

    // ─── removeImageExtension ─────────────────────────────────────

    /**
     * @return array<string, array{string, string}>
     */
    public static function removeImageExtensionProvider(): array
    {
        return [
            'jpg' => ['df_dk_0007450.jpg', 'df_dk_0007450'],
            'jpeg' => ['df_dk_0007450.jpeg', 'df_dk_0007450'],
            'png' => ['df_dk_0007450.png', 'df_dk_0007450'],
            'no extension' => ['df_dk_0007450', 'df_dk_0007450'],
            'double ext' => ['df_dk_0007450.tif.jpg', 'df_dk_0007450.tif'],
            'uppercase' => ['df_dk_0007450.JPG', 'df_dk_0007450'],
        ];
    }

    #[DataProvider('removeImageExtensionProvider')]
    public function testRemoveImageExtension(string $input, string $expected): void
    {
        // Access protected method via reflection
        $manifest = $this->loadFixture('manifest-fotothek-v2.json');
        $file = $this->makeFile($manifest);

        $ref = new \ReflectionMethod($file, 'removeImageExtension');

        self::assertSame($expected, $ref->invoke($file, $input));
    }
}
