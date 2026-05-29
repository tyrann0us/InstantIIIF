<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF\Tests\Unit;

use MediaWiki\Context\IContextSource;
use MediaWiki\Extension\InstantIIIF\Infrastructure\MediaWiki\IIIFFile;
use MediaWiki\Extension\InstantIIIF\Infrastructure\MediaWiki\MetadataExtractor;
use MediaWiki\MediaWikiServices;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for MetadataExtractor: the manifest → extmetadata mapping that
 * MMV (and SpecialInstantIIIFInspect) consume.
 */
#[CoversClass(MetadataExtractor::class)]
class MetadataExtractorTest extends TestCase
{
    private const FIXTURES_DIR = __DIR__ . '/../Fixtures/';

    protected function setUp(): void
    {
        MediaWikiServices::reset();
    }

    protected function tearDown(): void
    {
        MediaWikiServices::reset();
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

    private function makeExtractor(string $contentLang = 'en'): MetadataExtractor
    {
        return new MetadataExtractor(new \Language($contentLang));
    }

    private function makeContext(?string $userLang = null): IContextSource
    {
        $context = $this->createStub(IContextSource::class);
        if ($userLang !== null) {
            $context->method('getLanguage')->willReturn(new \Language($userLang));
        }
        $context->method('msg')->willReturnCallback(
            static fn (string $key) => new \Message($key)
        );
        return $context;
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function makeFile(
        array $manifest,
        string $provider,
        string $providerUrl,
        string $licenseFromMetadata = ''
    ): IIIFFile {

        $file = $this->createStub(IIIFFile::class);
        $file->method('getResolvedManifest')->willReturn([
            'provider' => $provider,
            'objectId' => 'obj',
            'manifestUrl' => 'https://example.org/manifest.json',
            'manifestRaw' => $manifest,
        ]);
        $file->method('getProviderUrl')->willReturn($providerUrl);
        $file->method('getLicenseUrlFromMetadata')->willReturn($licenseFromMetadata);
        return $file;
    }

    public function testExtractSetsDateTimeSentinelWhenUnresolved(): void
    {
        $file = $this->createStub(IIIFFile::class);
        $file->method('getResolvedManifest')->willReturn(null);
        $file->method('getProviderUrl')->willReturn('');

        $meta = $this->makeExtractor()->extract($file, $this->makeContext());

        // The shared no-timestamp sentinel suppresses the upload date in MMV.
        self::assertArrayHasKey('DateTime', $meta);
        self::assertSame(
            \MediaWiki\Extension\InstantIIIF\Infrastructure\MediaWiki\IIIFFile::NO_TIMESTAMP_SENTINEL,
            $meta['DateTime']['value']
        );
    }

    public function testExtractPopulatesFieldsFromFotothekManifest(): void
    {
        $manifest = $this->loadFixture('manifest-fotothek-v2.json');
        $file = $this->makeFile(
            $manifest,
            'deutsche-fotothek',
            'http://www.deutschefotothek.de/documents/obj/90062808'
        );

        $meta = $this->makeExtractor()->extract($file, $this->makeContext());

        // ObjectName from the Fotothek manifest's label.
        self::assertStringContainsString('Meißen-Triebischtal', $meta['ObjectName']['value']);

        // Credit comes from the HTML `attribution` field; the cleaned-up
        // Attribution strips tags and collapses whitespace.
        self::assertStringContainsString('Deutschen Fotothek', $meta['Attribution']['value']);
        self::assertSame('true', $meta['AttributionRequired']['value']);

        // License: no manifest-level license → falls back to providerUrl.
        self::assertSame(
            'http://www.deutschefotothek.de/documents/obj/90062808',
            $meta['LicenseUrl']['value']
        );

        // LicenseShortName is set (either from URL or message fallback).
        self::assertArrayHasKey('LicenseShortName', $meta);
    }

    public function testExtractPopulatesLicenseFromManifest(): void
    {
        $manifest = $this->loadFixture('manifest-bsb-v2.json');
        $file = $this->makeFile(
            $manifest,
            'digitale-sammlungen',
            'https://mdz-nbn-resolving.de/details:bsb00127289'
        );

        $meta = $this->makeExtractor()->extract($file, $this->makeContext());

        // BSB manifest has `license: ["https://creativecommons.org/.../by-nc-sa/4.0/"]`.
        self::assertSame(
            'https://creativecommons.org/licenses/by-nc-sa/4.0/',
            $meta['LicenseUrl']['value']
        );
        self::assertSame('CC BY-NC-SA 4.0', $meta['LicenseShortName']['value']);

        // Attribution from array-of-language-objects shape.
        self::assertSame('Bayerische Staatsbibliothek', $meta['Attribution']['value']);
    }

    public function testExtractLicenseFromSlubMetadata(): void
    {
        // SLUB manifest has no top-level `license`/`rights`; the
        // license URL lives inside an HTML <a> in metadata under the
        // label "Rechteinformationen".
        $manifest = $this->loadFixture('manifest-slub-v2.json');
        $file = $this->makeFile(
            $manifest,
            'slub-dresden',
            'http://digital.slub-dresden.de/id384671365-19500000',
            'http://creativecommons.org/publicdomain/mark/1.0/'
        );

        $meta = $this->makeExtractor()->extract($file, $this->makeContext());

        self::assertSame(
            'http://creativecommons.org/publicdomain/mark/1.0/',
            $meta['LicenseUrl']['value']
        );
        self::assertSame('Public Domain', $meta['LicenseShortName']['value']);
    }

    /**
     * MMV concatenates "{credit}, {license name}, {url}" with comma
     * separators. A manifest whose attribution naturally ends with `:` —
     * e.g. Fotothek's "© Es gelten die Nutzungsbedingungen … Partner-Institution:"
     * — would render as "Partner-Institution:, Nutzungsbedingungen, …",
     * the stray `:,` sequence the user flagged. Verify trailing
     * `:` / `;` / `,` are stripped from both Credit (HTML) and
     * Attribution (plain), including punctuation sitting inside a
     * trailing closing tag.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function trailingPunctuationProvider(): array
    {
        return [
            'colon at very end' => [
                'Foo Partner-Institution:',
                'Foo Partner-Institution',
                'Foo Partner-Institution',
            ],
            'colon before closing tag' => [
                '<p>Foo Bar:</p>',
                '<p>Foo Bar</p>',
                'Foo Bar',
            ],
            'multiple punctuation chars' => [
                'Foo Bar:;,',
                'Foo Bar',
                'Foo Bar',
            ],
            'trailing whitespace after colon' => [
                'Foo Bar:   ',
                'Foo Bar',
                'Foo Bar',
            ],
            'preserves trailing period' => [
                'Foo Bar.',
                'Foo Bar.',
                'Foo Bar.',
            ],
            'no trailing punctuation' => [
                'Bayerische Staatsbibliothek',
                'Bayerische Staatsbibliothek',
                'Bayerische Staatsbibliothek',
            ],
        ];
    }

    #[DataProvider('trailingPunctuationProvider')]
    public function testTrailingPunctuationIsStrippedFromCreditAndAttribution(
        string $manifestAttribution,
        string $expectedCredit,
        string $expectedAttribution
    ): void {

        $file = $this->makeFile(
            ['attribution' => $manifestAttribution],
            'deutsche-fotothek',
            'http://example.org/landing'
        );

        $meta = $this->makeExtractor()->extract($file, $this->makeContext());

        self::assertSame($expectedCredit, $meta['Credit']['value']);
        self::assertSame($expectedAttribution, $meta['Attribution']['value']);
    }

    public function testExtractV3RequiredStatement(): void
    {
        $manifest = $this->loadFixture('manifest-v3.json');
        $file = $this->makeFile($manifest, 'v3-test', 'https://example.org/object/12345');

        $meta = $this->makeExtractor()->extract($file, $this->makeContext());

        // v3 label is a language map. No user language on the stub
        // context and the injected content language is 'en', so we pick
        // the English translation.
        self::assertSame('Test Manifest v3', $meta['ObjectName']['value']);

        // v3 requiredStatement.value (only present in English in the fixture).
        self::assertSame('Example Institution', $meta['Credit']['value']);

        // v3 rights field
        self::assertSame(
            'https://creativecommons.org/licenses/by-sa/4.0/',
            $meta['LicenseUrl']['value']
        );
        self::assertSame('CC BY-SA 4.0', $meta['LicenseShortName']['value']);
    }

    /**
     * @return array<string, array{string|null, string, string}>
     */
    public static function languagePreferenceProvider(): array
    {
        // [user-language (null = unset), content-language, expected label]
        return [
            'user de wins over content en' => ['de', 'en', 'Test-Manifest v3'],
            'user en wins over content de' => ['en', 'de', 'Test Manifest v3'],
            'no user lang → content de' => [null, 'de', 'Test-Manifest v3'],
            'no user lang → content en' => [null, 'en', 'Test Manifest v3'],
            // Locale absent from the manifest: should fall back to the
            // first translation present (en in the v3 fixture).
            'user fr / content fr (neither present)' => ['fr', 'fr', 'Test Manifest v3'],
        ];
    }

    /**
     * The language priority must come from the wiki / user (not a
     * hard-coded list), so the same extension running on a French
     * wiki picks French where available and falls back to English
     * otherwise.
     */
    #[DataProvider('languagePreferenceProvider')]
    public function testLocalisedLabelHonoursUserAndContentLanguage(
        ?string $userLang,
        string $contentLang,
        string $expectedLabel
    ): void {

        $manifest = $this->loadFixture('manifest-v3.json');
        $file = $this->makeFile($manifest, 'v3-test', 'https://example.org/object/12345');

        $meta = $this->makeExtractor($contentLang)
            ->extract($file, $this->makeContext($userLang));

        self::assertSame($expectedLabel, $meta['ObjectName']['value']);
    }

    /**
     * `rights` / `license` is an IIIF v2 list — when none of its entries
     * is an HTTP URL string, urlFromLicenseField must fall through to ''.
     * Then MetadataExtractor walks the rest of its license-resolution
     * chain (provider metadata, provider landing) — both empty here too,
     * so no LicenseUrl/LicenseShortName appear at all.
     */
    public function testLicenseFieldWithListOfNonHttpStringsFallsThrough(): void
    {
        $manifest = [
            '@context' => 'http://iiif.io/api/presentation/2/context.json',
            'sequences' => [['canvases' => [
                ['width' => 1, 'height' => 1, 'images' => [['resource' => []]]],
            ]]],
            // Non-URL entries in the rights array; the helper iterates,
            // finds nothing matching `^https?://`, and returns '' (line 146).
            'rights' => ['not-a-url', 'still-not', ''],
        ];
        $file = $this->makeFile($manifest, 'unknown-provider', '');

        $meta = $this->makeExtractor()->extract($file, $this->makeContext());

        self::assertArrayNotHasKey('LicenseUrl', $meta);
        self::assertArrayNotHasKey('LicenseShortName', $meta);
    }

    /**
     * `preferredLanguages()` swallows any Throwable from
     * $context->getLanguage() (a malformed context, MW bootstrap
     * failure) and falls through to the content-language → 'en' chain.
     * Verify the catch-block contract: extraction still succeeds.
     */
    public function testPreferredLanguagesFallsThroughWhenContextLanguageThrows(): void
    {
        $context = $this->createStub(IContextSource::class);
        $context->method('getLanguage')
            ->willThrowException(new \RuntimeException('no language on context'));
        $context->method('msg')
            ->willReturnCallback(static fn (string $key) => new \Message($key));

        $manifest = $this->loadFixture('manifest-fotothek-v2.json');
        $file = $this->makeFile($manifest, 'deutsche-fotothek', 'http://example.org/landing');

        // Extraction must succeed despite the context's broken getLanguage.
        $meta = $this->makeExtractor('en')->extract($file, $context);

        self::assertArrayHasKey('ObjectName', $meta);
        self::assertNotSame('', $meta['ObjectName']['value']);
    }
}
