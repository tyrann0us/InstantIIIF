<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF\Tests\Unit;

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\InstantIIIF\Infrastructure\MediaWiki\IIIFFile;
use MediaWiki\Extension\InstantIIIF\Infrastructure\MediaWiki\MetadataExtractor;
use MediaWiki\Extension\InstantIIIF\Infrastructure\MediaWiki\SpecialInstantIIIFInspect;
use MediaWiki\MediaWikiServices;
use OutputPage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WebRequest;

/**
 * Tests for SpecialInstantIIIFInspect: form gating, error rendering for
 * unreachable manifests, success rendering of the metadata + canvas
 * tables.
 *
 * The SpecialPage talks to MediaWiki via a handful of overridable
 * methods (getOutput, getRequest, getContext, msg). The test stubs in
 * tests/phpunit/stubs/global-classes.php provide an injectable surface
 * for the first three, while msg() returns a trivial Message that
 * round-trips the key plus parameters as text — enough to assert on.
 */
#[CoversClass(SpecialInstantIIIFInspect::class)]
class SpecialInstantIIIFInspectTest extends TestCase
{
    private const FIXTURES_DIR = __DIR__ . '/../Fixtures/';

    protected function setUp(): void
    {
        MediaWikiServices::reset();
        RequestContext::reset();
    }

    protected function tearDown(): void
    {
        MediaWikiServices::reset();
        RequestContext::reset();
    }

    public function testKnownProviderIdsAreReturned(): void
    {
        $ids = SpecialInstantIIIFInspect::knownProviderIds();

        self::assertContains('deutsche-fotothek', $ids);
        self::assertContains('slub-dresden', $ids);
        self::assertContains('digitale-sammlungen', $ids);
    }

    public function testEmptyFormRendersOnlyIntroAndForm(): void
    {
        $special = $this->makeSpecial();

        $special->execute(null);

        $out = $special->injectedOutput;
        self::assertNotNull($out);
        self::assertSame([['key' => 'instantiiif-inspect-intro', 'params' => []]], $out->wikiMessages);
        // No results section header was emitted because no URL was submitted.
        self::assertStringNotContainsString('instantiiif-inspect-results-title', $out->html);
    }

    public function testInvalidUrlIsIgnoredAndNoResultsRendered(): void
    {
        $special = $this->makeSpecial();
        $special->injectedRequest->setParams([
            'wpManifestUrl' => 'not-an-http-url',
        ]);

        $special->execute(null);

        self::assertStringNotContainsString('instantiiif-inspect-results-title', $special->injectedOutput->html);
        self::assertStringNotContainsString('instantiiif-inspect-fetch-failed', $special->injectedOutput->html);
    }

    public function testUnreachableManifestRendersErrorBox(): void
    {
        $special = $this->makeSpecialWithCannedManifest(null);
        $special->injectedRequest->setParams([
            'wpManifestUrl' => 'https://nonexistent.example/manifest.json',
        ]);

        $special->execute(null);

        $html = $special->injectedOutput->html;
        self::assertStringContainsString('errorbox', $html);
        self::assertStringContainsString(
            'instantiiif-inspect-fetch-failed',
            $html,
            'Error box message key should reach the output.'
        );
    }

    public function testSuccessRendersSummaryAndCanvasTable(): void
    {
        $manifest = $this->loadFixture('manifest-fotothek-v2.json');
        $special = $this->makeSpecialWithCannedManifest($manifest);
        $special->injectedRequest->setParams([
            'wpManifestUrl' => 'https://example.org/manifest.json',
            'wpProviderId' => 'deutsche-fotothek',
        ]);

        $special->execute(null);

        $html = $special->injectedOutput->html;
        self::assertStringContainsString('instantiiif-inspect-results-title', $html);
        // Provider-id surfaces.
        self::assertStringContainsString('deutsche-fotothek', $html);
        // Page count surfaces.
        self::assertStringContainsString('instantiiif-inspect-row-page-count', $html);
        // Canvas table header.
        self::assertStringContainsString('instantiiif-inspect-canvases-title', $html);
        // Canvas service ID surfaces (from the fixture's first canvas).
        self::assertStringContainsString('iiif.arthistoricum.net', $html);
    }

    public function testMultipageManifestEnumeratesAllCanvases(): void
    {
        $manifest = $this->loadFixture('manifest-multipage-v2.json');
        $special = $this->makeSpecialWithCannedManifest($manifest);
        $special->injectedRequest->setParams([
            'wpManifestUrl' => 'https://example.org/multi/manifest.json',
        ]);

        $special->execute(null);

        $html = $special->injectedOutput->html;
        // Verify >1 <tr> rows in canvas table (one per page + header).
        $rowCount = substr_count($html, '<tr>');
        self::assertGreaterThan(
            2,
            $rowCount,
            'Multi-page manifest should render a canvas-table row per page.'
        );
    }

    /* -------------------- Helpers -------------------- */

    private function makeSpecial(): SpecialInstantIIIFInspect
    {
        $special = new SpecialInstantIIIFInspect(
            new \RepoGroup(),
            new \GlobalVarConfig(),
            new MetadataExtractor(new \Language('en'))
        );
        $special->injectedRequest = new WebRequest();
        $special->injectedOutput = new OutputPage();
        return $special;
    }

    /**
     * Build a SpecialInstantIIIFInspect whose synthetic IIIFFile returns
     * the given manifest array (or simulates a failed fetch when null).
     *
     * We can't override IIIFFile construction inside the subject under
     * test without subclassing, so this variant subclasses
     * SpecialInstantIIIFInspect, overriding the protected
     * buildInspectorFile() hook — except… that method is private.
     * Instead we lean on Hooks + a stub fetchTextCached on a subclass of
     * IIIFFile reflected back via an anonymous class. The simplest path
     * is to patch IIIFFile's HTTP fetch globally for the test session by
     * shadowing $services->getHttpRequestFactory().
     */
    private function makeSpecialWithCannedManifest(?array $manifest): SpecialInstantIIIFInspect
    {
        // Stub the HttpRequestFactory used by IIIFFile::fetchTextCached
        // so it returns the canned JSON without hitting the network.
        $factory = new class ($manifest) extends \MediaWiki\Http\HttpRequestFactory {
            private ?string $body;

            public function __construct(?array $manifest)
            {
                $this->body = $manifest !== null ? json_encode($manifest) : null;
            }

            public function create(string $url, array $options = [], ?string $caller = null): \MWHttpRequest
            {
                $body = $this->body;
                return new class ($body) extends \MWHttpRequest {
                    public function __construct(private ?string $body)
                    {
                    }
                    public function execute(): \StatusValue
                    {
                        return new \StatusValue($this->body !== null);
                    }
                    public function getContent(): string
                    {
                        return (string) $this->body;
                    }
                };
            }
        };
        MediaWikiServices::$mockHttpRequestFactory = $factory;

        return $this->makeSpecial();
    }

    /**
     * @return array<string, mixed>
     */
    private function loadFixture(string $name): array
    {
        $json = file_get_contents(self::FIXTURES_DIR . $name);
        self::assertIsString($json);
        return json_decode($json, true);
    }

    public function testGetDescriptionReturnsAMessage(): void
    {
        $special = $this->makeSpecial();

        self::assertInstanceOf(\Message::class, $special->getDescription());
    }

    public function testGetGroupNameIsWiki(): void
    {
        $special = $this->makeSpecial();
        $ref = new \ReflectionMethod($special, 'getGroupName');

        self::assertSame('wiki', $ref->invoke($special));
    }

    /**
     * `metaValue()` is a private helper that surfaces the
     * NO_TIMESTAMP_SENTINEL as the human-readable "(suppressed)" string
     * and skips non-array entries. Exercise both via reflection so the
     * private logic stays guarded.
     */
    public function testMetaValueRendersSuppressedForNoTimestampSentinel(): void
    {
        $special = $this->makeSpecial();
        $ref = new \ReflectionMethod($special, 'metaValue');

        $meta = [
            'DateTime' => ['value' => IIIFFile::NO_TIMESTAMP_SENTINEL, 'source' => 'extension'],
        ];

        self::assertSame('(suppressed)', $ref->invoke($special, $meta, 'DateTime'));
    }

    public function testMetaValueReturnsEmptyWhenEntryNotArray(): void
    {
        $special = $this->makeSpecial();
        $ref = new \ReflectionMethod($special, 'metaValue');

        // Entry is a string (or anything not an array) → early return.
        self::assertSame('', $ref->invoke($special, ['LicenseUrl' => 'not-an-array'], 'LicenseUrl'));
        // Key missing entirely → also empty.
        self::assertSame('', $ref->invoke($special, [], 'LicenseUrl'));
    }

    /**
     * `formatValue()` is the inspector's per-cell rendering hook. Each
     * shape — empty, URL, plain text — picks a different HTML element.
     */
    public function testFormatValueRendersPlaceholderForEmptyString(): void
    {
        $special = $this->makeSpecial();
        $ref = new \ReflectionMethod($special, 'formatValue');

        $html = $ref->invoke($special, '');

        self::assertStringContainsString('<em>', $html);
        self::assertStringContainsString('instantiiif-inspect-empty', $html);
    }

    /**
     * `renderCanvasTable()` short-circuits to an empty string when the
     * resolved manifest has no canvases (the v2 fallback for malformed
     * manifests). Exercise the branch so a regression there doesn't
     * silently leave the inspector emitting a bare `<h3>` followed by an
     * empty `<table>`.
     */
    public function testRenderCanvasTableReturnsEmptyWhenPageCountIsZero(): void
    {
        $special = $this->makeSpecialWithCannedManifest(['no' => 'canvases']);
        $ref = new \ReflectionMethod($special, 'renderCanvasTable');

        $file = (new \ReflectionMethod($special, 'buildInspectorFile'))
            ->invoke($special, 'https://example.org/manifest.json', 'inspector');

        self::assertSame('', $ref->invoke($special, $file));
    }

    /**
     * `buildInspectorFile()` throws if `Title::makeTitleSafe(NS_FILE,
     * INSPECT_DBKEY)` returns null. In real MediaWiki this can't happen
     * for a fixed valid dbkey, but the guard exists as belt-and-braces —
     * defend the contract so we'd hear about it if MW ever changes.
     */
    public function testBuildInspectorFileThrowsWhenTitleConstructionFails(): void
    {
        $special = $this->makeSpecial();
        \MediaWiki\Title\Title::$mockMakeTitleSafeReturnsNull = true;

        try {
            $ref = new \ReflectionMethod($special, 'buildInspectorFile');
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Failed to build inspector title');
            $ref->invoke($special, 'https://example.org/manifest.json', 'inspector');
        } finally {
            \MediaWiki\Title\Title::$mockMakeTitleSafeReturnsNull = false;
        }
    }
}
