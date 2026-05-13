<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF;

use ImageHandler;
use MediaTransformOutput;
use ThumbnailImage;

/**
 * MediaHandler for IIIF files.
 *
 * Extends ImageHandler to add `page` parameter support so that
 * wikitext like `[[File:Foo.jpg|page=3|mini]]` works for multi-page
 * IIIF manifests (scanned books, manuscripts, etc.).
 *
 * Without this handler, MediaWiki would use JpegHandler (because
 * IIIFFile reports image/jpeg), which does not accept `page`.
 */
class IIIFHandler extends ImageHandler
{
    /**
     * Map wikitext magic words to internal parameter names.
     * Adding `img_page` lets the parser recognise `page=N`.
     *
     * @return array<string, string>
     */
    // phpcs:ignore Syde.Classes.DisallowGetterSetter.GetterFound -- MediaWiki MediaHandler override
    public function getParamMap(): array
    {
        return [
            'img_width' => 'width',
            'img_page' => 'page',
        ];
    }

    /**
     * @param string $name
     * @param mixed $value
     */
    // phpcs:ignore Syde.Functions.ArgumentTypeDeclaration.NoArgumentType -- MediaWiki MediaHandler override
    public function validateParam($name, $value): bool
    {
        if ($name === 'page' && trim((string) $value) !== (string) intval($value)) {
            // Reject non-integer page values (likely a caption).
            return false;
        }

        return in_array($name, ['width', 'height', 'page'], true) && $value > 0;
    }

    /**
     * @param array<string, mixed> $params
     * @return string|false
     */
    // phpcs:ignore Syde.Functions.ArgumentTypeDeclaration.NoArgumentType -- MediaWiki MediaHandler override
    public function makeParamString($params): string|false
    {
        $page = $params['page'] ?? 1;
        if (!isset($params['width'])) {
            return false;
        }

        return "page{$page}-{$params['width']}px";
    }

    /**
     * @param string $str
     * @return array<string, mixed>|false
     */
    // phpcs:ignore Syde.Functions.ArgumentTypeDeclaration.NoArgumentType -- MediaWiki MediaHandler override
    public function parseParamString($str): array|false
    {
        if (preg_match('/^page(\d+)-(\d+)px$/', $str, $matches)) {
            return ['width' => (int) $matches[2], 'page' => (int) $matches[1]];
        }

        return false;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    // phpcs:ignore Syde.Classes.DisallowGetterSetter.GetterFound, Syde.Functions.ArgumentTypeDeclaration.NoArgumentType -- MediaWiki MediaHandler override
    protected function getScriptParams($params): array
    {
        return [
            'width' => $params['width'],
            'page' => $params['page'] ?? 1,
        ];
    }

    /**
     * Produce a thumbnail for the given IIIF file.
     *
     * IIIFFile overrides File::transform() directly and builds the IIIF
     * Image API URL there, so this method is normally not reached.
     * It exists only to satisfy the abstract contract of MediaHandler.
     *
     * @param \File $image
     * @param string $dstPath
     * @param string $dstUrl
     * @param array<string, mixed> $params
     * @param int $flags
     */
    // phpcs:ignore Syde.Functions.ArgumentTypeDeclaration.NoArgumentType, Syde.Functions.ReturnTypeDeclaration.NoReturnType -- MediaWiki MediaHandler override
    public function doTransform($image, $dstPath, $dstUrl, $params, $flags = 0): MediaTransformOutput
    {
        $width = (int) ($params['width'] ?? 0);
        $height = (int) ($params['height'] ?? 0);

        return new ThumbnailImage($image, $dstUrl, false, [
            'width' => $width,
            'height' => $height,
        ]);
    }

    /**
     * IIIF images are always remote — there is no local file to read
     * dimensions from.  Return an empty array so that MediaWiki falls
     * back to File::getWidth() / File::getHeight(), which IIIFFile
     * overrides to query the manifest / info.json.
     *
     * @param mixed $state
     * @param string $path
     * @return array<string, mixed>
     */
    // phpcs:ignore Syde.Classes.DisallowGetterSetter.GetterFound, Syde.Functions.ArgumentTypeDeclaration.NoArgumentType -- MediaWiki MediaHandler override
    public function getSizeAndMetadata($state, $path): array
    {
        return [];
    }

    /**
     * IIIFFile always needs rendering (thumbnail URL construction).
     *
     * @param \File $file
     * @return bool
     */
    // phpcs:ignore Syde.Functions.ArgumentTypeDeclaration.NoArgumentType -- MediaWiki MediaHandler override
    public function mustRender($file): bool
    {
        return true;
    }

    /**
     * IIIF thumbnails are free — the remote service creates them.
     *
     * @param \File $file
     * @return bool
     */
    // phpcs:ignore Syde.Functions.ArgumentTypeDeclaration.NoArgumentType -- MediaWiki MediaHandler override
    public function isExpensiveToThumbnail($file): bool
    {
        return false;
    }
}
