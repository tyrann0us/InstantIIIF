<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF\Domain;

/**
 * Provider-specific quirks for IIIF manifests in the wild.
 *
 * Most IIIF providers expose the canonical landing URL via v2 `related`
 * or v3 `homepage`, and the license URL via v2 `license` / v3 `rights`.
 * A few (Deutsche Fotothek, SLUB Dresden) put them inside the
 * `metadata` array under provider-specific labels instead. This class
 * holds the mapping from those labels to what they mean.
 *
 * Pure data and lookup, no side effects.
 */
final class ProviderQuirks
{
    /**
     * Metadata labels that carry the object's landing / homepage URL
     * for providers without a top-level `related` / `homepage` field.
     *
     * @var array<string, list<string>>
     */
    private const LANDING_META_KEYS = [
        'deutsche-fotothek' => ['Link zum Werk'],
        'slub-dresden' => ['PURL', 'Persistent URL'],
    ];

    /**
     * Metadata labels that carry the license URL for providers without
     * a top-level `license` / `rights` field (e.g. SLUB embeds the
     * license as an HTML link in `Rechteinformationen`).
     *
     * @var array<string, list<string>>
     */
    private const LICENSE_META_KEYS = [
        'slub-dresden' => ['Rechteinformationen', 'Rights'],
    ];

    /**
     * Provider IDs that have at least one metadata fallback.
     *
     * @return list<string>
     */
    public static function providerIds(): array
    {
        return array_keys(self::LANDING_META_KEYS + self::LICENSE_META_KEYS);
    }

    /**
     * Metadata-label needles for finding a landing URL inside the
     * manifest's `metadata` array. An empty list means the provider has no
     * such fallback, so the caller can skip searching metadata.
     *
     * @return list<string>
     */
    public static function landingLabelsFor(string $providerId): array
    {
        return self::LANDING_META_KEYS[$providerId] ?? [];
    }

    /**
     * Metadata-label needles for finding a license URL inside the
     * manifest's `metadata` array.
     *
     * @return list<string>
     */
    public static function licenseLabelsFor(string $providerId): array
    {
        return self::LICENSE_META_KEYS[$providerId] ?? [];
    }
}
