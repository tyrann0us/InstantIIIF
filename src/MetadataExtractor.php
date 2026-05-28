<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF;

use MediaWiki\Context\IContextSource;

/**
 * Builds the extmetadata field map MMV (and the inspector Special page)
 * consume from a resolved IIIF manifest.
 *
 * Extracted from the old static Hooks class so the GetExtendedMetadata
 * hook handler and SpecialInstantIIIFInspect share one implementation.
 */
class MetadataExtractor
{
    public function __construct(private \Language $contentLanguage)
    {
    }

    /**
     * Produce the extmetadata additions for an IIIF file.
     *
     * Always sets the DateTime sentinel to suppress MMV's spurious
     * "Uploaded" line (FormatMetadata otherwise falls back to
     * wfTimestamp("now") because IIIFFile has no local storage). The
     * sentinel survives CommonsMetadata's normalizeMetadataTimestamps
     * (wfTimestamp returns false for non-date strings) and is stripped to
     * '' by MMV's parseExtmeta, which then skips the label for falsy
     * values. The same sentinel is returned by IIIFFile::getTimestamp()
     * for the ApiQueryImageInfo `timestamp` field (consumed by
     * VisualEditor's media dialog) — see IIIFFile::NO_TIMESTAMP_SENTINEL.
     *
     * @return array<string, array{value: string, source: string}>
     */
    public function extract(IIIFFile $file, IContextSource $context): array
    {
        $meta = [
            'DateTime' => [
                'value' => IIIFFile::NO_TIMESTAMP_SENTINEL,
                'source' => 'extension',
            ],
        ];

        $resolved = $file->getResolvedManifest();
        if ($resolved === null) {
            return $meta;
        }

        $manifest = $resolved['manifestRaw'];

        $label = $this->extractLocalizedString($manifest['label'] ?? '', $context);
        if ($label !== '') {
            $meta['ObjectName'] = ['value' => $label, 'source' => 'extension'];
        }

        $attribution = $this->extractAttribution($manifest, $context);
        if ($attribution !== '') {
            // Trim trailing colons/commas/semicolons. MMV concatenates
            // attribution with the license name and URL using a comma
            // separator ("{credit}, {license}, {url}") — a manifest whose
            // attribution naturally ends with ":" then renders as
            // "Partner-Institution:, …" which reads as a stray comma.
            $meta['Credit'] = [
                'value' => $this->trimTrailingPunctuationHtml($attribution),
                'source' => 'extension',
            ];
            $plain = str_replace(['<br>', '<br/>', '<br />'], ' ', $attribution);
            $plain = trim(
                // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags, WordPressVIPMinimum.Functions.StripTags.StripTagsOneParameter -- not a WP project
                (string) preg_replace('/\s+/', ' ', strip_tags($plain))
            );
            $meta['Attribution'] = [
                'value' => $this->trimTrailingPunctuationPlain($plain),
                'source' => 'extension',
            ];
            $meta['AttributionRequired'] = ['value' => 'true', 'source' => 'extension'];
        }

        // v2: license, v3: rights — usually a URL or list of URLs.
        // Providers vary: BSB returns `["https://…"]`, SLUB returns
        // nothing (link is in metadata under "Rechteinformationen"),
        // Fotothek returns nothing either. Fall back through metadata,
        // then the provider landing page.
        $licenseUrl = $this->extractLicenseUrl($manifest['rights'] ?? $manifest['license'] ?? '');
        if ($licenseUrl === '') {
            $licenseUrl = $file->getLicenseUrlFromMetadata($resolved);
        }
        if ($licenseUrl === '') {
            $licenseUrl = $file->getProviderUrl();
        }
        if ($licenseUrl !== '') {
            $meta['LicenseUrl'] = ['value' => $licenseUrl, 'source' => 'extension'];
            $shortName = $this->licenseShortName($licenseUrl);
            // MMV only creates a License object when LicenseShortName is set.
            // Without it, the license link falls back to filePageUrl with a
            // meaningless ?uselang=…#… fragment appended by MMV.
            if ($shortName === '') {
                $shortName = $context->msg('instantiiif-license-shortname')
                    ->inContentLanguage()->text();
            }
            $meta['LicenseShortName'] = ['value' => $shortName, 'source' => 'extension'];
        }

        return $meta;
    }

    /**
     * Extract a license URL from the manifest's `rights`/`license` field,
     * accepting plain strings and IIIF v2 lists of strings.
     */
    private function extractLicenseUrl(mixed $value): string
    {
        if (is_string($value)) {
            return preg_match('~^https?://~', $value) ? $value : '';
        }
        if (is_array($value)) {
            foreach ($value as $entry) {
                if (is_string($entry) && preg_match('~^https?://~', $entry)) {
                    return $entry;
                }
            }
        }
        return '';
    }

    /**
     * Build the preferred-language priority list.
     *
     * Picks (in order): the user's interface language when available
     * (extmetadata is rendered for that user in MMV), the wiki's content
     * language, then English as a universal IIIF fallback.
     *
     * @return list<string>
     */
    private function preferredLanguages(IContextSource $context): array
    {
        $langs = [];
        try {
            $userLang = $context->getLanguage()->getCode();
            if ($userLang !== '') {
                $langs[] = $userLang;
            }
        } catch (\Throwable $unused) {
            // No language on this context — fall through.
            unset($unused);
        }
        $contentLang = $this->contentLanguage->getCode();
        if ($contentLang !== '' && !in_array($contentLang, $langs, true)) {
            $langs[] = $contentLang;
        }
        if (!in_array('en', $langs, true)) {
            $langs[] = 'en';
        }
        return $langs;
    }

    /**
     * Resolve a IIIF value to a single string. Accepts:
     *  - plain string
     *  - v2 single language object: `{"@value": "...", "@language": "..."}`
     *  - v2 array of language objects: `[{"@language":"de","@value":"…"}, …]`
     *  - v3 language map: `{"de": ["…"], "en": ["…"]}`
     *
     * Picks the closest available translation to the user's interface
     * language, falling back through the wiki's content language to
     * English, then to the first translation present.
     */
    private function extractLocalizedString(mixed $value, IContextSource $context): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (!is_array($value)) {
            return '';
        }
        if (isset($value['@value']) && is_string($value['@value'])) {
            return $value['@value'];
        }
        $preferred = $this->preferredLanguages($context);
        if (isset($value[0]) && is_array($value[0]) && isset($value[0]['@value'])) {
            return $this->pickLocalizedFromList($value, $preferred);
        }
        return $this->pickLocalizedFromMap($value, $preferred);
    }

    /**
     * @param array<int, mixed> $entries
     * @param list<string> $preferred
     */
    private function pickLocalizedFromList(array $entries, array $preferred): string
    {
        foreach ($preferred as $lang) {
            foreach ($entries as $entry) {
                if (
                    is_array($entry)
                    && ($entry['@language'] ?? null) === $lang
                    && is_string($entry['@value'] ?? null)
                ) {
                    return $entry['@value'];
                }
            }
        }
        foreach ($entries as $entry) {
            if (is_array($entry) && is_string($entry['@value'] ?? null)) {
                return $entry['@value'];
            }
        }
        return '';
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string> $preferred
     */
    private function pickLocalizedFromMap(array $value, array $preferred): string
    {
        foreach ($preferred as $lang) {
            $candidate = $value[$lang] ?? null;
            if (is_array($candidate) && isset($candidate[0]) && is_string($candidate[0])) {
                return $candidate[0];
            }
        }
        foreach ($value as $langValues) {
            if (is_array($langValues) && isset($langValues[0]) && is_string($langValues[0])) {
                return $langValues[0];
            }
        }
        return '';
    }

    /**
     * Extract attribution text from a IIIF manifest.
     * v2 uses the `attribution` field, v3 uses `requiredStatement.value`.
     * The `attribution` field may be:
     *  - a plain string ("…HTML…")
     *  - a single language object `{"@language":"de","@value":"…"}`
     *  - an array of language objects (BSB)
     *  - a v3 language map `{"de":["…"]}`
     *
     * @param array<string, mixed> $manifest
     */
    private function extractAttribution(array $manifest, IContextSource $context): string
    {
        $attribution = $manifest['attribution'] ?? '';
        if (is_string($attribution) && $attribution !== '') {
            return $attribution;
        }
        if (is_array($attribution)) {
            $resolved = $this->extractLocalizedString($attribution, $context);
            if ($resolved !== '') {
                return $resolved;
            }
        }

        $stmt = $manifest['requiredStatement'] ?? null;
        if (is_array($stmt) && isset($stmt['value'])) {
            return $this->extractLocalizedString($stmt['value'], $context);
        }

        return '';
    }

    /**
     * Strip trailing colons / commas / semicolons from a plain-text string.
     * Whitespace and any of `:`, `;`, `,` are removed from the very end —
     * other punctuation (e.g. trailing `.`) is preserved.
     */
    private function trimTrailingPunctuationPlain(string $text): string
    {
        return rtrim($text, " \t\n\r\0\x0B:;,");
    }

    /**
     * Like trimTrailingPunctuationPlain, but for HTML fragments — the
     * punctuation may sit just before trailing closing tags (e.g.
     * `<p>Foo:</p>`). Strips it while preserving the closing tags.
     */
    private function trimTrailingPunctuationHtml(string $html): string
    {
        // \s*[:;,]+\s* eats the punctuation plus any whitespace around it;
        // the capture preserves trailing closing tags (e.g. "<p>Foo:</p>"
        // → "<p>Foo</p>") so the HTML structure stays valid.
        return (string) preg_replace(
            '~\s*[:;,]+\s*((?:</[^>]+>\s*)*)$~',
            '$1',
            $html
        );
    }

    /**
     * Derive a human-readable short name from well-known license URLs
     * (Creative Commons, RightsStatements.org).
     */
    private function licenseShortName(string $url): string
    {
        if (preg_match('#creativecommons\.org/licenses/([a-z-]+)/(\d+\.\d+)#i', $url, $match)) {
            return 'CC ' . strtoupper($match[1]) . ' ' . $match[2];
        }
        if (str_contains($url, 'creativecommons.org/publicdomain/zero')) {
            return 'CC0';
        }
        if (str_contains($url, 'creativecommons.org/publicdomain/mark')) {
            return 'Public Domain';
        }
        if (preg_match('#rightsstatements\.org/vocab/([^/]+)/#i', $url, $match)) {
            return str_replace('-', ' ', $match[1]);
        }
        return '';
    }
}
