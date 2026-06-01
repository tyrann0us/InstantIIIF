<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF\Tests\Unit;

use MediaTransformError;
use MediaWiki\Extension\InstantIIIF\Infrastructure\MediaWiki\IIIFFile;
use MediaWiki\Extension\InstantIIIF\Infrastructure\MediaWiki\Repo;
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

    /**
     * MediaWiki core's File::getWidth($page = 1) / getHeight($page = 1)
     * signatures are untyped — MW calls them with `false` to mean
     * "no page specified" (seen in production: ImagePage rendering for
     * an IIIFFile triggers File::getWidth(false)). Internal coercion
     * via Page::normalize must absorb that or strict_types fires a
     * TypeError. Regression guard for the bug that surfaced on first
     * deploy of the DDD refactor.
     */
    public function testGetWidthAcceptsFalseAsNoPage(): void
    {
        $manifest = $this->loadFixture('manifest-fotothek-v2.json');
        $file = $this->makeFile($manifest);

        // false should resolve to page 1 (the default), not throw.
        self::assertSame($file->getWidth(1), $file->getWidth(false));
        self::assertSame($file->getHeight(1), $file->getHeight(false));
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
        self::assertInstanceOf(\MediaWiki\Extension\InstantIIIF\Infrastructure\MediaWiki\IIIFHandler::class, $handler);
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

    /**
     * Providers without a metadata-label fallback (everyone but SLUB) skip
     * the search entirely via the early-return guard on the labels list.
     */
    public function testGetLicenseUrlFromMetadataReturnsEmptyForProviderWithoutLabels(): void
    {
        $file = $this->makeFile($this->loadFixture('manifest-fotothek-v2.json'));

        // Fotothek has no LICENSE_META_KEYS entry → empty result.
        self::assertSame('', $file->getLicenseUrlFromMetadata([
            'provider' => 'deutsche-fotothek',
            'manifestRaw' => [],
        ]));
    }

    /**
     * Defensive guard for malformed resolved arrays: when `manifestRaw`
     * isn't an array we bail out instead of passing junk into
     * Manifest::from().
     */
    public function testGetLicenseUrlFromMetadataReturnsEmptyWhenManifestRawIsNotArray(): void
    {
        $file = $this->makeFile($this->loadFixture('manifest-slub-v2.json'));

        self::assertSame('', $file->getLicenseUrlFromMetadata([
            'provider' => 'slub-dresden',
            'manifestRaw' => 'not-an-array',
        ]));
    }

    /**
     * The static `manifestFetcher()` factory is private and is the boundary
     * where IIIFFile reaches into MediaWikiServices. Verify it returns a
     * CachedHttpManifestFetcher so the wiring stays correct after refactors.
     */
    public function testManifestFetcherFactoryReturnsCachedHttpManifestFetcher(): void
    {
        $ref = new \ReflectionMethod(IIIFFile::class, 'manifestFetcher');
        $fetcher = $ref->invoke(null);

        self::assertInstanceOf(
            \MediaWiki\Extension\InstantIIIF\Infrastructure\CachedHttpManifestFetcher::class,
            $fetcher
        );
    }

    /**
     * `fetchJsonCached()` is the seam between IIIFFile and the
     * Infrastructure HTTP layer. Production code goes through it but the
     * other tests mock it out — exercise the real body once so the
     * one-line delegation to manifestFetcher() is covered. Returns null
     * because the standalone HTTP stub responds with an empty body.
     */
    public function testFetchJsonCachedDelegatesToManifestFetcher(): void
    {
        $file = $this->makeFile($this->loadFixture('manifest-fotothek-v2.json'));
        $ref = new \ReflectionMethod(IIIFFile::class, 'fetchJsonCached');

        // The stub HttpRequestFactory returns an MWHttpRequest with an
        // empty body, so the fetcher decodes to null. The point is to
        // execute the line, not to assert on the result.
        $result = $ref->invoke($file, 'https://example.org/missing.json');

        self::assertNull($result);
    }

    /**
     * Public helper used by SpecialInstantIIIFInspect — exposes the service
     * @id for a given canvas as a plain string, with the input page run
     * through Page::normalize so callers can pass junk.
     */
    public function testGetServiceIdForPageReturnsServiceUrl(): void
    {
        $manifest = $this->loadFixture('manifest-multipage-v2.json');
        $file = $this->makeFile($manifest, 'digitale-sammlungen', 'bsb11610364', 'Bsb11610364');

        self::assertStringContainsString('bsb11610364_00001', (string) $file->getServiceIdForPage(1));
        self::assertStringContainsString('bsb11610364_00002', (string) $file->getServiceIdForPage(2));
        // Out-of-range page → null.
        self::assertNull($file->getServiceIdForPage(99999));
    }

    /**
     * Public helper used by SpecialInstantIIIFInspect's canvas table —
     * returns a `[width, height]` tuple.
     */
    public function testGetCanvasDimensionsReturnsTuple(): void
    {
        $manifest = $this->loadFixture('manifest-multipage-v2.json');
        $file = $this->makeFile($manifest, 'digitale-sammlungen', 'bsb11610364', 'Bsb11610364');

        self::assertSame([1768, 2536], $file->getCanvasDimensions(1));
        self::assertSame([1464, 2416], $file->getCanvasDimensions(2));
        // Out-of-range page → 0×0 (unknown).
        self::assertSame([0, 0], $file->getCanvasDimensions(99999));
    }

    /**
     * When the canvas has no width/height but info.json does, getWidth /
     * getHeight should fall through to info.json. The standard makeFile()
     * stub returns `[]` for info.json so this branch is otherwise
     * unreachable — we override it inline.
     */
    public function testGetWidthFallsBackToInfoJsonWhenCanvasDimsMissing(): void
    {
        $manifest = [
            '@context' => 'http://iiif.io/api/presentation/2/context.json',
            'sequences' => [['canvases' => [[
                // No width/height on the canvas itself.
                'images' => [['resource' => ['service' => ['@id' => 'https://example/svc']]]],
            ]]]],
        ];

        $title = new Title('Test', NS_FILE, 'File');
        $repo = $this->createStub(Repo::class);
        $repo->method('iiifSources')->willReturn([
            ['id' => 'test', 'manifestPattern' => 'https://example/$1/manifest.json'],
        ]);

        $file = new class ($repo, $title, $manifest) extends IIIFFile {
            private array $injectedManifest;
            public function __construct(Repo $repo, Title $title, array $manifest)
            {
                parent::__construct($repo, $title);
                $this->injectedManifest = $manifest;
            }
            protected function ensureResolved(): ?array
            {
                if ($this->resolved !== null) {
                    return $this->resolved;
                }
                $this->resolved = [
                    'provider' => 'test',
                    'objectId' => 'test',
                    'manifestUrl' => 'https://example/test/manifest.json',
                    'manifestRaw' => $this->injectedManifest,
                ];
                return $this->resolved;
            }
            protected function ensureInfoJsonFor(string $serviceId): array
            {
                // Pretend info.json returned these dims.
                return ['width' => 4000, 'height' => 3000];
            }
        };

        self::assertSame(4000, $file->getWidth(1));
        self::assertSame(3000, $file->getHeight(1));
    }

    /**
     * Defensive path in dimensionsForPage: when the manifest can't be
     * resolved at all (no providers match the title), getWidth must
     * return 0 instead of crashing on a null manifest reference.
     */
    public function testGetWidthReturnsZeroWhenManifestUnresolved(): void
    {
        $file = $this->makeFile(null);

        self::assertSame(0, $file->getWidth(1));
        self::assertSame(0, $file->getHeight(1));
    }

    /**
     * `transform()` uses originalDimensionsFor to decide the rendered
     * dimensions on the resulting ThumbnailImage. When the canvas has no
     * width/height, that helper falls through to the image service's
     * info.json. Exercise the fallback so it stays correct (it powers
     * MMV's data-file-width on info-only manifests).
     */
    public function testTransformOriginalDimsFallBackToInfoJsonWhenCanvasMissing(): void
    {
        $manifest = [
            '@context' => 'http://iiif.io/api/presentation/2/context.json',
            'sequences' => [['canvases' => [[
                // No width/height on the canvas.
                'images' => [['resource' => ['service' => ['@id' => 'https://example/svc']]]],
            ]]]],
        ];

        $title = new Title('Test', NS_FILE, 'File');
        $repo = $this->createStub(Repo::class);
        $repo->method('iiifSources')->willReturn([
            ['id' => 'test', 'manifestPattern' => 'https://example/$1/manifest.json'],
        ]);

        $file = new class ($repo, $title, $manifest) extends IIIFFile {
            private array $injectedManifest;
            public function __construct(Repo $repo, Title $title, array $manifest)
            {
                parent::__construct($repo, $title);
                $this->injectedManifest = $manifest;
            }
            protected function ensureResolved(): ?array
            {
                if ($this->resolved !== null) {
                    return $this->resolved;
                }
                $this->resolved = [
                    'provider' => 'test',
                    'objectId' => 'test',
                    'manifestUrl' => 'https://example/test/manifest.json',
                    'manifestRaw' => $this->injectedManifest,
                ];
                return $this->resolved;
            }
            protected function ensureInfoJsonFor(string $serviceId): array
            {
                return ['width' => 4000, 'height' => 3000];
            }
        };

        // transform with no size → originalDimensionsFor takes the info.json
        // dims, which surface on the ThumbnailImage.
        $thumb = $file->transform([]);
        self::assertInstanceOf(ThumbnailImage::class, $thumb);
        self::assertSame(4000, $thumb->getWidth());
        self::assertSame(3000, $thumb->getHeight());
    }

    /**
     * `ensureResolved()` memoises its result on `$this->resolved` — a
     * second call must short-circuit at the cache check (line 1) instead
     * of re-running the provider loop. Use the real-resolve harness and
     * count fetchJsonCached invocations to prove it.
     */
    public function testEnsureResolvedMemoisesResultAcrossCalls(): void
    {
        $manifest = $this->loadFixture('manifest-fotothek-v2.json');
        $title = new Title('Df_dk_0007450', NS_FILE, 'File');

        $file = $this->makeFileWithRealResolve(
            [['id' => 'deutsche-fotothek', 'idPattern' => '/^df_/', 'manifestPattern' => 'https://fotothek.example/$1/manifest.json']],
            ['https://fotothek.example/df_dk_0007450/manifest.json' => $manifest],
            $title,
        );

        $first = $file->getResolvedManifest();
        $second = $file->getResolvedManifest();

        self::assertSame($first, $second);
        // Memoisation: the second call must NOT re-issue a fetch.
        self::assertSame(1, $file->fetchCalls);
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

    // ─── Real ensureResolved() / tryProvider() paths ─────────────

    /**
     * Build an IIIFFile that exercises the real ensureResolved() /
     * tryProvider() / getProviderConfig() bodies but stubs out
     * fetchJsonCached so no HTTP is performed.
     *
     * @param array<int, array<string, mixed>> $sources
     * @param array<string, array<string, mixed>|null> $fetchMap URL → decoded body (or null)
     */
    private function makeFileWithRealResolve(
        array $sources,
        array $fetchMap,
        Title $title,
    ): IIIFFile {

        $repo = $this->createStub(Repo::class);
        $repo->method('iiifSources')->willReturn($sources);

        return new class ($repo, $title, $fetchMap) extends IIIFFile {
            /** @var array<string, array<string, mixed>|null> */
            private array $fetchMap;
            public int $fetchCalls = 0;

            public function __construct(Repo $repo, Title $title, array $fetchMap)
            {
                parent::__construct($repo, $title);
                $this->fetchMap = $fetchMap;
            }

            protected function fetchJsonCached(string $url): ?array
            {
                $this->fetchCalls++;
                return $this->fetchMap[$url] ?? null;
            }
        };
    }

    public function testEnsureResolvedHappyPathLoopsProviderConfigAndPopulatesResolved(): void
    {
        $manifest = $this->loadFixture('manifest-fotothek-v2.json');
        $title = new Title('Df_dk_0007450', NS_FILE, 'File');
        $file = $this->makeFileWithRealResolve(
            [
                // First entry: idPattern doesn't match → skipped.
                [
                    'id' => 'skipped',
                    'idPattern' => '/^bsb/',
                    'manifestPattern' => 'https://other.example/$1/manifest.json',
                ],
                // Second entry: matches and returns a valid manifest.
                [
                    'id' => 'deutsche-fotothek',
                    'idPattern' => '/^df_/',
                    'manifestPattern' => 'https://fotothek.example/$1/manifest.json',
                ],
            ],
            [
                'https://fotothek.example/df_dk_0007450/manifest.json' => $manifest,
            ],
            $title,
        );

        $resolved = $file->getResolvedManifest();

        self::assertIsArray($resolved);
        self::assertSame('deutsche-fotothek', $resolved['provider']);
        self::assertSame('df_dk_0007450', $resolved['objectId']);
        self::assertSame('https://fotothek.example/df_dk_0007450/manifest.json', $resolved['manifestUrl']);
        self::assertSame($manifest, $resolved['manifestRaw']);
    }

    public function testEnsureResolvedReturnsNullForEmptyObjectIdAfterUnspoof(): void
    {
        // dbKey of just `.jpg` → unspoof() strips to '' → guard returns null.
        $title = new Title('.jpg', NS_FILE, 'File');
        $file = $this->makeFileWithRealResolve(
            [
                [
                    'id' => 'whatever',
                    'manifestPattern' => 'https://example.org/$1/manifest.json',
                ],
            ],
            [],
            $title,
        );

        self::assertNull($file->getResolvedManifest());
        // No fetch must occur — the empty-objectId guard short-circuits.
        self::assertSame(0, $file->fetchCalls);
    }

    public function testEnsureResolvedReturnsNullWhenTitleIsNull(): void
    {
        // Build a file whose getTitle() returns null but where ensureResolved
        // runs the real body (not overridden).
        $repo = $this->createStub(Repo::class);
        $repo->method('iiifSources')->willReturn([
            ['id' => 'x', 'manifestPattern' => 'https://example.org/$1/manifest.json'],
        ]);

        $file = new class ($repo) extends IIIFFile {
            public function __construct(Repo $repo)
            {
                // Skip parent constructor to keep title null.
                $this->repo = $repo;
            }

            public function getTitle(): ?Title
            {
                return null;
            }

            protected function fetchJsonCached(string $url): ?array
            {
                self::fail('fetchJsonCached must not be reached when title is null');
            }
        };

        self::assertNull($file->getResolvedManifest());
    }

    public function testTryProviderSkipsWhenIdPatternDoesNotMatch(): void
    {
        $title = new Title('Df_dk_0007450', NS_FILE, 'File');
        $file = $this->makeFileWithRealResolve(
            [
                [
                    'id' => 'bsb-only',
                    'idPattern' => '/^bsb/',
                    'manifestPattern' => 'https://bsb.example/$1/manifest.json',
                ],
            ],
            // No URL registered: if the loop were to actually fetch, we'd get null.
            [],
            $title,
        );

        self::assertNull($file->getResolvedManifest());
        // idPattern guard returns before fetchJsonCached.
        self::assertSame(0, $file->fetchCalls);
    }

    public function testTryProviderReturnsNullWhenManifestPatternEmpty(): void
    {
        $title = new Title('Df_dk_0007450', NS_FILE, 'File');
        $file = $this->makeFileWithRealResolve(
            [
                // idPattern matches, but there's no manifestPattern to build
                // a URL from — the manifestPattern guard returns before any
                // fetch.
                ['id' => 'no-manifest', 'idPattern' => '/^df_/'],
            ],
            [],
            $title,
        );

        self::assertNull($file->getResolvedManifest());
        // Empty manifestPattern guard returns before any fetch.
        self::assertSame(0, $file->fetchCalls);
    }

    public function testTryProviderReturnsNullWhenFetchFails(): void
    {
        $title = new Title('Df_dk_0007450', NS_FILE, 'File');
        $file = $this->makeFileWithRealResolve(
            [
                [
                    'id' => 'fetches-but-fails',
                    'idPattern' => '/^df_/',
                    'manifestPattern' => 'https://fotothek.example/$1/manifest.json',
                ],
            ],
            // Map entry returns null → fetchJsonCached returns null.
            ['https://fotothek.example/df_dk_0007450/manifest.json' => null],
            $title,
        );

        self::assertNull($file->getResolvedManifest());
        self::assertSame(1, $file->fetchCalls);
    }

    public function testTryProviderReturnsNullForErrorManifest(): void
    {
        $title = new Title('Df_dk_0007450', NS_FILE, 'File');
        // Manifest::isError() returns true when label starts with "error:".
        $errorManifest = ['label' => 'error: not found'];
        $file = $this->makeFileWithRealResolve(
            [
                [
                    'id' => 'fotothek',
                    'idPattern' => '/^df_/',
                    'manifestPattern' => 'https://fotothek.example/$1/manifest.json',
                ],
            ],
            ['https://fotothek.example/df_dk_0007450/manifest.json' => $errorManifest],
            $title,
        );

        self::assertNull($file->getResolvedManifest());
    }

    public function testTryProviderDefaultsProviderIdWhenAbsent(): void
    {
        $manifest = $this->loadFixture('manifest-fotothek-v2.json');
        $title = new Title('Df_dk_0007450', NS_FILE, 'File');
        $file = $this->makeFileWithRealResolve(
            [
                // No `id` key → default to 'default'.
                ['idPattern' => '/^df_/', 'manifestPattern' => 'https://fotothek.example/$1/manifest.json'],
            ],
            ['https://fotothek.example/df_dk_0007450/manifest.json' => $manifest],
            $title,
        );

        $resolved = $file->getResolvedManifest();

        self::assertIsArray($resolved);
        self::assertSame('default', $resolved['provider']);
    }

    // ─── ensureInfoJsonFor real path ──────────────────────────────

    public function testEnsureInfoJsonForFetchesInfoJsonAndMemoises(): void
    {
        // We exercise ensureInfoJsonFor via getWidth() — which falls back
        // through dimensionsForPage to info.json when canvas dims are unknown.
        // The canvas in this synthetic manifest has no width/height, so the
        // fallback runs and info.json provides them.
        $manifest = [
            '@context' => 'http://iiif.io/api/presentation/2/context.json',
            'label' => 'No dims',
            'sequences' => [['canvases' => [
                [
                    'images' => [[
                        'resource' => ['service' => ['@id' => 'https://img.example/svc']],
                    ]],
                ],
            ]]],
        ];
        $title = new Title('Test', NS_FILE, 'File');
        $file = new class (
            $this->createStub(Repo::class),
            $title,
            $manifest,
            ['https://img.example/svc/info.json' => ['width' => 4321, 'height' => 1234]],
        ) extends IIIFFile {
            /** @var array<string, mixed>|null */
            private ?array $injectedManifest;
            /** @var array<string, array<string, mixed>|null> */
            private array $fetchMap;
            public int $fetchCalls = 0;

            public function __construct(Repo $repo, Title $title, ?array $manifest, array $fetchMap)
            {
                parent::__construct($repo, $title);
                $this->injectedManifest = $manifest;
                $this->fetchMap = $fetchMap;
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
                    'provider' => 'p',
                    'objectId' => 'x',
                    'manifestUrl' => 'https://example.org/m.json',
                    'manifestRaw' => $this->injectedManifest,
                ];
                return $this->resolved;
            }

            protected function fetchJsonCached(string $url): ?array
            {
                $this->fetchCalls++;
                return $this->fetchMap[$url] ?? null;
            }
        };

        // First call: triggers the info.json fetch and the dims fallback.
        self::assertSame(4321, $file->getWidth(1));
        self::assertSame(1234, $file->getHeight(1));
        // Second call must reuse the memoised entry — fetchCalls stays at 1.
        self::assertSame(4321, $file->getWidth(1));
        self::assertSame(1, $file->fetchCalls);
    }

    public function testEnsureInfoJsonForFallsBackToEmptyArrayWhenFetchReturnsNull(): void
    {
        // Canvas has no dims, info.json fetch returns null → width/height = 0.
        $manifest = [
            '@context' => 'http://iiif.io/api/presentation/2/context.json',
            'label' => 'No dims, no info.json',
            'sequences' => [['canvases' => [
                ['images' => [['resource' => ['service' => ['@id' => 'https://img.example/svc']]]]],
            ]]],
        ];
        $title = new Title('Test', NS_FILE, 'File');
        $file = new class (
            $this->createStub(Repo::class),
            $title,
            $manifest,
        ) extends IIIFFile {
            /** @var array<string, mixed>|null */
            private ?array $injectedManifest;

            public function __construct(Repo $repo, Title $title, ?array $manifest)
            {
                parent::__construct($repo, $title);
                $this->injectedManifest = $manifest;
            }

            protected function ensureResolved(): ?array
            {
                if ($this->resolved !== null) {
                    return $this->resolved;
                }
                $this->resolved = [
                    'provider' => 'p',
                    'objectId' => 'x',
                    'manifestUrl' => 'https://example.org/m.json',
                    'manifestRaw' => $this->injectedManifest,
                ];
                return $this->resolved;
            }

            protected function fetchJsonCached(string $url): ?array
            {
                return null; // simulate fetch failure
            }
        };

        self::assertSame(0, $file->getWidth(1));
        self::assertSame(0, $file->getHeight(1));
    }

    // ─── buildThumbnail height-only branch ────────────────────────

    public function testTransformWithHeightOnlyFillsWidthFromAspectRatio(): void
    {
        // Fotothek canvas: width=1600, height=1324. Asking for height=662
        // should derive width via the aspect ratio → round(1600 * 662 / 1324) = 800.
        $manifest = $this->loadFixture('manifest-fotothek-v2.json');
        $file = $this->makeFile($manifest);

        $thumb = $file->transform(['height' => 662]);

        self::assertInstanceOf(ThumbnailImage::class, $thumb);
        self::assertSame(662, $thumb->getHeight());
        // Computed via aspect ratio (1600/1324).
        self::assertSame(800, $thumb->getWidth());
    }

    // ─── getProviderConfig non-Repo guard ────────────────────────

    public function testGetProviderConfigReturnsEmptyWhenRepoIsNotIiifRepo(): void
    {
        // Build a file whose $repo is a vanilla FileRepo (not our Repo).
        // Real ensureResolved() runs, hits getProviderConfig(), sees that
        // $repo is not Repo, returns [], so the foreach loop has nothing
        // to iterate and resolved stays null.
        $title = new Title('Df_dk_0007450', NS_FILE, 'File');
        $vanilla = new \FileRepo(['name' => 'local']);
        $file = new class ($vanilla, $title) extends IIIFFile {
            public function __construct(\FileRepo $repo, Title $title)
            {
                // Bypass our constructor's type hint (Repo) — assign repo directly.
                $this->repo = $repo;
                $this->title = $title;
            }

            protected function fetchJsonCached(string $url): ?array
            {
                self::fail('fetchJsonCached must not run when repo is not a Repo');
            }
        };

        self::assertNull($file->getResolvedManifest());
    }

    // ─── canonicalLocalTitle edge cases via getDescriptionUrl ───

    public function testGetDescriptionUrlReturnsBaseTitleWhenDbKeyHasNoSpoofedExtension(): void
    {
        // dbKey without `.jpg` → unspoof() returns the same string → early
        // return with original title (no Title::makeTitleSafe rebuild).
        $manifest = $this->loadFixture('manifest-fotothek-v2.json');
        $file = $this->makeFile($manifest, 'deutsche-fotothek', 'df_dk_0007450', 'Df_dk_0007450');

        $url = $file->getDescriptionUrl();

        self::assertStringContainsString('File:Df_dk_0007450', $url);
        self::assertStringNotContainsString('.jpg', $url);
    }

    public function testGetDescriptionUrlFallsBackToOriginalTitleWhenMakeTitleSafeFails(): void
    {
        // makeTitleSafe() returns null for an empty stripped string. A dbKey
        // of just `.jpg` strips to `''`, which is normally caught by the
        // early `stripped===''` return — so we hit the fallback branch with
        // a dbKey whose unspoofed form is non-empty but a stubbed Title
        // that overrides makeTitleSafe to fail. The standalone Title stub
        // already returns null for empty strings, so we exercise the
        // empty-stripped early-return path here (which keeps the original
        // title unchanged).
        $manifest = $this->loadFixture('manifest-fotothek-v2.json');
        // dbKey ".jpg" → stripped to '' → early-return original title.
        $file = $this->makeFile($manifest, 'deutsche-fotothek', 'df_dk_0007450', '.jpg');

        // Must not throw; returns a URL pointing at the original (spoofed) title.
        $url = $file->getDescriptionUrl();

        self::assertStringStartsWith('https://', $url);
        self::assertStringContainsString('.jpg', $url);
    }

    // ─── preferredLanguages — indirectly via getProviderUrl ───

    public function testPreferredLanguagesPrependsContentLanguageThenEn(): void
    {
        // Force the wiki content language to German via the stub override.
        $previous = \MediaWiki\MediaWikiServices::$mockContentLanguageCode;
        \MediaWiki\MediaWikiServices::$mockContentLanguageCode = 'de';

        try {
            // SLUB manifest uses German labels in metadata. With the content
            // language set to 'de', preferredLanguages() yields ['de', 'en']
            // and the PURL lookup resolves correctly.
            $manifest = $this->loadFixture('manifest-slub-v2.json');
            $file = $this->makeFile($manifest, 'slub-dresden', '384671365-19500000', '384671365-19500000.jpg');

            self::assertSame(
                'http://digital.slub-dresden.de/id384671365-19500000',
                $file->getProviderUrl()
            );
        } finally {
            \MediaWiki\MediaWikiServices::$mockContentLanguageCode = $previous;
        }
    }

    /**
     * preferredLanguages() must still yield at least 'en' when the content
     * language code is empty (not pre-pended) — the appended 'en' keeps the
     * downstream metadata lookups working.
     */
    public function testPreferredLanguagesAppendsEnWhenContentLanguageIsEmpty(): void
    {
        $previous = \MediaWiki\MediaWikiServices::$mockContentLanguageCode;
        \MediaWiki\MediaWikiServices::$mockContentLanguageCode = '';

        try {
            $manifest = $this->loadFixture('manifest-slub-v2.json');
            $file = $this->makeFile($manifest, 'slub-dresden', '384671365-19500000', '384671365-19500000.jpg');

            // Lookup still succeeds via the appended 'en'.
            self::assertSame(
                'http://digital.slub-dresden.de/id384671365-19500000',
                $file->getProviderUrl()
            );
        } finally {
            \MediaWiki\MediaWikiServices::$mockContentLanguageCode = $previous;
        }
    }

    /**
     * `preferredLanguages()` swallows any Throwable from
     * MediaWikiServices::getInstance()->getContentLanguage() (extreme
     * bootstrap failure scenario) and falls through to ['en'] only.
     * Forces the catch block via the stub's mockContentLanguageThrows.
     */
    public function testPreferredLanguagesFallsBackToEnWhenContentLanguageThrows(): void
    {
        \MediaWiki\MediaWikiServices::$mockContentLanguageThrows
            = new \RuntimeException('content language service unavailable');

        try {
            // Use SLUB so getProviderUrl exercises preferredLanguages().
            $manifest = $this->loadFixture('manifest-slub-v2.json');
            $file = $this->makeFile($manifest, 'slub-dresden', '384671365-19500000', '384671365-19500000.jpg');

            // No throw — the catch block absorbs it. The 'en' fallback
            // still finds the PURL metadata label (which is 'PURL' in the
            // fixture, not language-keyed).
            self::assertSame(
                'http://digital.slub-dresden.de/id384671365-19500000',
                $file->getProviderUrl()
            );
        } finally {
            \MediaWiki\MediaWikiServices::$mockContentLanguageThrows = null;
        }
    }
}
