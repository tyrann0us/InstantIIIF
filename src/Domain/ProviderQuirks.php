<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF\Domain;

/**
 * Provider-specific quirks for IIIF manifests in the wild.
 *
 * Most IIIF providers expose the canonical landing URL via v2 `related`
 * or v3 `homepage`, and the license URL via v2 `license` / v3 `rights`.
 * A handful (Deutsche Fotothek, SLUB Dresden) instead bury these inside
 * the `metadata` array keyed by provider-specific labels — this class
 * centralises the label → meaning mapping.
 *
 * Pure data + lookup; no side effects.
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
     * Metadata-label needles for finding a landing URL inside the
     * manifest's `metadata` array. Empty list means: no provider-specific
     * fallback — the caller should not bother searching metadata.
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
