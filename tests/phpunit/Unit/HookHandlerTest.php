<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF\Tests\Unit;

use MediaWiki\Context\IContextSource;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\InstantIIIF\HookHandler;
use MediaWiki\Extension\InstantIIIF\IIIFFile;
use MediaWiki\Extension\InstantIIIF\MetadataExtractor;
use MediaWiki\Extension\InstantIIIF\Repo;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use OutputPage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Skin;
use ThumbnailImage;

/**
 * Tests for HookHandler: onBeforePageDisplay, onThumbnailBeforeProduceHTML,
 * onImagePageFileHistoryLine, onImagePageShowTOC, onGetExtendedMetadata.
 */
#[CoversClass(HookHandler::class)]
class HookHandlerTest extends TestCase
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

    private function makeHandler(?\RepoGroup $repoGroup = null): HookHandler
    {
        return new HookHandler(
            $repoGroup ?? new \RepoGroup(),
            new \NamespaceInfo(),
            new MetadataExtractor(new \Language('en'))
        );
    }

    private function makeContext(): IContextSource
    {
        $context = $this->createStub(IContextSource::class);
        $context->method('msg')->willReturnCallback(
            static fn (string $key) => new \Message($key)
        );
        return $context;
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

    /**
     * Create an IIIFFile mock with specified manifest and title.
     */
    private function makeIiifFileMock(
        string $fixture,
        string $dbKey = 'Df_dk_0007450',
        string $nsText = 'File',
        int $pageCount = 1,
        int $lastTransformPage = 1,
        bool $isMultipage = false,
        string $providerUrl = '',
        int $fileWidth = 1600,
        int $fileHeight = 1324,
    ): IIIFFile {

        $manifest = $this->loadFixture($fixture);
        $title = new Title($dbKey, NS_FILE, $nsText);

        $file = $this->createStub(IIIFFile::class);
        $file->method('getTitle')->willReturn($title);
        $file->method('isMultipage')->willReturn($isMultipage);
        $file->method('pageCount')->willReturn($pageCount);
        $file->method('lastTransformPage')->willReturn($lastTransformPage);
        $file->method('getProviderUrl')->willReturn($providerUrl);
        $file->method('getWidth')->willReturn($fileWidth);
        $file->method('getHeight')->willReturn($fileHeight);
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
        return $this->createStub(\File::class);
    }

    // ─── onBeforePageDisplay loads module ───────────────────

    public function testOnBeforePageDisplayAlwaysAddsModule(): void
    {
        $out = new OutputPage();
        $skin = new Skin();
        // No title set — not a file page.

        $this->makeHandler()->onBeforePageDisplay($out, $skin);

        self::assertContains('ext.instantIIIF.mmvPatch', $out->modules);
    }

    public function testOnBeforePageDisplayAddsMediaSearchModuleWhenIIIFRepoIsConfigured(): void
    {
        $out = new OutputPage();
        $skin = new Skin();

        $iiifRepo = new Repo([
            'name' => 'iiif',
            'class' => Repo::class,
            'directory' => '/tmp/iiif',
            'iiifSources' => [
                ['id' => 'fotothek', 'idPattern' => '/^(df_.+)$/'],
                ['id' => 'slub', 'idPattern' => '/^([0-9].+)$/'],
            ],
        ]);

        $repoGroup = $this->createStub(\RepoGroup::class);
        $repoGroup->method('forEachForeignRepo')
            ->willReturnCallback(static function (callable $cb) use ($iiifRepo): bool {
                $cb($iiifRepo);
                return false;
            });

        $this->makeHandler($repoGroup)->onBeforePageDisplay($out, $skin);

        self::assertContains('ext.instantIIIF.mediaSearch', $out->modules);
        self::assertArrayHasKey('wgInstantIIIFRepos', $out->jsConfigVars);
        self::assertSame(
            [[
                'apiurl' => 'https://wiki.example.org/w/api.php',
                'idPatterns' => ['/^(df_.+)$/', '/^([0-9].+)$/'],
            ]],
            $out->jsConfigVars['wgInstantIIIFRepos']
        );
    }

    public function testOnBeforePageDisplaySkipsMediaSearchModuleWithoutIIIFRepo(): void
    {
        $out = new OutputPage();
        $skin = new Skin();

        $repoGroup = $this->createStub(\RepoGroup::class);
        $repoGroup->method('forEachForeignRepo')->willReturn(false);

        $this->makeHandler($repoGroup)->onBeforePageDisplay($out, $skin);

        self::assertNotContains('ext.instantIIIF.mediaSearch', $out->modules);
        self::assertArrayNotHasKey('wgInstantIIIFRepos', $out->jsConfigVars);
    }

    public function testOnBeforePageDisplaySkipsNonIIIFForeignRepos(): void
    {
        $out = new OutputPage();
        $skin = new Skin();

        $foreignNonIIIF = new \FileRepo(['name' => 'wikimediacommons']);

        $repoGroup = $this->createStub(\RepoGroup::class);
        $repoGroup->method('forEachForeignRepo')
            ->willReturnCallback(static function (callable $cb) use ($foreignNonIIIF): bool {
                $cb($foreignNonIIIF);
                return false;
            });

        $this->makeHandler($repoGroup)->onBeforePageDisplay($out, $skin);

        self::assertNotContains('ext.instantIIIF.mediaSearch', $out->modules);
        self::assertArrayNotHasKey('wgInstantIIIFRepos', $out->jsConfigVars);
    }

    public function testOnBeforePageDisplayPassesProviderUrlAndStylesOnFilePage(): void
    {
        $title = new Title('Df_dk_0007450', NS_FILE, 'File');
        $out = new OutputPage();
        $out->setTitle($title);
        $skin = new Skin();

        $file = $this->createStub(IIIFFile::class);
        $file->method('getProviderUrl')
            ->willReturn('https://www.deutschefotothek.de/documents/obj/12345678');

        $repoGroup = $this->createStub(\RepoGroup::class);
        $repoGroup->method('findFile')->willReturn($file);

        $this->makeHandler($repoGroup)->onBeforePageDisplay($out, $skin);

        // Provider URL passed to JS so the shared-upload link can be fixed.
        self::assertArrayHasKey('wgIIIFProviderUrl', $out->jsConfigVars);
        self::assertSame(
            'https://www.deutschefotothek.de/documents/obj/12345678',
            $out->jsConfigVars['wgIIIFProviderUrl']
        );

        // Style module that hides the meaningless file-history section.
        self::assertContains('ext.instantIIIF.filePage', $out->moduleStyles);
    }

    public function testOnBeforePageDisplaySkipsProviderUrlForNonIiifFile(): void
    {
        $title = new Title('Regular.jpg', NS_FILE, 'File');
        $out = new OutputPage();
        $out->setTitle($title);
        $skin = new Skin();

        $repoGroup = $this->createStub(\RepoGroup::class);
        $repoGroup->method('findFile')->willReturn($this->makeRegularFileMock());

        $this->makeHandler($repoGroup)->onBeforePageDisplay($out, $skin);

        self::assertArrayNotHasKey('wgIIIFProviderUrl', $out->jsConfigVars);
        self::assertNotContains('ext.instantIIIF.filePage', $out->moduleStyles);
    }

    public function testOnBeforePageDisplaySkipsProviderUrlOnNonFilePage(): void
    {
        // Title is in main namespace, not NS_FILE.
        $title = new Title('Main_Page', 0, '');
        $out = new OutputPage();
        $out->setTitle($title);
        $skin = new Skin();

        $this->makeHandler()->onBeforePageDisplay($out, $skin);

        self::assertArrayNotHasKey('wgIIIFProviderUrl', $out->jsConfigVars);
    }

    // ─── onThumbnailBeforeProduceHTML — data attributes ─────

    public function testThumbnailHookAddsIiifTitle(): void
    {
        // File reports 1600x1324 (its full dimensions); the rendered thumb is
        // only 800x550 (a clamped article preview). data-file-width must be
        // the FILE's value — MMV's lightboximage.js caps the requested
        // lightbox thumb at data-file-width, so using the thumb's clamped
        // width would make MMV show a tiny image.
        $file = $this->makeIiifFileMock(
            'manifest-fotothek-v2.json',
            fileWidth: 1600,
            fileHeight: 1324,
        );

        $thumb = $this->createStub(ThumbnailImage::class);
        $thumb->method('getFile')->willReturn($file);
        $thumb->method('getWidth')->willReturn(800);
        $thumb->method('getHeight')->willReturn(550);

        $imgAttrs = [];
        $linkAttrs = false;

        $this->makeHandler()->onThumbnailBeforeProduceHTML($thumb, $imgAttrs, $linkAttrs);

        // data-iiif-title carries the spoofed ".jpg" that MMV requires
        // to recognise the file (the canonical wiki dbkey is extension-less).
        self::assertArrayHasKey('data-iiif-title', $imgAttrs);
        self::assertSame('File:Df_dk_0007450.jpg', $imgAttrs['data-iiif-title']);

        // The FILE's full dimensions (not the thumb's clamped 800x550).
        self::assertSame(1600, $imgAttrs['data-file-width']);
        self::assertSame(1324, $imgAttrs['data-file-height']);
    }

    public function testThumbnailHookAppendsJpgToExtensionlessTitle(): void
    {
        // Provider IDs are usually extensionless (e.g.
        // "Bsb11610364"). We must append ".jpg" so MultimediaViewer
        // recognises the file as an image and opens the overlay.
        $file = $this->makeIiifFileMock(
            'manifest-bsb-v2.json',
            dbKey: 'Bsb11610364',
            nsText: 'File',
        );

        $thumb = $this->createStub(ThumbnailImage::class);
        $thumb->method('getFile')->willReturn($file);
        $thumb->method('getWidth')->willReturn(300);
        $thumb->method('getHeight')->willReturn(400);

        $imgAttrs = [];
        $linkAttrs = false;

        $this->makeHandler()->onThumbnailBeforeProduceHTML($thumb, $imgAttrs, $linkAttrs);

        self::assertSame('File:Bsb11610364.jpg', $imgAttrs['data-iiif-title']);
    }

    public function testThumbnailHookSkipsNonIiifFile(): void
    {
        $file = $this->makeRegularFileMock();

        $thumb = $this->createStub(ThumbnailImage::class);
        $thumb->method('getFile')->willReturn($file);

        $imgAttrs = [];
        $linkAttrs = false;

        $result = $this->makeHandler()->onThumbnailBeforeProduceHTML($thumb, $imgAttrs, $linkAttrs);

        self::assertTrue($result);
        self::assertArrayNotHasKey('data-iiif-title', $imgAttrs);
    }

    // ─── Multi-page data attributes ─────────────────────────

    public function testThumbnailHookAddsPageAttributesForMultipage(): void
    {
        $file = $this->makeIiifFileMock(
            'manifest-multipage-v2.json',
            isMultipage: true,
            pageCount: 3,
            lastTransformPage: 2,
        );

        $thumb = $this->createStub(ThumbnailImage::class);
        $thumb->method('getFile')->willReturn($file);
        $thumb->method('getWidth')->willReturn(600);
        $thumb->method('getHeight')->willReturn(800);

        $imgAttrs = [];
        $linkAttrs = false;

        $this->makeHandler()->onThumbnailBeforeProduceHTML($thumb, $imgAttrs, $linkAttrs);

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

        $thumb = $this->createStub(ThumbnailImage::class);
        $thumb->method('getFile')->willReturn($file);
        $thumb->method('getWidth')->willReturn(600);
        $thumb->method('getHeight')->willReturn(800);

        $imgAttrs = [];
        $linkAttrs = false;

        $this->makeHandler()->onThumbnailBeforeProduceHTML($thumb, $imgAttrs, $linkAttrs);

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

        $thumb = $this->createStub(ThumbnailImage::class);
        $thumb->method('getFile')->willReturn($file);
        $thumb->method('getWidth')->willReturn(600);
        $thumb->method('getHeight')->willReturn(800);

        $imgAttrs = [];
        $linkAttrs = false;

        $this->makeHandler()->onThumbnailBeforeProduceHTML($thumb, $imgAttrs, $linkAttrs);

        // data-iiif-page is set, but data-iiif-full-url is NOT set for page 1.
        self::assertArrayHasKey('data-iiif-page', $imgAttrs);
        self::assertSame(1, $imgAttrs['data-iiif-page']);
        self::assertArrayNotHasKey('data-iiif-full-url', $imgAttrs);
    }

    // ─── data-iiif-navigate on prev/next thumbnails ─────────

    public function testThumbnailHookAddsNavigateAttributeOnSameFilePage(): void
    {
        $file = $this->makeIiifFileMock(
            'manifest-multipage-v2.json',
            isMultipage: true,
            pageCount: 3,
            lastTransformPage: 2,
        );

        // Simulate: we're on the file detail page for this same file.
        $pageTitle = new Title('Df_dk_0007450', NS_FILE, 'File');
        RequestContext::getMain()->setTitle($pageTitle);

        $thumb = $this->createStub(ThumbnailImage::class);
        $thumb->method('getFile')->willReturn($file);
        $thumb->method('getWidth')->willReturn(600);
        $thumb->method('getHeight')->willReturn(800);

        $imgAttrs = [];
        $linkAttrs = [
            'class' => 'mw-file-description',
            'href' => '/wiki/File:Df_dk_0007450?page=2',
        ];

        $this->makeHandler()->onThumbnailBeforeProduceHTML($thumb, $imgAttrs, $linkAttrs);

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
        $pageTitle = new Title('Other_File', NS_FILE, 'File');
        RequestContext::getMain()->setTitle($pageTitle);

        $thumb = $this->createStub(ThumbnailImage::class);
        $thumb->method('getFile')->willReturn($file);
        $thumb->method('getWidth')->willReturn(600);
        $thumb->method('getHeight')->willReturn(800);

        $imgAttrs = [];
        $linkAttrs = [
            'class' => 'mw-file-description',
            'href' => '/wiki/File:Df_dk_0007450?page=2',
        ];

        $this->makeHandler()->onThumbnailBeforeProduceHTML($thumb, $imgAttrs, $linkAttrs);

        self::assertArrayNotHasKey('data-iiif-navigate', $imgAttrs);
    }

    // ─── file-link href fix for multi-page ──────────────────

    public function testThumbnailHookFixesFileLinkHrefForPageGreaterThan1(): void
    {
        $file = $this->makeIiifFileMock(
            'manifest-multipage-v2.json',
            isMultipage: true,
            pageCount: 3,
            lastTransformPage: 2,
        );

        $thumb = $this->createStub(ThumbnailImage::class);
        $thumb->method('getFile')->willReturn($file);
        $thumb->method('getWidth')->willReturn(600);
        $thumb->method('getHeight')->willReturn(800);

        $imgAttrs = [];
        // file-link context: no class attribute.
        $linkAttrs = [
            'href' => 'https://iiif.example/page1/full/full/0/default.jpg',
        ];

        $this->makeHandler()->onThumbnailBeforeProduceHTML($thumb, $imgAttrs, $linkAttrs);

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

        $thumb = $this->createStub(ThumbnailImage::class);
        $thumb->method('getFile')->willReturn($file);
        $thumb->method('getWidth')->willReturn(600);
        $thumb->method('getHeight')->willReturn(800);

        $imgAttrs = [];
        $originalHref = 'https://iiif.example/page1/full/full/0/default.jpg';
        $linkAttrs = ['href' => $originalHref];

        $this->makeHandler()->onThumbnailBeforeProduceHTML($thumb, $imgAttrs, $linkAttrs);

        // Page 1 → no fix needed.
        self::assertSame($originalHref, $linkAttrs['href']);
    }

    // ─── onImagePageFileHistoryLine — hide history ─────────

    public function testFileHistoryLineHidesForIiifFile(): void
    {
        $file = $this->createStub(IIIFFile::class);
        $out = new OutputPage();
        $historyList = new \MediaWiki\Page\ImageHistoryList($out);

        $line = '<tr>some content</tr>';
        $css = null;

        $result = $this->makeHandler()
            ->onImagePageFileHistoryLine($historyList, $file, $line, $css);

        // The row is cleared and suppressed; the surrounding heading /
        // file-size info is hidden by the style module loaded in
        // onBeforePageDisplay (see testOnBeforePageDisplayPassesProviderUrlAndStylesOnFilePage).
        self::assertFalse($result);
        self::assertSame('', $line);
    }

    public function testFileHistoryLinePassesThroughForRegularFile(): void
    {
        $file = $this->makeRegularFileMock();
        $out = new OutputPage();
        $historyList = new \MediaWiki\Page\ImageHistoryList($out);

        $line = '<tr>some content</tr>';
        $css = null;

        $result = $this->makeHandler()
            ->onImagePageFileHistoryLine($historyList, $file, $line, $css);

        self::assertTrue($result);
        self::assertSame('<tr>some content</tr>', $line);
    }

    // ─── onImagePageShowTOC — remove filehistory entry ─────

    public function testShowTOCRemovesFileHistoryForIiif(): void
    {
        $file = $this->createStub(IIIFFile::class);
        $page = new \MediaWiki\Page\ImagePage($file);

        $toc = [
            '<a href="#filelinks">File links</a>',
            '<a href="#filehistory">File history</a>',
            '<a href="#metadata">Metadata</a>',
        ];

        $this->makeHandler()->onImagePageShowTOC($page, $toc);

        self::assertCount(2, $toc);
        foreach ($toc as $entry) {
            self::assertStringNotContainsString('#filehistory', $entry);
        }
    }

    public function testShowTOCPreservesTocForRegularFile(): void
    {
        $file = $this->makeRegularFileMock();
        $page = new \MediaWiki\Page\ImagePage($file);

        $toc = [
            '<a href="#filelinks">File links</a>',
            '<a href="#filehistory">File history</a>',
        ];
        $original = $toc;

        $this->makeHandler()->onImagePageShowTOC($page, $toc);

        self::assertSame($original, $toc);
    }

    // ─── onGetExtendedMetadata — delegates to MetadataExtractor ────

    public function testGetExtendedMetadataDelegatesForIiifFile(): void
    {
        $manifest = $this->loadFixture('manifest-fotothek-v2.json');
        $file = $this->createStub(IIIFFile::class);
        $file->method('getResolvedManifest')->willReturn([
            'provider' => 'deutsche-fotothek',
            'objectId' => 'df_dk_0007450',
            'manifestUrl' => 'https://example.org/manifest.json',
            'manifestRaw' => $manifest,
        ]);
        $file->method('getProviderUrl')->willReturn('http://www.deutschefotothek.de/documents/obj/90062808');

        $meta = [];
        $maxCacheTime = null;

        $this->makeHandler()
            ->onGetExtendedMetadata($meta, $file, $this->makeContext(), false, $maxCacheTime);

        // DateTime sentinel + a mapped field prove the extractor ran.
        self::assertSame(
            \MediaWiki\Extension\InstantIIIF\IIIFFile::NO_TIMESTAMP_SENTINEL,
            $meta['DateTime']['value']
        );
        self::assertStringContainsString('Meißen-Triebischtal', $meta['ObjectName']['value']);
    }

    public function testGetExtendedMetadataSkipsNonIiifFile(): void
    {
        $file = $this->makeRegularFileMock();

        $meta = [];
        $maxCacheTime = null;

        $this->makeHandler()
            ->onGetExtendedMetadata($meta, $file, $this->makeContext(), false, $maxCacheTime);

        self::assertEmpty($meta);
    }
}
