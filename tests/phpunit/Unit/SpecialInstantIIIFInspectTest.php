<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF\Tests\Unit;

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\InstantIIIF\Hooks;
use MediaWiki\Extension\InstantIIIF\IIIFFile;
use MediaWiki\Extension\InstantIIIF\Repo;
use MediaWiki\Extension\InstantIIIF\SpecialInstantIIIFInspect;
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
        $special = new SpecialInstantIIIFInspect();
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
}
