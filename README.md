# InstantIIIF

[![PHP QA](https://github.com/tyrann0us/InstantIIIF/actions/workflows/quality-assurance-php.yml/badge.svg)](https://github.com/tyrann0us/InstantIIIF/actions/workflows/quality-assurance-php.yml)
[![JS QA](https://github.com/tyrann0us/InstantIIIF/actions/workflows/quality-assurance-js.yml/badge.svg)](https://github.com/tyrann0us/InstantIIIF/actions/workflows/quality-assurance-js.yml)
[![Integration Tests](https://github.com/tyrann0us/InstantIIIF/actions/workflows/integration-tests.yml/badge.svg)](https://github.com/tyrann0us/InstantIIIF/actions/workflows/integration-tests.yml)
[![E2E Tests](https://github.com/tyrann0us/InstantIIIF/actions/workflows/e2e-tests.yml/badge.svg)](https://github.com/tyrann0us/InstantIIIF/actions/workflows/e2e-tests.yml)
[![codecov](https://codecov.io/gh/tyrann0us/InstantIIIF/graph/badge.svg)](https://codecov.io/gh/tyrann0us/InstantIIIF)

MediaWiki extension that registers a virtual `FileRepo`, so ordinary file links (`[[File:...]]`) hotlink images from remote [IIIF](https://iiif.io/) sources (Presentation API v2 / v3). Nothing has to be uploaded. Inspired by [Instant Commons](https://www.mediawiki.org/wiki/InstantCommons).

## Table of contents

* [Legal notice](#legal-notice)
* [Installation](#installation)
* [Supported providers](#supported-providers)
* [Usage](#usage)
* [Configuration reference](#configuration-reference)
* [Diagnostics: Special:InstantIIIFInspect](#diagnostics-specialinstantiiifinspect)
* [Copyright and license](#copyright-and-license)
* [Contributing](#contributing)

## Legal notice

> [!WARNING]
> **Only embed images from IIIF providers whose terms permit external display and hotlinking, including the supported ones listed below.**
>
> InstantIIIF fetches images directly from the configured IIIF servers. The wiki acts as the publisher of every embedded image, so you are responsible for having the right to display it.
>
> * Cultural-heritage institutions usually publish reuse terms next to each digital object (public domain, CC-licensed, "non-commercial only", or "all rights reserved"). Read them before embedding.
> * Some providers run public IIIF endpoints but **do not allow third-party hotlinking**, even for public-domain works. Hotlinking such resources can violate their terms of service or applicable copyright law.
> * The maintainers of this extension **accept no legal responsibility** for content embedded via InstantIIIF.
>
> **If in doubt, ask the provider in writing whether you may display their IIIF resources in your wiki.**

## Installation

1. Place this directory into `extensions/InstantIIIF`.
2. Add to `LocalSettings.php`:

```php
wfLoadExtension( 'InstantIIIF' );

$wgForeignFileRepos[] = [
    'name'        => 'iiif',
    'class'       => \MediaWiki\Extension\InstantIIIF\Infrastructure\MediaWiki\Repo::class,
    'hashLevels'  => 0,
    'iiifSources' => [
        [
            'id'              => 'deutsche-fotothek',
            'idPattern'       => '/^df_[a-z0-9_-]+$/i',
            'manifestPattern' => 'https://iiif.arthistoricum.net/proxy/fotothek/$1/manifest.json',
        ],
        [
            'id'              => 'slub-dresden',
            'idPattern'       => '/^[0-9]+-[0-9]+$/',
            'manifestPattern' => 'https://iiif.slub-dresden.de/iiif/2/$1/manifest.json',
        ],
        [
            'id'              => 'digitale-sammlungen',
            'idPattern'       => '/^bsb[0-9]+$/',
            'manifestPattern' => 'https://api.digitale-sammlungen.de/iiif/presentation/v2/$1/manifest',
        ],
        // add more providers here…
    ],
];

// Optional: HTTP timeout for manifest / info.json fetches in seconds (default 5).
$wgInstantIIIFDefaultTimeout = 8;
```

## Supported providers

InstantIIIF is a generic IIIF Presentation API v2 / v3 client and should work with most IIIF endpoints without changes. Only the providers listed below are *officially supported*, which here means:

* the extension has been tested against real manifests from these providers, and
* it ships provider-specific fallbacks that always extract the landing-page URL of the original work (used for the "Source" link in MultimediaViewer and on the file description page) and the license / rights URL (used for the "License" label and the "More info" attribution).

| Provider                                                                      | `id` to use           | Manifest pattern (`$1` is the identifier)                             | Multi-page |
|-------------------------------------------------------------------------------|-----------------------|-----------------------------------------------------------------------|------------|
| [Deutsche Fotothek](https://www.deutschefotothek.de/)                         | `deutsche-fotothek`   | `https://iiif.arthistoricum.net/proxy/fotothek/$1/manifest.json`      | Yes        |
| [SLUB Dresden](https://digital.slub-dresden.de/)                              | `slub-dresden`        | `https://iiif.slub-dresden.de/iiif/2/$1/manifest.json`                | Yes        |
| [Münchner Digitalisierungszentrum (BSB)](https://www.digitale-sammlungen.de/) | `digitale-sammlungen` | `https://api.digitale-sammlungen.de/iiif/presentation/v2/$1/manifest` | Yes        |

### Unsupported providers

Any other IIIF Presentation API v2 / v3 endpoint can be added to `iiifSources`. Most providers put the relevant metadata at the manifest's top level (`label`, `attribution` / `requiredStatement`, `homepage` / `related`, `license` / `rights`), where the generic code reads it. Provider-specific fallbacks are only needed when a manifest keeps this metadata inside `metadata` entries, as SLUB does for the license URL.

If a new provider renders incorrectly, [open an issue](https://github.com/tyrann0us/InstantIIIF/issues). A new mapping is usually a few lines of code.

## Usage

Once at least one source is configured, reference an object by its identifier, with the same syntax as a local file:

### Single-page image

```wikitext
[[File:df_dk_0007450|400px]]
[[File:df_dk_0007450|thumb|Ansicht von Meißen]]
[[File:Df_dk_0000856|mini]]
```

(`df_dk_0007450` is matched by the `deutsche-fotothek` `idPattern` and resolved against the configured manifest URL.)

### Multi-page document: pick a canvas

```wikitext
[[File:1741646995-18800000|page=3|mini]]
[[File:bsb11610364|page=6|mini]]
[[File:bsb00127289|page=2|mini]]
```

`page` is 1-based. An out-of-range page index renders a transform error in place of the image, so the problem shows up in preview.

### Visual editor

IIIF identifiers also resolve in VisualEditor's "Insert media" dialog. Type the identifier into the search field and the matching file appears as a result.

## Configuration reference

Top level of the `$wgForeignFileRepos[]` entry:

| Key                | Required | Description                                                                                                                                                              |
|--------------------|----------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `name`             | yes      | The MediaWiki repo name. Conventionally `iiif`.                                                                                                                          |
| `class`            | yes      | Must be `\MediaWiki\Extension\InstantIIIF\Infrastructure\MediaWiki\Repo::class`.                                                                                         |
| `hashLevels`       | yes      | `0`. IIIF has no local storage, but FileRepo needs the field.                                                                                                            |
| `iiifSources`      | yes      | List of provider entries (see below).                                                                                                                                    |
| `imageCacheExpiry` | no       | Local image-cache TTL in seconds; `0` disables caching. Defaults to `31536000` (one year), so caching is on by default. See [Local image caching](#local-image-caching). |

Each entry in `iiifSources`:

| Key               | Required | Description                                                                                                                                                                                                                                                                                            |
|-------------------|----------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `id`              | yes      | Short provider identifier. Use one of the officially supported IDs above to enable provider-specific fallbacks. Any other string works for unsupported providers.                                                                                                                                      |
| `manifestPattern` | yes      | URL of the IIIF manifest. `$1` is replaced with the file identifier.                                                                                                                                                                                                                                   |
| `idPattern`       | yes      | PHP regex (**with delimiters**, e.g. `/^df_[a-z0-9_-]+$/i`) constraining which file titles route to this source. It limits which identifiers are fetched from this provider, so no manifest request goes out for ids that belong elsewhere. For a single catch-all source, use `/./` (matches any id). |

Optional globals:

| Variable                       | Default | Description                                                                                       |
|--------------------------------|---------|---------------------------------------------------------------------------------------------------|
| `$wgInstantIIIFDefaultTimeout` | `5`     | HTTP timeout in seconds for fetching IIIF manifests, `info.json`, and (when caching) image bytes. |

### Local image caching

By default InstantIIIF caches image bytes locally, so each distinct `(image, size)` is fetched from the provider at most once and served from the wiki after that. This covers inline thumbnails and the full-resolution image (MultimediaViewer, the "Original file" link, the imageinfo `url` field). The manifest / `info.json` WAN cache uses the same TTL. Caching is write-once: IIIF object bytes are immutable, so a stored copy is never revalidated against the provider. This keeps traffic to the source institution low.

Cached bytes live in a dedicated `thumb` zone, by default the `iiif-cache` container under the repo's `directory` (served from `$wgUploadPath/iiif-cache`). `directory` defaults to `$wgUploadDirectory` and need not be set. The web server must be able to write to the cache directory, and the URL must be served.

```php
$wgForeignFileRepos[] = [
    // …name, class, iiifSources…
    'imageCacheExpiry' => 31536000, // 1 year (default). Set to 0 to disable.
];
```

To move the cache elsewhere (for example onto a separate volume), configure the `thumb` zone the usual FileRepo way. An explicit `zones.thumb` is never overridden:

```php
    'zones' => [ 'thumb' => [
        'container' => 'iiif-cache',
        'url'       => '/iiif-cache',   // must map to the served directory
    ] ],
```

There is no automatic eviction. Cached files stay until you remove them, for example by pruning the cache directory by modification time.

## Diagnostics: Special:InstantIIIFInspect

Sysops (anyone with the `instantiiif-inspect` right) can open `Special:InstantIIIFInspect` to see what InstantIIIF extracts from a IIIF manifest URL. This is the same metadata the parser, MultimediaViewer and the VisualEditor media search get. Use it when

* adding a provider and checking that label, attribution, license URL and landing-page URL come through,
* debugging a manifest that renders with missing or wrong metadata in MMV,
* checking whether the wiki server can reach a remote IIIF endpoint at all.

Paste a manifest URL and optionally pick a provider ID to apply that provider's metadata fallbacks. The page shows a summary table (manifest URL, effective provider ID, canvas count, label, attribution, credit HTML, license URL and short name, provider landing URL) and a table per canvas (page number, dimensions, IIIF Image Service `@id`). Result URLs can be bookmarked.

## Copyright and license

This package is [open-source software](https://opensource.org/license/MIT) distributed under the terms of the MIT License. See [LICENSE](./LICENSE) for the full text.

## Contributing

Feedback, bug reports and pull requests are welcome. To start a discussion, [open an issue](https://github.com/tyrann0us/InstantIIIF/issues).
