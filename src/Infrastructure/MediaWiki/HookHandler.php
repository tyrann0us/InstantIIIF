<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF\Infrastructure\MediaWiki;

use MediaWiki\Context\RequestContext;
use MediaWiki\Hook\GetExtendedMetadataHook;
use MediaWiki\Hook\ThumbnailBeforeProduceHTMLHook;
use MediaWiki\Output\Hook\BeforePageDisplayHook;
use MediaWiki\Page\Hook\ImagePageFileHistoryLineHook;
use MediaWiki\Page\Hook\ImagePageShowTOCHook;

class HookHandler implements
    BeforePageDisplayHook,
    ThumbnailBeforeProduceHTMLHook,
    ImagePageFileHistoryLineHook,
    ImagePageShowTOCHook,
    GetExtendedMetadataHook
{
    public function __construct(
        private \RepoGroup $repoGroup,
        private \NamespaceInfo $namespaceInfo,
        private MetadataExtractor $metadataExtractor
    ) {
    }

    /**
     * Load the RL module on every page.
     *
     * On File: pages with an IIIF file, also pass the provider URL as a
     * JS config variable so the client-side code can fix the shared-upload
     * description link (which would otherwise point to the local URL
     * because getDescriptionUrl() now returns the wiki page URL) and load
     * the style module that hides the meaningless file-history section.
     *
     * @param \OutputPage $out
     * @param \Skin $skin
     */
    // phpcs:ignore Syde.Functions.ArgumentTypeDeclaration.NoArgumentType -- MediaWiki hook interface signature
    public function onBeforePageDisplay($out, $skin): void
    {
        $out->addModules(['ext.instantIIIF.mmvPatch']);

        $iiifRepos = $this->collectIIIFRepoDescriptors();
        if ($iiifRepos !== []) {
            $out->addJsConfigVars('wgInstantIIIFRepos', $iiifRepos);
            $out->addModules(['ext.instantIIIF.mediaSearch', 'ext.instantIIIF.veMediaInsert']);
        }

        $title = $out->getTitle();
        if ($title === null || $title->getNamespace() !== NS_FILE) {
            return;
        }

        $file = $this->repoGroup->findFile($title);
        if (!$file instanceof IIIFFile) {
            return;
        }

        // Hide the meaningless file-history section / file-size info for
        // hotlinked IIIF files (see onImagePageFileHistoryLine).
        $out->addModuleStyles(['ext.instantIIIF.filePage']);

        $providerUrl = $file->getProviderUrl();
        if ($providerUrl !== '') {
            $out->addJsConfigVars('wgIIIFProviderUrl', $providerUrl);
        }
    }

    /**
     * Collect descriptors for every configured InstantIIIF repo so the
     * client-side media-search patch can recognise them and route their
     * search requests through a direct title lookup.
     *
     * @return list<array{apiurl: string, idPatterns: list<string>}>
     */
    private function collectIIIFRepoDescriptors(): array
    {
        $descriptors = [];
        $this->repoGroup
            // phpcs:ignore Syde.Functions.ArgumentTypeDeclaration.NoArgumentType -- forEachForeignRepo callback receives FileRepo
            ->forEachForeignRepo(static function ($repo) use (&$descriptors): bool {
                if ($repo instanceof Repo) {
                    $descriptors[] = [
                        'apiurl' => Repo::resolveLocalApiUrl(),
                        'idPatterns' => $repo->idPatterns(),
                    ];
                }
                return false;
            });
        return $descriptors;
    }

    /**
     * Adds data-iiif-title spoofed ending to <img>.
     * Example: "File:df_dk_0007450.jpg"
     *
     * Also fixes the link target for IIIF thumbnails:
     * - file-link context (main image on file detail page): point to the
     *   correct page's full-resolution IIIF URL instead of always page 1.
     * - desc-link context (article thumbnails, prev/next on file pages):
     *   already handled by passing `page` to ThumbnailImage, which makes
     *   getDescLinkAttribs() add `?page=N` automatically.
     *
     * @param \ThumbnailImage $thumbnail
     * @param array<string, mixed> $attribs
     * @param array<string, mixed>|bool $linkAttribs
     */
    // phpcs:ignore Syde.Functions.FunctionLength.TooLong, SlevomatCodingStandard.Complexity.Cognitive.ComplexityTooHigh, Syde.Functions.ArgumentTypeDeclaration.NoArgumentType -- MediaWiki hook interface signature; pending full refactor
    public function onThumbnailBeforeProduceHTML($thumbnail, &$attribs, &$linkAttribs): bool
    {
        $file = $thumbnail->getFile();
        if (!$file instanceof IIIFFile) {
            return true;
        }

        $title = $file->getTitle();
        if ($title === null) {
            return true;
        }

        // Localized namespace text for NS_FILE (e.g., "File").
        $nsText = $title->getNsText();
        if ($nsText === '') {
            // Fallback, but should not happen here
            $nsText = $this->namespaceInfo->getCanonicalName(NS_FILE) ?: 'File';
        }

        // DB-Key = Title without a namespace, with underscores (no spaces).
        $dbKey = $title->getDBkey();

        // MultimediaViewer requires the file to have a valid image file
        // extension, so we spoof one via IIIFTitle::spoof (idempotent —
        // titles that already end in an image extension are not doubled).
        $attribs['data-iiif-title'] = sprintf('%s:%s', $nsText, IIIFTitle::spoof($dbKey));

        $page = $file->lastTransformPage();

        // Report the file's actual dimensions, not the rendered thumbnail's.
        // MMV reads data-file-width as originalWidth in mmv.lightboximage.js
        // and caps the requested lightbox thumb at that value — using the
        // thumb's clamped width here would cap the lightbox at 300/600 px.
        $attribs['data-file-width'] = $file->getWidth($page);
        $attribs['data-file-height'] = $file->getHeight($page);

        // For multi-page documents, include the page number and the
        // full-resolution URL for that page. The JS patch uses the page
        // number for the ThumbnailInfo API call and the full URL to fix
        // the image link in the MMV overlay (which otherwise always
        // points to page 1 because getUrl() is not page-aware).
        if ($file->isMultipage()) {
            $attribs['data-iiif-page'] = $page;
            if ($page > 1) {
                $fullUrl = $file->getUrlForPage($page);
                if ($fullUrl !== '') {
                    $attribs['data-iiif-full-url'] = $fullUrl;
                }
            }
        }

        // Determine the link context once based on the *incoming* attrs.
        // - "file-link": main image on file detail page. MediaWiki core
        //   passes no class here and an href pointing at the raw IIIF URL.
        // - "desc-link": prev/next thumbnails on file detail pages and
        //   article thumbnails. MediaWiki core sets
        //   class="mw-file-description" and an href pointing at the local
        //   file page (with `?page=N` appended for multi-page contexts).
        $isFileLink = is_array($linkAttribs)
            && isset($linkAttribs['href'])
            && !isset($linkAttribs['class']);
        $isDescLink = is_array($linkAttribs)
            && isset($linkAttribs['class'])
            && str_contains((string) $linkAttribs['class'], 'mw-file-description');

        // For multi-page documents, the file-link href returned by core
        // points at canvas 1 (`File::getUrl()` legacy behaviour); rewrite
        // it to the canvas the user is actually looking at.
        if ($isFileLink && $page > 1 && $file->isMultipage()) {
            $pageUrl = $file->getUrlForPage($page);
            if ($pageUrl !== '') {
                $linkAttribs['href'] = $pageUrl;
            }
        }

        // Tag prev/next-on-same-file navigation thumbnails BEFORE we
        // promote the file-link context with `mw-file-description`,
        // otherwise that promotion would also pick up here and the JS
        // module would strip the class off the main image.
        if ($isDescLink && $file->isMultipage()) {
            $pageTitle = RequestContext::getMain()->getTitle();
            if (
                $pageTitle !== null
                && $pageTitle->getNamespace() === NS_FILE
                && $pageTitle->getDBkey() === $title->getDBkey()
            ) {
                $attribs['data-iiif-navigate'] = '1';
            }
        }

        // Add `mw-file-description` to the main image link so MMV
        // recognises it (its bootstrap collects via the same selector),
        // which both intercepts the click into the overlay and surfaces
        // the "Open in Media Viewer" stripe button. Prev/next
        // thumbs are tagged with data-iiif-navigate above and stripped
        // back by mmv-patch.js, so navigation behaviour does not
        // regress.
        if ($isFileLink && is_array($linkAttribs)) {
            $existing = $linkAttribs['class'] ?? '';
            $linkAttribs['class'] = trim($existing . ' mw-file-description');
        }

        return true;
    }

    /**
     * Hide the file history section for IIIF files — version history is meaningless
     * for hotlinked remote resources, and the single auto-generated row shows
     * misleading data (no user, today's date).
     *
     * Suppressing the row alone still leaves the section heading rendered by
     * ImageHistoryPseudoPager::getBody(), so the file-page style module
     * (loaded in onBeforePageDisplay) hides it; this hook clears the row.
     *
     * @param \MediaWiki\Page\ImageHistoryList $imageHistoryList
     * @param \File $file
     * @param string $line
     * @param string|null $css
     */
    // phpcs:ignore Syde.Functions.ArgumentTypeDeclaration.NoArgumentType -- MediaWiki hook interface signature
    public function onImagePageFileHistoryLine($imageHistoryList, $file, &$line, &$css): bool
    {
        if (!$file instanceof IIIFFile) {
            return true;
        }

        $line = '';
        return false;
    }

    /**
     * Remove the "File history" entry from the file page TOC for IIIF files.
     *
     * @param \MediaWiki\Page\ImagePage $page
     * @param string[] $toc
     */
    // phpcs:ignore Syde.Functions.ArgumentTypeDeclaration.NoArgumentType -- MediaWiki hook interface signature
    public function onImagePageShowTOC($page, &$toc): void
    {
        if (!$page->getDisplayedFile() instanceof IIIFFile) {
            return;
        }

        $toc = array_values(array_filter(
            $toc,
            static fn (string $item) => !str_contains($item, '#filehistory')
        ));
    }

    /**
     * Populate extmetadata for IIIF files from their IIIF manifest.
     *
     * Delegates to MetadataExtractor so the inspector Special page sees
     * the exact same field map.
     *
     * @param array<string, mixed> $combinedMeta
     * @param \File $file
     * @param \MediaWiki\Context\IContextSource $context
     * @param bool $single
     * @param int|null $maxCacheTime
     */
    // phpcs:ignore Syde.Functions.ArgumentTypeDeclaration.NoArgumentType -- MediaWiki hook interface signature
    public function onGetExtendedMetadata(
        &$combinedMeta,
        $file,
        $context,
        $single,
        &$maxCacheTime
    ): void {

        if (!$file instanceof IIIFFile) {
            return;
        }

        foreach ($this->metadataExtractor->extract($file, $context) as $key => $value) {
            $combinedMeta[$key] = $value;
        }
    }
}
