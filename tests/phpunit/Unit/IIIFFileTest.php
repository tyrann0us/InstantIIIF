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
     * @param string                    $nsText       Namespace text (e.g. "File")
     */
    private function makeFile(
        ?array $manifestRaw,
        string $provider = 'deutsche-fotothek',
        string $objectId = 'df_dk_0007450',
        string $dbKey = 'Df_dk_0007450',
        string $nsText = 'File',
    ): IIIFFile {

        $title = new Title($dbKey, NS_FILE, $nsText);
        $repo = $this->createStub(Repo::class);
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

    // ─── getDescriptionUrl → local wiki URL ─────────────────

    public function testGetDescriptionUrlReturnsLocalWikiUrl(): void
    {
        $manifest = $this->loadFixture('manifest-fotothek-v2.json');
        $file = $this->makeFile($manifest);

        $url = $file->getDescriptionUrl();

        // Must be a full URL (with protocol), not a relative path.
        self::assertStringStartsWith('https://', $url);
        // Must contain the local file page path (with the spoofed `.jpg`
        // stripped — see testGetDescriptionUrlStripsSpoofedJpgExtension).
        self::assertStringContainsString('File:Df_dk_0007450', $url);
        self::assertStringNotContainsString('fotothek.slub-dresden.de', $url);
    }

    /**
     * getDescriptionShortUrl() returns the provider landing URL so
     * MMV's download-dialog "Namensnennung" credit and the HTML
     * embed's trailing "Link" point downstream re-users at the
     * original institution that holds the work, not back at the local
     * wiki page.
     */
    /**
     * Real-world IIIF object IDs are extension-less (e.g.
     * "Bsb11610364"). Hooks spoofs ".jpg" onto data-iiif-title so MMV
     * accepts the file, and MMV later round-trips that spoofed title
     * through the imageinfo API. The IIIFFile that comes back must
     * still expose the *un-spoofed* descriptionUrl, otherwise the
     * file-page's File usage listing (which matches by exact
     * title) loses every wikitext usage that referenced the bare ID.
     */
    public function testGetDescriptionUrlStripsSpoofedJpgExtension(): void
    {
        $manifest = $this->loadFixture('manifest-multipage-v2.json');
        $file = $this->makeFile(
            $manifest,
            'digitale-sammlungen',
            'bsb11610364',
            'Bsb11610364.jpg', // spoofed title coming back via the API
        );

        $url = $file->getDescriptionUrl();

        self::assertStringContainsString('File:Bsb11610364', $url);
        self::assertStringNotContainsString('.jpg', $url);
    }

    public function testGetDescriptionShortUrlReturnsProviderUrl(): void
    {
        $manifest = $this->loadFixture('manifest-fotothek-v2.json');
        $file = $this->makeFile($manifest);

        self::assertSame(
            'http://www.deutschefotothek.de/documents/obj/90062808',
            $file->getDescriptionShortUrl()
        );
    }

    public function testGetDescriptionShortUrlFallsBackToLocalWhenProviderEmpty(): void
    {
        // No provider URL recoverable for this synthetic manifest, so we
        // must fall back to the local file page URL to avoid sending
        // `null`/`undefined` to MMV (which would crash HtmlUtils).
        $manifest = [
            '@context' => 'http://iiif.io/api/presentation/2/context.json',
            'label' => 'No links',
            'sequences' => [['canvases' => [
                ['width' => 1, 'height' => 1, 'images' => [['resource' => ['service' => ['@id' => 'https://example/svc']]]]],
            ]]],
        ];
        $file = $this->makeFile($manifest, 'unknown-provider', 'test', 'Test.jpg');

        self::assertSame($file->getDescriptionUrl(), $file->getDescriptionShortUrl());
    }

    public function testGetDescriptionUrlReturnsEmptyWithoutTitle(): void
    {
        // Construct file with null title scenario
        $repo = $this->createStub(Repo::class);
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

    // ─── getProviderUrl → provider landing page ─────────────

    public function testGetProviderUrlFromFotothekMetadata(): void
    {
        $manifest = $this->loadFixture('manifest-fotothek-v2.json');
        $file = $this->makeFile($manifest);

        $url = $file->getProviderUrl();

        // Fotothek's "Link zum Werk" metadata entry on the live manifest.
        self::assertSame('http://www.deutschefotothek.de/documents/obj/90062808', $url);
    }

    public function testGetProviderUrlFromSlubMetadataPurl(): void
    {
        // SLUB manifests carry the landing URL inside an HTML <a>
        // under the metadata label "PURL"; they have no top-level
        // `related`/`homepage`.
        $manifest = $this->loadFixture('manifest-slub-v2.json');
        $file = $this->makeFile($manifest, 'slub-dresden', '384671365-19500000', '384671365-19500000.jpg');

        $url = $file->getProviderUrl();

        self::assertSame('http://digital.slub-dresden.de/id384671365-19500000', $url);
    }

    public function testGetProviderUrlFromBsbV2RelatedArray(): void
    {
        // BSB manifests expose `related` as a list of objects; we
        // pick the first entry (the "Details" landing page).
        $manifest = $this->loadFixture('manifest-bsb-v2.json');
        $file = $this->makeFile($manifest, 'digitale-sammlungen', 'bsb00127289', 'Bsb00127289.jpg');

        $url = $file->getProviderUrl();

        self::assertSame('https://mdz-nbn-resolving.de/details:bsb00127289', $url);
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

    // ─── Multi-page support ──────────────────────────────

    public function testSinglePageDocumentIsNotMultipage(): void
    {
        $manifest = $this->loadFixture('manifest-fotothek-v2.json');
        $file = $this->makeFile($manifest);

        self::assertFalse($file->isMultipage());
        self::assertSame(1, $file->pageCount());
    }

    public function testMultipageDocumentReportsCorrectPageCount(): void
    {
        // bsb11610364 manifest has 622 canvases.
        $manifest = $this->loadFixture('manifest-multipage-v2.json');
        $file = $this->makeFile($manifest, 'digitale-sammlungen', 'bsb11610364', 'Bsb11610364');

        self::assertTrue($file->isMultipage());
        self::assertSame(622, $file->pageCount());
    }

    public function testGetWidthReturnsCanvasDimensionsForPage(): void
    {
        $manifest = $this->loadFixture('manifest-multipage-v2.json');
        $file = $this->makeFile($manifest, 'digitale-sammlungen', 'bsb11610364', 'Bsb11610364');

        // First three canvases from the bsb11610364 manifest.
        self::assertSame(1768, $file->getWidth(1));
        self::assertSame(1464, $file->getWidth(2));
        self::assertSame(1784, $file->getWidth(3));
    }

    public function testGetHeightReturnsCanvasDimensionsForPage(): void
    {
        $manifest = $this->loadFixture('manifest-multipage-v2.json');
        $file = $this->makeFile($manifest, 'digitale-sammlungen', 'bsb11610364', 'Bsb11610364');

        self::assertSame(2536, $file->getHeight(1));
        self::assertSame(2416, $file->getHeight(2));
        self::assertSame(2440, $file->getHeight(3));
    }

    // ─── getUrl / getFullUrl → IIIF Image API URL ─────────

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
        $file = $this->makeFile($manifest, 'digitale-sammlungen', 'bsb11610364', 'Bsb11610364');

        $url1 = $file->getUrlForPage(1);
        $url2 = $file->getUrlForPage(2);
        $url6 = $file->getUrlForPage(6);

        self::assertStringContainsString('bsb11610364_00001', $url1);
        self::assertStringContainsString('bsb11610364_00002', $url2);
        self::assertStringContainsString('bsb11610364_00006', $url6);

        // All must be full-resolution IIIF URLs.
        self::assertStringContainsString('/full/full/0/default.jpg', $url1);
        self::assertStringContainsString('/full/full/0/default.jpg', $url2);
        self::assertStringContainsString('/full/full/0/default.jpg', $url6);
    }

    public function testGetUrlDefaultsToPage1WithoutTransform(): void
    {
        $manifest = $this->loadFixture('manifest-multipage-v2.json');
        $file = $this->makeFile($manifest, 'digitale-sammlungen', 'bsb11610364', 'Bsb11610364');

        // Before any transform, getUrl() returns the page 1 canvas.
        $url = $file->getUrl();
        self::assertStringContainsString('bsb11610364_00001', $url);
    }

    /**
     * getUrl() must reflect the last transformed page so the "Original
     * file" link on a file description page at ?page=N points to
     * canvas N instead of always canvas 1. Also drives the MMV
     * "download" / "open in new tab" link via the imageinfo API (which
     * calls transform with iiurlparam=pageN-Wpx before reading `url`).
     */
    public function testGetUrlReflectsLastTransformedPage(): void
    {
        $manifest = $this->loadFixture('manifest-multipage-v2.json');
        $file = $this->makeFile($manifest, 'digitale-sammlungen', 'bsb11610364', 'Bsb11610364');

        $file->transform(['width' => 800, 'page' => 2]);
        self::assertStringContainsString('bsb11610364_00002', $file->getUrl());

        $file->transform(['width' => 800, 'page' => 6]);
        self::assertStringContainsString('bsb11610364_00006', $file->getUrl());
    }

    public function testGetFullUrlReflectsLastTransformedPage(): void
    {
        $manifest = $this->loadFixture('manifest-multipage-v2.json');
        $file = $this->makeFile($manifest, 'digitale-sammlungen', 'bsb11610364', 'Bsb11610364');

        $file->transform(['width' => 800, 'page' => 6]);
        self::assertStringContainsString('bsb11610364_00006', $file->getFullUrl());
    }

    /**
     * On a file description page at ?page=6, MediaWiki renders prev/next
     * thumbnails AFTER the main image — each transform() call overwrites
     * lastTransformPage, so by the time the "Original file" link is rendered
     * it would point at canvas 7 (or 5). getUrl() must honour the request's
     * `?page=` parameter instead so it stays anchored to the page the user
     * is actually looking at.
     */
    public function testGetUrlHonoursRequestPageOverMultipleTransforms(): void
    {
        $manifest = $this->loadFixture('manifest-multipage-v2.json');
        $file = $this->makeFile($manifest, 'digitale-sammlungen', 'bsb11610364', 'Bsb11610364');

        $request = new \WebRequest();
        $request->setParams(['page' => '6']);
        \MediaWiki\Context\RequestContext::getMain()->setRequest($request);

        // Simulate MW's render order on a file-page-with-thumbs view.
        $file->transform(['width' => 800, 'page' => 6]);
        $file->transform(['width' => 200, 'page' => 5]);
        $file->transform(['width' => 200, 'page' => 7]);

        self::assertStringContainsString('bsb11610364_00006', $file->getUrl());
        self::assertStringContainsString('page=6', $file->getDescriptionUrl());

        // Cleanup so other tests get a fresh context.
        \MediaWiki\Context\RequestContext::reset();
    }

    /**
     * getDescriptionUrl() must include ?page=N for multi-page canvases > 1
     * so the MMV "More details" button (and the imageinfo descriptionurl
     * field) takes the user back to the same canvas.
     */
    public function testGetDescriptionUrlIncludesPageQueryAfterTransform(): void
    {
        $manifest = $this->loadFixture('manifest-multipage-v2.json');
        $file = $this->makeFile($manifest, 'digitale-sammlungen', 'bsb11610364', 'Bsb11610364');

        // Page 1 is the default — no query string needed.
        $file->transform(['width' => 600, 'page' => 1]);
        self::assertStringNotContainsString('page=', $file->getDescriptionUrl());

        $file->transform(['width' => 600, 'page' => 2]);
        self::assertStringContainsString('page=2', $file->getDescriptionUrl());

        $file->transform(['width' => 600, 'page' => 6]);
        self::assertStringContainsString('page=6', $file->getDescriptionUrl());
    }

    // ─── v3 manifest parsing ───────────────────────────────

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

    // ─── exists() / error manifests ────────────────────────

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
        $file = $this->makeFile($manifest, 'digitale-sammlungen', 'bsb11610364', 'Bsb11610364');

        self::assertSame(1, $file->lastTransformPage());

        $file->transform(['width' => 400, 'page' => 2]);
        self::assertSame(2, $file->lastTransformPage());

        $file->transform(['width' => 400, 'page' => 6]);
        self::assertSame(6, $file->lastTransformPage());
    }

    public function testTransformPage2UsesCorrectService(): void
    {
        $manifest = $this->loadFixture('manifest-multipage-v2.json');
        $file = $this->makeFile($manifest, 'digitale-sammlungen', 'bsb11610364', 'Bsb11610364');

        $thumb = $file->transform(['width' => 600, 'page' => 2]);

        self::assertInstanceOf(ThumbnailImage::class, $thumb);
        self::assertStringContainsString('bsb11610364_00002', $thumb->getUrl());
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

    /**
     * SLUB manifests have no top-level related`/`homepage`/`license`;
     * the public landing URL only exists inside an HTML <a> in
     * metadata under label "PURL".
     */
    public function testGetLicenseUrlFromMetadataForSlub(): void
    {
        $manifest = $this->loadFixture('manifest-slub-v2.json');
        $file = $this->makeFile($manifest, 'slub-dresden', '384671365-19500000', '384671365-19500000.jpg');

        $licenseUrl = $file->getLicenseUrlFromMetadata($file->getResolvedManifest());

        self::assertSame('http://creativecommons.org/publicdomain/mark/1.0/', $licenseUrl);
    }

    // ─── Page normalization ───────────────────────────────────────

    public function testPageNormalizationClampsToOne(): void
    {
        $manifest = $this->loadFixture('manifest-multipage-v2.json');
        $file = $this->makeFile($manifest, 'digitale-sammlungen', 'bsb11610364', 'Bsb11610364');

        // Page 0 and negative should be normalized to 1.
        self::assertSame($file->getWidth(1), $file->getWidth(0));
        self::assertSame($file->getWidth(1), $file->getWidth(-5));
    }

    public function testOutOfBoundsPageReturnsZeroDimensions(): void
    {
        $manifest = $this->loadFixture('manifest-multipage-v2.json');
        $file = $this->makeFile($manifest, 'digitale-sammlungen', 'bsb11610364', 'Bsb11610364');

        // Page beyond the 622 canvases → 0.
        self::assertSame(0, $file->getWidth(99999));
        self::assertSame(0, $file->getHeight(99999));
    }

    // ─── Invalid / out-of-range page numbers ──────────────────────

    /**
     * Out-of-range page requested via wikitext (`[[File:Foo|page=99999]]`)
     * or URL (`?page=99999`): transform must surface a MediaTransformError
     * rather than silently rendering a broken canvas — MediaWiki's image
     * pipeline catches the error and shows the "could not be resolved"
     * fallback to the reader.
     */
    public function testTransformReturnsErrorForPageBeyondCanvasCount(): void
    {
        $manifest = $this->loadFixture('manifest-multipage-v2.json');
        $file = $this->makeFile($manifest, 'digitale-sammlungen', 'bsb11610364', 'Bsb11610364');

        $result = $file->transform(['width' => 300, 'page' => 99999]);

        self::assertInstanceOf(MediaTransformError::class, $result);
    }

    public function testGetUrlForPageBeyondCanvasCountReturnsEmpty(): void
    {
        $manifest = $this->loadFixture('manifest-multipage-v2.json');
        $file = $this->makeFile($manifest, 'digitale-sammlungen', 'bsb11610364', 'Bsb11610364');

        self::assertSame('', $file->getUrlForPage(99999));
    }

    /**
     * After a successful transform a second, out-of-range transform
     * updates lastTransformPage but `getUrl()` should *not* return a
     * malformed URL — it must report empty so callers (e.g. the
     * "Original file" link) don't render a broken `<a href>`.
     */
    public function testGetUrlReturnsEmptyAfterOutOfRangeTransform(): void
    {
        $manifest = $this->loadFixture('manifest-multipage-v2.json');
        $file = $this->makeFile($manifest, 'digitale-sammlungen', 'bsb11610364', 'Bsb11610364');

        $file->transform(['width' => 600, 'page' => 6]);
        self::assertStringContainsString('bsb11610364_00006', $file->getUrl());

        $file->transform(['width' => 600, 'page' => 99999]);
        self::assertSame('', $file->getUrl());
    }

    /**
     * @return array<string, array{mixed, int}>
     */
    public static function pageNormalizationProvider(): array
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
     * `normalizePage` underpins every page-aware code path; verify the
     * full normalisation matrix so junky inputs from wikitext / URL
     * params all settle on page 1.
     *
     * @param mixed $input
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('pageNormalizationProvider')]
    public function testNormalizePage(mixed $input, int $expected): void
    {
        $manifest = $this->loadFixture('manifest-multipage-v2.json');
        $file = $this->makeFile($manifest, 'digitale-sammlungen', 'bsb11610364', 'Bsb11610364');

        $ref = new \ReflectionMethod($file, 'normalizePage');

        self::assertSame($expected, $ref->invoke($file, $input));
    }

    /**
     * getTimestamp() must be non-empty and non-numeric: a falsy value would
     * make wfTimestamp() fall back to "now", and a digit-leading value risks
     * parsing as a real date. The non-date sentinel makes wfTimestamp()
     * return false so ApiQueryImageInfo emits an empty upload timestamp.
     * The end-to-end blanking is verified against real MediaWiki in the
     * imageinfo-api Playwright spec (the standalone wfTimestamp() stub
     * always returns false, so it can't exercise that conversion here).
     */
    public function testGetTimestampReturnsNonDateSentinel(): void
    {
        $file = $this->makeFile($this->loadFixture('manifest-fotothek-v2.json'));

        $timestamp = $file->getTimestamp();

        self::assertNotSame('', $timestamp);
        self::assertSame(0, preg_match('/^\d/', $timestamp));
    }
}
