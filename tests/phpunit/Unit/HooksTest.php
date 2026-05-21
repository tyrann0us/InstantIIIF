<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF\Tests\Unit;

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\InstantIIIF\Hooks;
use MediaWiki\Extension\InstantIIIF\IIIFFile;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\ImageHistoryList;
use MediaWiki\Page\ImagePage;
use MediaWiki\Title\Title;
use OutputPage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Skin;
use ThumbnailImage;

/**
 * Tests for Hooks: onBeforePageDisplay, onThumbnailBeforeProduceHTML,
 * onImagePageFileHistoryLine, onImagePageShowTOC, onGetExtendedMetadata.
 *
 * Covers AC 1, 4, 5, 6, 9, 12, 14, 15, 16.
 */
#[CoversClass(Hooks::class)]
class HooksTest extends TestCase
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

    // ─── Helpers ───────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function loadFixture(string $name): array
    {
        $json = file_get_contents(self::FIXTURES_DIR . $name);
        self::assertIsString($json);
        return json_decode($json, true);
    }

    /**
     * Create an IIIFFile mock with specified manifest and title.
     */
    private function makeIiifFileMock(
        string $fixture,
        string $dbKey = 'Df_dk_0007450.jpg',
        string $nsText = 'Datei',
        int $pageCount = 1,
        int $lastTransformPage = 1,
        bool $isMultipage = false,
        string $providerUrl = '',
    ): IIIFFile {

        $manifest = $this->loadFixture($fixture);
        $title = new Title($dbKey, NS_FILE, $nsText);

        $file = $this->createMock(IIIFFile::class);
        $file->method('getTitle')->willReturn($title);
        $file->method('isMultipage')->willReturn($isMultipage);
        $file->method('pageCount')->willReturn($pageCount);
        $file->method('lastTransformPage')->willReturn($lastTransformPage);
        $file->method('getProviderUrl')->willReturn($providerUrl);
        $file->method('getResolvedManifest')->willReturn([
            'provider' => 'deutsche-fotothek',
            'objectId' => 'df_dk_0007450',
            'manifestUrl' => 'https://example.org/manifest.json',
            'manifestRaw' => $manifest,
        ]);
        $file->method('getUrlForPage')->willReturnCallback(
            static fn (int $page) => "https://iiif.example/page{$page}/full/full/0/default.jpg"
        );

        return $file;
    }

    /**
     * Create a non-IIIF regular File mock.
     */
    private function makeRegularFileMock(): \File
    {
        return $this->createMock(\File::class);
    }

    // ─── AC 1: onBeforePageDisplay loads module ───────────────────

    public function testOnBeforePageDisplayAlwaysAddsModule(): void
    {
        $out = new OutputPage();
        $skin = new Skin();
        // No title set — not a file page.

        Hooks::onBeforePageDisplay($out, $skin);

        self::assertContains('ext.instantIIIF.mmvPatch', $out->modules);
    }

    public function testOnBeforePageDisplayPassesProviderUrlOnFilePage(): void
    {
        $title = new Title('Df_dk_0007450.jpg', NS_FILE, 'Datei');
        $out = new OutputPage();
        $out->setTitle($title);
        $skin = new Skin();

        // Set up RepoGroup mock to return an IIIFFile.
        $file = $this->createMock(IIIFFile::class);
        $file->method('getProviderUrl')
            ->willReturn('https://www.deutschefotothek.de/documents/obj/12345678');

        $repoGroup = $this->createMock(\RepoGroup::class);
        $repoGroup->method('findFile')->willReturn($file);
        MediaWikiServices::$mockRepoGroup = $repoGroup;

        Hooks::onBeforePageDisplay($out, $skin);

        self::assertArrayHasKey('wgIIIFProviderUrl', $out->jsConfigVars);
        self::assertSame(
            'https://www.deutschefotothek.de/documents/obj/12345678',
            $out->jsConfigVars['wgIIIFProviderUrl']
        );
    }

    public function testOnBeforePageDisplaySkipsProviderUrlForNonIiifFile(): void
    {
        $title = new Title('Regular.jpg', NS_FILE, 'File');
        $out = new OutputPage();
        $out->setTitle($title);
        $skin = new Skin();

        $repoGroup = $this->createMock(\RepoGroup::class);
        $repoGroup->method('findFile')->willReturn($this->makeRegularFileMock());
        MediaWikiServices::$mockRepoGroup = $repoGroup;

        Hooks::onBeforePageDisplay($out, $skin);

        self::assertArrayNotHasKey('wgIIIFProviderUrl', $out->jsConfigVars);
    }

    public function testOnBeforePageDisplaySkipsProviderUrlOnNonFilePage(): void
    {
        // Title is in main namespace, not NS_FILE.
        $title = new Title('Main_Page', 0, '');
        $out = new OutputPage();
        $out->setTitle($title);
        $skin = new Skin();

        Hooks::onBeforePageDisplay($out, $skin);

        self::assertArrayNotHasKey('wgIIIFProviderUrl', $out->jsConfigVars);
    }

    // ─── AC 5: onThumbnailBeforeProduceHTML — data attributes ─────

    public function testThumbnailHookAddsIiifTitle(): void
    {
        $file = $this->makeIiifFileMock('manifest-fotothek-v2.json');

        $thumb = $this->createMock(ThumbnailImage::class);
        $thumb->method('getFile')->willReturn($file);
        $thumb->method('getWidth')->willReturn(800);
        $thumb->method('getHeight')->willReturn(1100);

        $imgAttrs = [];
        $linkAttrs = false;

        Hooks::onThumbnailBeforeProduceHTML($thumb, $imgAttrs, $linkAttrs);

        // data-iiif-title: spoofed with .jpg extension
        self::assertArrayHasKey('data-iiif-title', $imgAttrs);
        self::assertSame('Datei:Df_dk_0007450.jpg.jpg', $imgAttrs['data-iiif-title']);

        // Bonus dimensions
        self::assertSame(800, $imgAttrs['data-file-width']);
        self::assertSame(1100, $imgAttrs['data-file-height']);
    }

    public function testThumbnailHookSkipsNonIiifFile(): void
    {
        $file = $this->makeRegularFileMock();

        $thumb = $this->createMock(ThumbnailImage::class);
        $thumb->method('getFile')->willReturn($file);

        $imgAttrs = [];
        $linkAttrs = false;

        $result = Hooks::onThumbnailBeforeProduceHTML($thumb, $imgAttrs, $linkAttrs);

        self::assertTrue($result);
        self::assertArrayNotHasKey('data-iiif-title', $imgAttrs);
    }

    // ─── AC 6: Multi-page data attributes ─────────────────────────

    public function testThumbnailHookAddsPageAttributesForMultipage(): void
    {
        $file = $this->makeIiifFileMock(
            'manifest-multipage-v2.json',
            isMultipage: true,
            pageCount: 3,
            lastTransformPage: 2,
        );

        $thumb = $this->createMock(ThumbnailImage::class);
        $thumb->method('getFile')->willReturn($file);
        $thumb->method('getWidth')->willReturn(600);
        $thumb->method('getHeight')->willReturn(800);

        $imgAttrs = [];
        $linkAttrs = false;

        Hooks::onThumbnailBeforeProduceHTML($thumb, $imgAttrs, $linkAttrs);

        self::assertArrayHasKey('data-iiif-page', $imgAttrs);
        self::assertSame(2, $imgAttrs['data-iiif-page']);
    }

    public function testThumbnailHookAddsFullUrlForPageGreaterThan1(): void
    {
        $file = $this->makeIiifFileMock(
            'manifest-multipage-v2.json',
            isMultipage: true,
            pageCount: 3,
            lastTransformPage: 2,
        );

        $thumb = $this->createMock(ThumbnailImage::class);
        $thumb->method('getFile')->willReturn($file);
        $thumb->method('getWidth')->willReturn(600);
        $thumb->method('getHeight')->willReturn(800);

        $imgAttrs = [];
        $linkAttrs = false;

        Hooks::onThumbnailBeforeProduceHTML($thumb, $imgAttrs, $linkAttrs);

        self::assertArrayHasKey('data-iiif-full-url', $imgAttrs);
        self::assertSame(
            'https://iiif.example/page2/full/full/0/default.jpg',
            $imgAttrs['data-iiif-full-url']
        );
    }

    public function testThumbnailHookOmitsFullUrlForPage1(): void
    {
        $file = $this->makeIiifFileMock(
            'manifest-multipage-v2.json',
            isMultipage: true,
            pageCount: 3,
            lastTransformPage: 1,
        );

        $thumb = $this->createMock(ThumbnailImage::class);
        $thumb->method('getFile')->willReturn($file);
        $thumb->method('getWidth')->willReturn(600);
        $thumb->method('getHeight')->willReturn(800);

        $imgAttrs = [];
        $linkAttrs = false;

        Hooks::onThumbnailBeforeProduceHTML($thumb, $imgAttrs, $linkAttrs);

        // data-iiif-page is set, but data-iiif-full-url is NOT set for page 1.
        self::assertArrayHasKey('data-iiif-page', $imgAttrs);
        self::assertSame(1, $imgAttrs['data-iiif-page']);
        self::assertArrayNotHasKey('data-iiif-full-url', $imgAttrs);
    }

    // ─── AC 9: data-iiif-navigate on prev/next thumbnails ─────────

    public function testThumbnailHookAddsNavigateAttributeOnSameFilePage(): void
    {
        $file = $this->makeIiifFileMock(
            'manifest-multipage-v2.json',
            isMultipage: true,
            pageCount: 3,
            lastTransformPage: 2,
        );

        // Simulate: we're on the file detail page for this same file.
        $pageTitle = new Title('Df_dk_0007450.jpg', NS_FILE, 'Datei');
        RequestContext::getMain()->setTitle($pageTitle);

        $thumb = $this->createMock(ThumbnailImage::class);
        $thumb->method('getFile')->willReturn($file);
        $thumb->method('getWidth')->willReturn(600);
        $thumb->method('getHeight')->willReturn(800);

        $imgAttrs = [];
        $linkAttrs = [
            'class' => 'mw-file-description',
            'href' => '/wiki/Datei:Df_dk_0007450.jpg?page=2',
        ];

        Hooks::onThumbnailBeforeProduceHTML($thumb, $imgAttrs, $linkAttrs);

        self::assertArrayHasKey('data-iiif-navigate', $imgAttrs);
        self::assertSame('1', $imgAttrs['data-iiif-navigate']);
    }

    public function testThumbnailHookOmitsNavigateAttributeOnDifferentFile(): void
    {
        $file = $this->makeIiifFileMock(
            'manifest-multipage-v2.json',
            isMultipage: true,
            pageCount: 3,
            lastTransformPage: 2,
        );

        // We're on a DIFFERENT file's page.
        $pageTitle = new Title('Other_File.jpg', NS_FILE, 'Datei');
        RequestContext::getMain()->setTitle($pageTitle);

        $thumb = $this->createMock(ThumbnailImage::class);
        $thumb->method('getFile')->willReturn($file);
        $thumb->method('getWidth')->willReturn(600);
        $thumb->method('getHeight')->willReturn(800);

        $imgAttrs = [];
        $linkAttrs = [
            'class' => 'mw-file-description',
            'href' => '/wiki/Datei:Df_dk_0007450.jpg?page=2',
        ];

        Hooks::onThumbnailBeforeProduceHTML($thumb, $imgAttrs, $linkAttrs);

        self::assertArrayNotHasKey('data-iiif-navigate', $imgAttrs);
    }

    // ─── AC 6: file-link href fix for multi-page ──────────────────

    public function testThumbnailHookFixesFileLinkHrefForPageGreaterThan1(): void
    {
        $file = $this->makeIiifFileMock(
            'manifest-multipage-v2.json',
            isMultipage: true,
            pageCount: 3,
            lastTransformPage: 2,
        );

        $thumb = $this->createMock(ThumbnailImage::class);
        $thumb->method('getFile')->willReturn($file);
        $thumb->method('getWidth')->willReturn(600);
        $thumb->method('getHeight')->willReturn(800);

        $imgAttrs = [];
        // file-link context: no class attribute.
        $linkAttrs = [
            'href' => 'https://iiif.example/page1/full/full/0/default.jpg',
        ];

        Hooks::onThumbnailBeforeProduceHTML($thumb, $imgAttrs, $linkAttrs);

        // href should be replaced with the correct page URL.
        self::assertSame(
            'https://iiif.example/page2/full/full/0/default.jpg',
            $linkAttrs['href']
        );
    }

    public function testThumbnailHookDoesNotFixHrefForPage1(): void
    {
        $file = $this->makeIiifFileMock(
            'manifest-multipage-v2.json',
            isMultipage: true,
            pageCount: 3,
            lastTransformPage: 1,
        );

        $thumb = $this->createMock(ThumbnailImage::class);
        $thumb->method('getFile')->willReturn($file);
        $thumb->method('getWidth')->willReturn(600);
        $thumb->method('getHeight')->willReturn(800);

        $imgAttrs = [];
        $originalHref = 'https://iiif.example/page1/full/full/0/default.jpg';
        $linkAttrs = ['href' => $originalHref];

        Hooks::onThumbnailBeforeProduceHTML($thumb, $imgAttrs, $linkAttrs);

        // Page 1 → no fix needed.
        self::assertSame($originalHref, $linkAttrs['href']);
    }

    // ─── AC 12: onImagePageFileHistoryLine — hide history ─────────

    public function testFileHistoryLineHidesForIiifFile(): void
    {
        $file = $this->createMock(IIIFFile::class);
        $out = new OutputPage();
        $historyList = new ImageHistoryList($out);

        $line = '<tr>some content</tr>';
        $css = null;

        $result = Hooks::onImagePageFileHistoryLine($historyList, $file, $line, $css);

        self::assertFalse($result);
        self::assertSame('', $line);
        // Inline style to hide the file history section header.
        self::assertNotEmpty($out->inlineStyles);
        self::assertStringContainsString('#filehistory', $out->inlineStyles[0]);
        self::assertStringContainsString('.fileInfo', $out->inlineStyles[0]);
    }

    public function testFileHistoryLinePassesThroughForRegularFile(): void
    {
        $file = $this->makeRegularFileMock();
        $out = new OutputPage();
        $historyList = new ImageHistoryList($out);

        $line = '<tr>some content</tr>';
        $css = null;

        $result = Hooks::onImagePageFileHistoryLine($historyList, $file, $line, $css);

        self::assertTrue($result);
        self::assertSame('<tr>some content</tr>', $line);
        self::assertEmpty($out->inlineStyles);
    }

    // ─── AC 12: onImagePageShowTOC — remove filehistory entry ─────

    public function testShowTOCRemovesFileHistoryForIiif(): void
    {
        $file = $this->createMock(IIIFFile::class);
        $page = new ImagePage($file);

        $toc = [
            '<a href="#filelinks">File links</a>',
            '<a href="#filehistory">File history</a>',
            '<a href="#metadata">Metadata</a>',
        ];

        Hooks::onImagePageShowTOC($page, $toc);

        self::assertCount(2, $toc);
        foreach ($toc as $entry) {
            self::assertStringNotContainsString('#filehistory', $entry);
        }
    }

    public function testShowTOCPreservesTocForRegularFile(): void
    {
        $file = $this->makeRegularFileMock();
        $page = new ImagePage($file);

        $toc = [
            '<a href="#filelinks">File links</a>',
            '<a href="#filehistory">File history</a>',
        ];
        $original = $toc;

        Hooks::onImagePageShowTOC($page, $toc);

        self::assertSame($original, $toc);
    }

    // ─── AC 14: onGetExtendedMetadata — extmetadata population ────

    public function testGetExtendedMetadataSetsDateTimeSentinel(): void
    {
        $file = $this->createMock(IIIFFile::class);
        $file->method('getResolvedManifest')->willReturn(null);
        $file->method('getProviderUrl')->willReturn('');

        $meta = [];
        $context = $this->createMock(\MediaWiki\Context\IContextSource::class);
        $maxCacheTime = null;

        Hooks::onGetExtendedMetadata($meta, $file, $context, false, $maxCacheTime);

        // DateTime sentinel '<>' suppresses the upload date in MMV.
        self::assertArrayHasKey('DateTime', $meta);
        self::assertSame('<>', $meta['DateTime']['value']);
    }

    public function testGetExtendedMetadataPopulatesFieldsFromFotothekManifest(): void
    {
        $manifest = $this->loadFixture('manifest-fotothek-v2.json');

        $file = $this->createMock(IIIFFile::class);
        $file->method('getResolvedManifest')->willReturn([
            'provider' => 'deutsche-fotothek',
            'objectId' => 'df_dk_0007450',
            'manifestUrl' => 'https://example.org/manifest.json',
            'manifestRaw' => $manifest,
        ]);
        $file->method('getProviderUrl')
            ->willReturn('https://www.deutschefotothek.de/documents/obj/12345678');

        $meta = [];
        $context = $this->createMock(\MediaWiki\Context\IContextSource::class);
        $maxCacheTime = null;

        Hooks::onGetExtendedMetadata($meta, $file, $context, false, $maxCacheTime);

        // ObjectName from manifest label.
        self::assertSame('Meißen. Rathaus', $meta['ObjectName']['value']);

        // Credit from attribution.
        self::assertSame('SLUB / Deutsche Fotothek', $meta['Credit']['value']);
        self::assertSame('SLUB / Deutsche Fotothek', $meta['Attribution']['value']);
        self::assertSame('true', $meta['AttributionRequired']['value']);

        // License: no manifest-level license → falls back to providerUrl.
        self::assertSame(
            'https://www.deutschefotothek.de/documents/obj/12345678',
            $meta['LicenseUrl']['value']
        );
        // LicenseShortName is set (either from URL or message fallback).
        self::assertArrayHasKey('LicenseShortName', $meta);
    }

    public function testGetExtendedMetadataPopulatesLicenseFromManifest(): void
    {
        $manifest = $this->loadFixture('manifest-bsb-v2.json');

        $file = $this->createMock(IIIFFile::class);
        $file->method('getResolvedManifest')->willReturn([
            'provider' => 'bsb',
            'objectId' => 'bsb10000001',
            'manifestUrl' => 'https://example.org/manifest.json',
            'manifestRaw' => $manifest,
        ]);
        $file->method('getProviderUrl')->willReturn('https://www.digitale-sammlungen.de/de/view/bsb10000001');

        $meta = [];
        $context = $this->createMock(\MediaWiki\Context\IContextSource::class);
        $maxCacheTime = null;

        Hooks::onGetExtendedMetadata($meta, $file, $context, false, $maxCacheTime);

        // License URL from manifest `license` field.
        self::assertSame(
            'https://creativecommons.org/licenses/by-nc-sa/4.0/',
            $meta['LicenseUrl']['value']
        );
        // Short name from CC URL parsing.
        self::assertSame('CC BY-NC-SA 4.0', $meta['LicenseShortName']['value']);
    }

    public function testGetExtendedMetadataCC0License(): void
    {
        $manifest = $this->loadFixture('manifest-slub-v2.json');

        $file = $this->createMock(IIIFFile::class);
        $file->method('getResolvedManifest')->willReturn([
            'provider' => 'slub',
            'objectId' => 'slub_001',
            'manifestUrl' => 'https://example.org/manifest.json',
            'manifestRaw' => $manifest,
        ]);
        $file->method('getProviderUrl')->willReturn('https://digital.slub-dresden.de/werkansicht/dlf/12345');

        $meta = [];
        $context = $this->createMock(\MediaWiki\Context\IContextSource::class);
        $maxCacheTime = null;

        Hooks::onGetExtendedMetadata($meta, $file, $context, false, $maxCacheTime);

        // Public Domain Mark from CC URL.
        self::assertSame(
            'https://creativecommons.org/publicdomain/mark/1.0/',
            $meta['LicenseUrl']['value']
        );
        self::assertSame('Public Domain', $meta['LicenseShortName']['value']);
    }

    public function testGetExtendedMetadataV3RequiredStatement(): void
    {
        $manifest = $this->loadFixture('manifest-v3.json');

        $file = $this->createMock(IIIFFile::class);
        $file->method('getResolvedManifest')->willReturn([
            'provider' => 'v3-test',
            'objectId' => 'v3_001',
            'manifestUrl' => 'https://example.org/manifest.json',
            'manifestRaw' => $manifest,
        ]);
        $file->method('getProviderUrl')->willReturn('https://example.org/object/12345');

        $meta = [];
        $context = $this->createMock(\MediaWiki\Context\IContextSource::class);
        $maxCacheTime = null;

        Hooks::onGetExtendedMetadata($meta, $file, $context, false, $maxCacheTime);

        // v3 label is a language map
        self::assertSame('Test Manifest v3', $meta['ObjectName']['value']);

        // v3 requiredStatement.value
        self::assertSame('Example Institution', $meta['Credit']['value']);

        // v3 rights field
        self::assertSame(
            'https://creativecommons.org/licenses/by-sa/4.0/',
            $meta['LicenseUrl']['value']
        );
        self::assertSame('CC BY-SA 4.0', $meta['LicenseShortName']['value']);
    }

    public function testGetExtendedMetadataSkipsNonIiifFile(): void
    {
        $file = $this->makeRegularFileMock();

        $meta = [];
        $context = $this->createMock(\MediaWiki\Context\IContextSource::class);
        $maxCacheTime = null;

        Hooks::onGetExtendedMetadata($meta, $file, $context, false, $maxCacheTime);

        self::assertEmpty($meta);
    }

    // ─── AC 14: licenseShortName edge cases ───────────────────────

    /**
     * @return array<string, array{string, string}>
     */
    public static function licenseShortNameProvider(): array
    {
        return [
            'CC BY 4.0' => [
                'https://creativecommons.org/licenses/by/4.0/',
                'CC BY 4.0',
            ],
            'CC BY-SA 3.0' => [
                'https://creativecommons.org/licenses/by-sa/3.0/',
                'CC BY-SA 3.0',
            ],
            'CC BY-NC-ND 2.0' => [
                'https://creativecommons.org/licenses/by-nc-nd/2.0/',
                'CC BY-NC-ND 2.0',
            ],
            'CC0' => [
                'https://creativecommons.org/publicdomain/zero/1.0/',
                'CC0',
            ],
            'Public Domain Mark' => [
                'https://creativecommons.org/publicdomain/mark/1.0/',
                'Public Domain',
            ],
            'RightsStatements InC' => [
                'https://rightsstatements.org/vocab/InC/1.0/',
                'InC',
            ],
            'RightsStatements NoC-US' => [
                'https://rightsstatements.org/vocab/NoC-US/1.0/',
                'NoC US',
            ],
            'Unknown URL' => [
                'https://example.org/license',
                '',
            ],
        ];
    }

    /**
     * Test licenseShortName() via reflection since it's private.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('licenseShortNameProvider')]
    public function testLicenseShortName(string $url, string $expected): void
    {
        $ref = new \ReflectionMethod(Hooks::class, 'licenseShortName');

        self::assertSame($expected, $ref->invoke(null, $url));
    }
}
