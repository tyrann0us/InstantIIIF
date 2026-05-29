<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF\Infrastructure\MediaWiki;

/**
 * Helpers for the spoofed image extension that lets MediaWiki / MMV treat
 * extension-less IIIF object IDs (e.g. "df_dk_0007450", "bsb11610364") as
 * image files.
 *
 * MultimediaViewer's isValidExtension() rejects titles without a recognised
 * image extension, so onThumbnailBeforeProduceHTML appends ".jpg" to the
 * data-iiif-title. Every consumer that round-trips the title back to a
 * canonical wiki page (file-page lookup, MMV share / embed / wikitext URL
 * rewriting, media-search query) has to strip it again.
 *
 * Only ".jpg" is ever appended, so only ".jpg" needs to be recognised on
 * the way back.
 *
 * Mirrored on the client by resources/iiif-title.js.
 */
final class IIIFTitle
{
    /** Single source of truth: the only extension we ever spoof. */
    public const SPOOF_EXTENSION = 'jpg';

    /**
     * Append the spoofed extension when the dbkey doesn't already end with
     * it. Idempotent — spoof("Foo.jpg") returns "Foo.jpg" unchanged so the
     * imageinfo API call from MMV doesn't see a doubled "Foo.jpg.jpg" that
     * unspoof() can't undo.
     */
    public static function spoof(string $dbKey): string
    {
        return self::isSpoofed($dbKey)
            ? $dbKey
            : $dbKey . '.' . self::SPOOF_EXTENSION;
    }

    /**
     * Strip a trailing ".jpg" (case-insensitive). Idempotent — unspoof("Foo")
     * returns "Foo".
     */
    public static function unspoof(string $dbKey): string
    {
        return preg_replace(self::pattern(), '', $dbKey) ?? $dbKey;
    }

    /** Does the dbkey end with our spoofed extension? */
    public static function isSpoofed(string $dbKey): bool
    {
        return (bool) preg_match(self::pattern(), $dbKey);
    }

    /** End-anchored, case-insensitive pattern for the spoofed extension. */
    private static function pattern(): string
    {
        return '/\.' . self::SPOOF_EXTENSION . '$/i';
    }
}
