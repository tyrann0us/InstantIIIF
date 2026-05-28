<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF\Tests\Integration;

use MediaWiki\Extension\InstantIIIF\Infrastructure\MediaWiki\HookHandler;
use MediaWiki\Extension\InstantIIIF\Infrastructure\MediaWiki\IIIFFile;
use MediaWiki\Extension\InstantIIIF\Infrastructure\MediaWiki\MetadataExtractor;
use MediaWiki\Output\OutputPage;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use ThumbnailImage;

/**
 * Integration tests for HookHandler, exercising real MW plumbing
 * (OutputPage, RepoGroup, NamespaceInfo, RequestContext) instead of
 * the hand-stubbed versions in the standalone Unit suite.
 *
 * Standalone HookHandlerTest covers the full matrix of hook branches;
 * this suite focuses on the seams where real-MW behaviour matters:
 *  - OutputPage actually receives our module additions.
 *  - ImagePageFileHistoryLine clears the line (mutated-by-reference).
 *  - GetExtendedMetadata sets the DateTime sentinel for IIIFFiles.
 */
#[CoversClass(HookHandler::class)]
class HookHandlerTest extends MediaWikiIntegrationTestCase
{
    private function makeHandler(): HookHandler
    {
        $services = $this->getServiceContainer();
        return new HookHandler(
            $services->getRepoGroup(),
            $services->getNamespaceInfo(),
            new MetadataExtractor($services->getContentLanguage())
        );
    }

    private function makeOutputPage(?Title $title = null): OutputPage
    {
        $context = new \MediaWiki\Context\RequestContext();
        $context->setRequest(new FauxRequest());
        $context->setTitle($title ?? Title::newMainPage());
        $out = new OutputPage($context);
        $out->setTitle($title ?? Title::newMainPage());
        return $out;
    }

    /* -------------------- onBeforePageDisplay -------------------- */

    public function testBeforePageDisplayAddsMmvPatchModule(): void
    {
        $out = $this->makeOutputPage();
        $skin = $out->getSkin();

        $this->makeHandler()->onBeforePageDisplay($out, $skin);

        self::assertContains('ext.instantIIIF.mmvPatch', $out->getModules());
    }

    public function testBeforePageDisplayLeavesNonFileNamespaceAlone(): void
    {
        $out = $this->makeOutputPage(Title::newMainPage());
        $skin = $out->getSkin();

        $this->makeHandler()->onBeforePageDisplay($out, $skin);

        // The file-page style module is only loaded for IIIF file pages.
        self::assertNotContains('ext.instantIIIF.filePage', $out->getModuleStyles());
    }

    /* -------------------- onImagePageFileHistoryLine -------------------- */

    public function testImagePageFileHistoryLineBlanksLineForIiifFiles(): void
    {
        $imageHistoryList = null; // unused by the handler
        $file = $this->createStub(IIIFFile::class);
        $line = '<tr>...history row...</tr>';
        $css = null;

        $result = $this->makeHandler()->onImagePageFileHistoryLine(
            $imageHistoryList,
            $file,
            $line,
            $css
        );

        self::assertSame('', $line);
        self::assertFalse($result, 'Returning false aborts the row render.');
    }

    public function testImagePageFileHistoryLineLeavesNonIiifFilesAlone(): void
    {
        $file = $this->createStub(\File::class);
        $line = '<tr>real file row</tr>';
        $css = null;

        $result = $this->makeHandler()->onImagePageFileHistoryLine(null, $file, $line, $css);

        self::assertSame('<tr>real file row</tr>', $line);
        self::assertTrue($result);
    }

    /* -------------------- onGetExtendedMetadata -------------------- */

    public function testGetExtendedMetadataSetsDateTimeSentinelForIiifFile(): void
    {
        $file = $this->createStub(IIIFFile::class);
        $file->method('getResolvedManifest')->willReturn(null);

        $combined = [];
        $maxCacheTime = 3600;
        $context = \MediaWiki\Context\RequestContext::getMain();

        $this->makeHandler()->onGetExtendedMetadata(
            $combined,
            $file,
            $context,
            true,
            $maxCacheTime
        );

        self::assertArrayHasKey('DateTime', $combined);
        self::assertSame(
            IIIFFile::NO_TIMESTAMP_SENTINEL,
            $combined['DateTime']['value']
        );
    }

    public function testGetExtendedMetadataLeavesNonIiifFilesUntouched(): void
    {
        $file = $this->createStub(\File::class);
        $combined = ['preexisting' => 'value'];
        $maxCacheTime = 3600;
        $context = \MediaWiki\Context\RequestContext::getMain();

        $this->makeHandler()->onGetExtendedMetadata(
            $combined,
            $file,
            $context,
            true,
            $maxCacheTime
        );

        self::assertSame(['preexisting' => 'value'], $combined);
    }

    /* -------------------- onThumbnailBeforeProduceHTML -------------------- */

    /**
     * The hook adds data-iiif-title with a localised "File:" prefix and
     * the spoofed `.jpg` extension MMV expects.
     */
    public function testThumbnailBeforeProduceHtmlAddsDataIiifTitle(): void
    {
        $title = Title::makeTitle(NS_FILE, 'Df_dk_0007450');
        $file = $this->createStub(IIIFFile::class);
        $file->method('getTitle')->willReturn($title);
        $file->method('lastTransformPage')->willReturn(1);
        $file->method('isMultipage')->willReturn(false);
        $file->method('getWidth')->willReturn(1600);
        $file->method('getHeight')->willReturn(1324);

        $thumb = $this->createStub(ThumbnailImage::class);
        $thumb->method('getFile')->willReturn($file);

        $attribs = [];
        $linkAttribs = [];

        $this->makeHandler()->onThumbnailBeforeProduceHTML($thumb, $attribs, $linkAttribs);

        self::assertArrayHasKey('data-iiif-title', $attribs);
        self::assertStringEndsWith('.jpg', $attribs['data-iiif-title']);
        self::assertStringContainsString('Df_dk_0007450', $attribs['data-iiif-title']);

        // Reports the file's full dimensions (not the rendered thumb's).
        self::assertSame(1600, $attribs['data-file-width']);
        self::assertSame(1324, $attribs['data-file-height']);
    }

    public function testThumbnailBeforeProduceHtmlSkipsNonIiifFiles(): void
    {
        $thumb = $this->createStub(ThumbnailImage::class);
        $thumb->method('getFile')->willReturn($this->createStub(\File::class));

        $attribs = [];
        $linkAttribs = [];

        $result = $this->makeHandler()->onThumbnailBeforeProduceHTML(
            $thumb,
            $attribs,
            $linkAttribs
        );

        self::assertTrue($result);
        self::assertSame([], $attribs);
    }
}
