<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF\Tests\Unit\Domain;

use MediaWiki\Extension\InstantIIIF\Domain\Manifest;
use MediaWiki\Extension\InstantIIIF\Domain\Page;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Manifest::class)]
class ManifestTest extends TestCase
{
    private const FIXTURES_DIR = __DIR__ . '/../../Fixtures/';

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

    /* -------------------- pageCount -------------------- */

    public function testPageCountSinglePageFotothek(): void
    {
        $manifest = Manifest::from($this->loadFixture('manifest-fotothek-v2.json'));
        self::assertSame(1, $manifest->pageCount());
    }

    public function testPageCountMultipageBsb(): void
    {
        $manifest = Manifest::from($this->loadFixture('manifest-multipage-v2.json'));
        self::assertSame(622, $manifest->pageCount());
    }

    public function testPageCountEmptyManifest(): void
    {
        self::assertSame(0, Manifest::from([])->pageCount());
    }

    /* -------------------- imageServiceIdFor -------------------- */

    public function testImageServiceIdV2Fotothek(): void
    {
        $manifest = Manifest::from($this->loadFixture('manifest-fotothek-v2.json'));
        $id = $manifest->imageServiceIdFor(Page::normalize(1));
        self::assertIsString($id);
        self::assertStringContainsString('df_dk_0007450', $id);
    }

    public function testImageServiceIdV3Homepage(): void
    {
        $manifest = Manifest::from($this->loadFixture('manifest-v3.json'));
        $id = $manifest->imageServiceIdFor(Page::normalize(1));
        self::assertIsString($id);
        self::assertStringContainsString('example.org/iiif/image/v3/img001', $id);
    }

    public function testImageServiceIdMultipagePerCanvas(): void
    {
        $manifest = Manifest::from($this->loadFixture('manifest-multipage-v2.json'));

        $id1 = $manifest->imageServiceIdFor(Page::normalize(1));
        $id2 = $manifest->imageServiceIdFor(Page::normalize(2));

        self::assertNotNull($id1);
        self::assertNotNull($id2);
        self::assertNotSame($id1, $id2);
        self::assertStringContainsString('bsb11610364_00001', $id1);
        self::assertStringContainsString('bsb11610364_00002', $id2);
    }

    public function testImageServiceIdOutOfRangeReturnsNull(): void
    {
        $manifest = Manifest::from($this->loadFixture('manifest-fotothek-v2.json'));
        self::assertNull($manifest->imageServiceIdFor(Page::normalize(99)));
    }

    public function testImageServiceIdEmptyManifestReturnsNull(): void
    {
        self::assertNull(Manifest::from([])->imageServiceIdFor(Page::normalize(1)));
    }

    public function testImageServiceIdStripsTrailingSlash(): void
    {
        // Construct a manifest where service @id has a trailing slash.
        $raw = [
            '@context' => 'http://iiif.io/api/presentation/2/context.json',
            'sequences' => [['canvases' => [
                [
                    'width' => 100,
                    'height' => 100,
                    'images' => [['resource' => ['service' => ['@id' => 'https://example.org/svc/']]]],
                ],
            ]]],
        ];
        self::assertSame(
            'https://example.org/svc',
            Manifest::from($raw)->imageServiceIdFor(Page::normalize(1))
        );
    }

    /* -------------------- canvasDimensionsFor -------------------- */

    public function testCanvasDimensions(): void
    {
        $manifest = Manifest::from($this->loadFixture('manifest-multipage-v2.json'));
        $dims = $manifest->canvasDimensionsFor(Page::normalize(1));
        self::assertSame(1768, $dims->width);
        self::assertSame(2536, $dims->height);
    }

    public function testCanvasDimensionsOutOfRangeUnknown(): void
    {
        $manifest = Manifest::from($this->loadFixture('manifest-fotothek-v2.json'));
        $dims = $manifest->canvasDimensionsFor(Page::normalize(99));
        self::assertFalse($dims->isKnown());
    }

    /* -------------------- landingUrl (v3 homepage / v2 related) -------------------- */

    public function testLandingUrlFromV3Homepage(): void
    {
        $manifest = Manifest::from($this->loadFixture('manifest-v3.json'));
        self::assertSame('https://example.org/object/12345', $manifest->landingUrl());
    }

    public function testLandingUrlFromV2RelatedList(): void
    {
        $manifest = Manifest::from($this->loadFixture('manifest-bsb-v2.json'));
        // BSB's `related` is a list; first entry is the "Details" landing.
        self::assertSame('https://mdz-nbn-resolving.de/details:bsb00127289', $manifest->landingUrl());
    }

    public function testLandingUrlReturnsNullWhenAbsent(): void
    {
        // SLUB has no homepage/related; only metadata-embedded link.
        $manifest = Manifest::from($this->loadFixture('manifest-slub-v2.json'));
        self::assertNull($manifest->landingUrl());
    }

    public function testLandingUrlReturnsNullForEmptyManifest(): void
    {
        self::assertNull(Manifest::from([])->landingUrl());
    }

    /* -------------------- findUrlInMetadataByLabels -------------------- */

    public function testFindUrlInMetadataMatchesLabelInString(): void
    {
        $manifest = Manifest::from($this->loadFixture('manifest-fotothek-v2.json'));
        // Fotothek embeds the landing URL behind metadata label "Link zum Werk".
        self::assertSame(
            'http://www.deutschefotothek.de/documents/obj/90062808',
            $manifest->findUrlInMetadataByLabels(['Link zum Werk'])
        );
    }

    public function testFindUrlInMetadataExtractsFromHtmlValue(): void
    {
        $manifest = Manifest::from($this->loadFixture('manifest-slub-v2.json'));
        // SLUB embeds the URL inside an HTML <a href="…"> under "PURL".
        self::assertSame(
            'http://digital.slub-dresden.de/id384671365-19500000',
            $manifest->findUrlInMetadataByLabels(['PURL', 'Persistent URL'])
        );
    }

    public function testFindUrlInMetadataIsCaseInsensitive(): void
    {
        $raw = [
            'metadata' => [
                ['label' => 'Source URL', 'value' => 'https://example.org/source'],
            ],
        ];
        self::assertSame(
            'https://example.org/source',
            Manifest::from($raw)->findUrlInMetadataByLabels(['source url'])
        );
    }

    public function testFindUrlInMetadataReturnsEmptyWhenLabelMissing(): void
    {
        $manifest = Manifest::from($this->loadFixture('manifest-fotothek-v2.json'));
        self::assertSame('', $manifest->findUrlInMetadataByLabels(['NotPresent']));
    }

    public function testFindUrlInMetadataNoMetadataField(): void
    {
        self::assertSame('', Manifest::from([])->findUrlInMetadataByLabels(['Any']));
    }

    /* -------------------- isError -------------------- */

    public function testIsErrorRecognisesV2ErrorLabel(): void
    {
        self::assertTrue(Manifest::from(['label' => 'error: not found'])->isError());
    }

    public function testIsErrorRecognisesV2ErrorCanvasId(): void
    {
        $raw = ['sequences' => [['canvases' => [['@id' => 'error/123']]]]];
        self::assertTrue(Manifest::from($raw)->isError());
    }

    public function testIsErrorFalseForRealManifest(): void
    {
        $manifest = Manifest::from($this->loadFixture('manifest-fotothek-v2.json'));
        self::assertFalse($manifest->isError());
    }

    /* -------------------- raw field accessors -------------------- */

    public function testRawLabel(): void
    {
        $manifest = Manifest::from(['label' => 'Hello']);
        self::assertSame('Hello', $manifest->rawLabel());
    }

    public function testRawLicenseV2(): void
    {
        $manifest = Manifest::from(['license' => 'https://example.org/lic']);
        self::assertSame('https://example.org/lic', $manifest->rawLicense());
    }

    public function testRawLicenseV3RightsPreferred(): void
    {
        $manifest = Manifest::from([
            'rights' => 'https://example.org/rights',
            'license' => 'https://example.org/lic',
        ]);
        self::assertSame('https://example.org/rights', $manifest->rawLicense());
    }

    public function testRawAttributionV2Field(): void
    {
        $manifest = Manifest::from(['attribution' => 'Some institution']);
        self::assertSame('Some institution', $manifest->rawAttribution());
    }

    public function testRawAttributionFromV3RequiredStatement(): void
    {
        $manifest = Manifest::from([
            'requiredStatement' => ['value' => ['en' => ['Required statement']]],
        ]);
        self::assertSame(['en' => ['Required statement']], $manifest->rawAttribution());
    }

    public function testRawAttributionEmpty(): void
    {
        self::assertSame('', Manifest::from([])->rawAttribution());
    }

    public function testRawAttributionEmptyStringFallsThroughToRequiredStatement(): void
    {
        // attribution='' must NOT short-circuit; requiredStatement should win.
        $manifest = Manifest::from([
            'attribution' => '',
            'requiredStatement' => ['value' => 'Provided by example'],
        ]);
        self::assertSame('Provided by example', $manifest->rawAttribution());
    }

    /* -------------------- imageServiceIdFor extra branches -------------------- */

    public function testImageServiceIdV2FallbackFromResourceIdRegex(): void
    {
        // v2 canvas where resource has NO service object — derive base from @id.
        $raw = [
            'sequences' => [['canvases' => [[
                'width' => 100,
                'height' => 100,
                'images' => [['resource' => [
                    '@id' => 'https://example.org/iiif/2/abc123/full/full/0/default.jpg',
                ]]],
            ]]]],
        ];
        self::assertSame(
            'https://example.org/iiif/2/abc123',
            Manifest::from($raw)->imageServiceIdFor(Page::normalize(1))
        );
    }

    public function testImageServiceIdFromServiceList(): void
    {
        // v2 canvas where `service` is a list of services rather than an object.
        $raw = [
            'sequences' => [['canvases' => [[
                'width' => 100,
                'height' => 100,
                'images' => [['resource' => ['service' => [
                    ['@id' => 'https://example.org/svc-list-id'],
                ]]]],
            ]]]],
        ];
        self::assertSame(
            'https://example.org/svc-list-id',
            Manifest::from($raw)->imageServiceIdFor(Page::normalize(1))
        );
    }

    public function testImageServiceIdFromV3PlainIdKey(): void
    {
        // v3 service keyed by plain `id` (no @-prefix).
        $raw = [
            'items' => [[
                'items' => [['items' => [['body' => [
                    'service' => ['id' => 'https://example.org/v3-svc'],
                ]]]]],
            ]],
        ];
        self::assertSame(
            'https://example.org/v3-svc',
            Manifest::from($raw)->imageServiceIdFor(Page::normalize(1))
        );
    }

    public function testImageServiceIdFromServiceListWithPlainIdKey(): void
    {
        // Service list whose first entry uses plain `id`.
        $raw = [
            'items' => [[
                'items' => [['items' => [['body' => [
                    'service' => [
                        ['id' => 'https://example.org/v3-svc-list'],
                    ],
                ]]]]],
            ]],
        ];
        self::assertSame(
            'https://example.org/v3-svc-list',
            Manifest::from($raw)->imageServiceIdFor(Page::normalize(1))
        );
    }

    /* -------------------- landingUrl extra branches -------------------- */

    public function testLandingUrlFromHomepageObjectAtId(): void
    {
        $manifest = Manifest::from([
            'homepage' => ['@id' => 'https://example.org/home-atid'],
        ]);
        self::assertSame('https://example.org/home-atid', $manifest->landingUrl());
    }

    public function testLandingUrlFromHomepageListOfObjectsWithPlainId(): void
    {
        $manifest = Manifest::from([
            'homepage' => [
                ['id' => 'https://example.org/home-list-id'],
            ],
        ]);
        self::assertSame('https://example.org/home-list-id', $manifest->landingUrl());
    }

    public function testLandingUrlFallsThroughFromNonHttpHomepageToRelated(): void
    {
        $manifest = Manifest::from([
            'homepage' => 'mailto:curator@example.org',
            'related' => 'https://example.org/related-fallback',
        ]);
        self::assertSame('https://example.org/related-fallback', $manifest->landingUrl());
    }

    /* -------------------- findUrlInMetadataByLabels extra branches -------------------- */

    public function testFindUrlInMetadataReturnsEmptyWhenMetadataIsScalar(): void
    {
        $manifest = Manifest::from(['metadata' => 'not-an-array']);
        self::assertSame('', $manifest->findUrlInMetadataByLabels(['Any']));
    }

    public function testFindUrlInMetadataSkipsNonArrayEntries(): void
    {
        $manifest = Manifest::from([
            'metadata' => [
                'junk-scalar',
                ['label' => 'Source URL', 'value' => 'https://example.org/after-junk'],
            ],
        ]);
        self::assertSame(
            'https://example.org/after-junk',
            $manifest->findUrlInMetadataByLabels(['Source URL'])
        );
    }

    public function testFindUrlInMetadataResolvesLanguageMapValue(): void
    {
        // v3 language-map value: requires LocalizedText::resolve + re-parse.
        $manifest = Manifest::from([
            'metadata' => [[
                'label' => ['en' => ['Source URL']],
                'value' => ['en' => ['https://example.org/lang-map-url']],
            ]],
        ]);
        self::assertSame(
            'https://example.org/lang-map-url',
            $manifest->findUrlInMetadataByLabels(['Source URL'], ['en'])
        );
    }

    public function testFindUrlInMetadataFallsBackToListRecursion(): void
    {
        // Value is a plain list (not a language map) — the foreach-recursion
        // branch must dig into entries until it finds an http(s) URL.
        $manifest = Manifest::from([
            'metadata' => [[
                'label' => 'Refs',
                'value' => ['no-url-here', 'https://example.org/recursed'],
            ]],
        ]);
        self::assertSame(
            'https://example.org/recursed',
            $manifest->findUrlInMetadataByLabels(['Refs'])
        );
    }

    /* -------------------- canvases array_filter defensive branches -------------------- */

    public function testPageCountSkipsNonArrayV3Items(): void
    {
        $raw = [
            'items' => [
                ['width' => 10, 'height' => 10],
                'junk-string',
                ['width' => 20, 'height' => 20],
            ],
        ];
        self::assertSame(2, Manifest::from($raw)->pageCount());
    }

    public function testPageCountSkipsNonArrayV2Canvases(): void
    {
        $raw = [
            'sequences' => [['canvases' => [
                ['width' => 10, 'height' => 10],
                42,
                ['width' => 20, 'height' => 20],
                ['width' => 30, 'height' => 30],
            ]]],
        ];
        self::assertSame(3, Manifest::from($raw)->pageCount());
    }
}
