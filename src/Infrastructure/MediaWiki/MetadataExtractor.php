<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF\Infrastructure\MediaWiki;

use MediaWiki\Context\IContextSource;
use MediaWiki\Extension\InstantIIIF\Domain\LicenseClassifier;
use MediaWiki\Extension\InstantIIIF\Domain\LocalizedText;
use MediaWiki\Extension\InstantIIIF\Domain\Manifest;

/**
 * Builds the extmetadata field map MMV (and the inspector Special page)
 * consume from a resolved IIIF manifest.
 *
 * Extracted from the old static Hooks class so the GetExtendedMetadata
 * hook handler and SpecialInstantIIIFInspect share one implementation.
 * Delegates manifest parsing to Domain\Manifest, locale resolution to
 * Domain\LocalizedText, and license short-name derivation to
 * Domain\LicenseClassifier.
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

        $manifest = Manifest::from($resolved['manifestRaw']);
        $preferred = $this->preferredLanguages($context);

        $label = LocalizedText::resolve($manifest->rawLabel(), $preferred);
        if ($label !== '') {
            $meta['ObjectName'] = ['value' => $label, 'source' => 'extension'];
        }

        $attribution = LocalizedText::resolve($manifest->rawAttribution(), $preferred);
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

        $licenseUrl = $this->resolveLicenseUrl($file, $manifest, $resolved);
        if ($licenseUrl !== '') {
            $meta['LicenseUrl'] = ['value' => $licenseUrl, 'source' => 'extension'];
            $shortName = LicenseClassifier::shortNameFor($licenseUrl);
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
     * v2 `license`, v3 `rights` — usually a URL or list of URLs. Providers
     * vary: BSB returns `["https://…"]`, SLUB returns nothing (link is
     * in metadata under "Rechteinformationen"), Fotothek returns nothing
     * either. Fall back through metadata, then the provider landing page.
     *
     * @param array<string, mixed> $resolved
     */
    private function resolveLicenseUrl(
        IIIFFile $file,
        Manifest $manifest,
        array $resolved
    ): string {

        $licenseUrl = $this->urlFromLicenseField($manifest->rawLicense());
        if ($licenseUrl !== '') {
            return $licenseUrl;
        }
        $licenseUrl = $file->getLicenseUrlFromMetadata($resolved);
        if ($licenseUrl !== '') {
            return $licenseUrl;
        }
        return $file->getProviderUrl();
    }

    /**
     * Pull an HTTP URL out of the manifest's `rights`/`license` field,
     * accepting plain strings and IIIF v2 lists of strings.
     */
    private function urlFromLicenseField(mixed $value): string
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
}
