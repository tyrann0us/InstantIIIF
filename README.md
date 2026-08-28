# InstantIIIF

[![PHP QA](https://github.com/tyrann0us/InstantIIIF/actions/workflows/quality-assurance-php.yml/badge.svg)](https://github.com/tyrann0us/InstantIIIF/actions/workflows/quality-assurance-php.yml)
[![JS QA](https://github.com/tyrann0us/InstantIIIF/actions/workflows/quality-assurance-js.yml/badge.svg)](https://github.com/tyrann0us/InstantIIIF/actions/workflows/quality-assurance-js.yml)
[![Integration Tests](https://github.com/tyrann0us/InstantIIIF/actions/workflows/integration-tests.yml/badge.svg)](https://github.com/tyrann0us/InstantIIIF/actions/workflows/integration-tests.yml)
[![E2E Tests](https://github.com/tyrann0us/InstantIIIF/actions/workflows/e2e-tests.yml/badge.svg)](https://github.com/tyrann0us/InstantIIIF/actions/workflows/e2e-tests.yml)
[![codecov](https://codecov.io/gh/tyrann0us/InstantIIIF/graph/badge.svg)](https://codecov.io/gh/tyrann0us/InstantIIIF)

MediaWiki extension that registers a virtual `FileRepo` so that ordinary file links (`[[File:...]]`) hotlink images from remote [IIIF](https://iiif.io/) sources (Presentation API v2 / v3) instead of requiring a local upload. Inspired by [Instant Commons](https://www.mediawiki.org/wiki/InstantCommons).

## Table of Contents

* [Legal Notice](#legal-notice)
* [Installation](#installation)
* [Supported Providers](#supported-providers)
* [Usage](#usage)
* [Configuration Reference](#configuration-reference)
* [Diagnostics: Special:InstantIIIFInspect](#diagnostics-specialinstantiiifinspect)
* [Copyright and License](#copyright-and-license)
* [Contributing](#contributing)

## Legal Notice

> [!WARNING]
> **Only embed images from IIIF providers whose terms permit external display and hotlinking, including the supported ones listed below.**
>
> InstantIIIF fetches images directly from the configured IIIF servers. The wiki acts as the publisher of every embedded image, so you are responsible for ensuring you have the right to display it.
>
> * Cultural-heritage institutions usually publish reuse terms next to each digital object — public-domain, CC-licensed, "non-commercial only", or "all rights reserved". Read them before embedding.
> * Some providers expose IIIF endpoints technically but **do not allow third-party hotlinking** (even for public-domain works). Hotlinking such resources can violate their terms of service or applicable copyright law.
> * The maintainers of this extension **accept no legal responsibility** for content embedded via InstantIIIF.
>
> **When in doubt: contact the provider in writing and ask whether displaying their IIIF resources in your wiki is permitted.**

## Installation

1. Place this directory into `extensions/InstantIIIF`.
2. Add to `LocalSettings.php`:

```php
wfLoadExtension( 'InstantIIIF' );

$wgForeignFileRepos[] = [
    'name'        => 'iiif',
    'class'       => \MediaWiki\Extension\InstantIIIF\Repo::class,
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

## Supported Providers

InstantIIIF is a generic IIIF Presentation API v2 / v3 client and will likely **work out of the box with most IIIF endpoints**. However, only the providers listed below are *officially supported*, which here means:

* the extension has been tested against real manifests from these providers, and
* it ships provider-specific fallbacks that always extract the **landing-page URL** of the original work (used for the "Source" link in MultimediaViewer and the file description page) and the **license / rights URL** (used for the "License" label and the "More info" attribution).

| Provider                                                                      | `id` to use           | Manifest pattern (`$1` is the identifier)                             | Multi-page |
|-------------------------------------------------------------------------------|-----------------------|-----------------------------------------------------------------------|------------|
| [Deutsche Fotothek](https://www.deutschefotothek.de/)                         | `deutsche-fotothek`   | `https://iiif.arthistoricum.net/proxy/fotothek/$1/manifest.json`      | Yes        |
| [SLUB Dresden](https://digital.slub-dresden.de/)                              | `slub-dresden`        | `https://iiif.slub-dresden.de/iiif/2/$1/manifest.json`                | Yes        |
| [Münchner Digitalisierungszentrum (BSB)](https://www.digitale-sammlungen.de/) | `digitale-sammlungen` | `https://api.digitale-sammlungen.de/iiif/presentation/v2/$1/manifest` | Yes        |

### Unsupported providers

Any other IIIF Presentation API v2 / v3 endpoint can be added to `iiifSources`. In practice most providers expose all relevant metadata at the manifest's top level (`label`, `attribution` / `requiredStatement`, `homepage` / `related`, `license` / `rights`), and the generic code paths handle them. Provider-specific fallbacks only kick in when a manifest hides this metadata inside `metadata` entries (as e.g. SLUB does for the license URL).

If you configure a new provider and something renders incorrectly, [open an issue](https://github.com/tyrann0us/InstantIIIF/issues) — adding a mapping is typically a few lines of code.

## Usage

After configuring at least one source, reference any object by its identifier — exactly the same syntax as a local file:

### Single-page image

```wikitext
[[File:df_dk_0007450|400px]]
[[File:df_dk_0007450|thumb|Ansicht von Meißen]]
[[File:Df_dk_0000856|mini]]
```

(`df_dk_0007450` is matched by the `deutsche-fotothek` `idPattern` and resolved against the configured manifest URL.)

### Multi-page document — pick a canvas

```wikitext
[[File:1741646995-18800000|page=3|mini]]
[[File:bsb11610364|page=6|mini]]
[[File:bsb00127289|page=2|mini]]
```

`page` is 1-based. When the page index is out of range, a simple transform error is rendered in place of the image, so the failure is visible during preview.

### Visual editor

Once the extension is loaded, IIIF identifiers also resolve in VisualEditor's "Insert media" dialog: type the identifier into the search input and the matching file appears as a result.

## Configuration Reference

`$wgForeignFileRepos[]` entry — top level:

| Key                | Required | Description                                                                                                                                                                  |
|--------------------|----------|------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `name`             | yes      | The MediaWiki repo name. Conventionally `iiif`.                                                                                                                              |
| `class`            | yes      | Must be `\MediaWiki\Extension\InstantIIIF\Repo::class`.                                                                                                                      |
| `hashLevels`       | yes      | `0` — IIIF has no local storage, but FileRepo needs the field.                                                                                                               |
| `iiifSources`      | yes      | List of provider entries (see below).                                                                                                                                        |
| `imageCacheExpiry` | no       | Local image-cache TTL in seconds; `0` disables caching. Defaults to `31536000` (one year), so caching is **on by default**. See [Local image caching](#local-image-caching). |

Each entry in `iiifSources`:

| Key               | Required | Description                                                                                                                                                                                                                                                                                                           |
|-------------------|----------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `id`              | yes      | Short provider identifier. Use one of the officially-supported IDs above to enable provider-specific fallbacks; any other string is fine for unsupported providers.                                                                                                                                                   |
| `manifestPattern` | yes      | URL of the IIIF manifest. `$1` is replaced with the file identifier.                                                                                                                                                                                                                                                  |
| `idPattern`       | yes      | PHP regex (**with delimiters**, e.g. `/^df_[a-z0-9_-]+$/i`) constraining which file titles route to this source. Required: it scopes which identifiers are fetched from this provider, so a manifest request is never fired for ids that belong elsewhere. For a single catch-all source, use `/./` (matches any id). |

Optional globals:

| Variable                       | Default | Description                                                                                       |
|--------------------------------|---------|---------------------------------------------------------------------------------------------------|
| `$wgInstantIIIFDefaultTimeout` | `5`     | HTTP timeout in seconds for fetching IIIF manifests, `info.json`, and (when caching) image bytes. |

### Local image caching

By default InstantIIIF caches image bytes locally so each distinct `(image, size)` is fetched from the provider **at most once**, then served from the wiki afterwards. This applies to inline thumbnails, the full-resolution image (MultimediaViewer, the "Original file" link, the imageinfo `url` field), and — via a shared TTL — the manifest / `info.json` WAN cache. Caching is write-once: a stored copy is never revalidated against the provider, since IIIF object bytes are immutable. The net effect is to keep outbound traffic to the source institution to a minimum.

Cached bytes are stored in a dedicated `thumb` zone, by default in the `iiif-cache` container under `$wgUploadDirectory` (served from `$wgUploadPath/iiif-cache`). The repo's `directory` is left untouched. The cache directory must be **writable by the web server** and the URL must be **web-served**.

```php
$wgForeignFileRepos[] = [
    // …name, class, iiifSources…
    'imageCacheExpiry' => 31536000, // 1 year (default). Set to 0 to disable.
];
```

To relocate the cache (e.g. onto a separate volume), configure the `thumb` zone the native FileRepo way — an explicit `zones.thumb` is respected and not overridden:

```php
    'zones' => [ 'thumb' => [
        'container' => 'iiif-cache',
        'url'       => '/iiif-cache',   // must map to the served directory
    ] ],
```

There is currently no automatic eviction — cached files accumulate until removed manually (e.g. by pruning the cache directory by modification time).

## Diagnostics: Special:InstantIIIFInspect

Sysops (anyone with the `instantiiif-inspect` right) can open `Special:InstantIIIFInspect` to preview exactly what InstantIIIF extracts from a given IIIF manifest URL — the same metadata the parser, MultimediaViewer and the VisualEditor media-search will see. Useful when:

* adding a new provider and checking that label, attribution, license URL, and landing-page URL come through correctly,
* debugging a manifest that renders with missing or wrong metadata in MMV,
* sanity-checking that a remote IIIF endpoint is reachable from the wiki server at all.

Paste a manifest URL, optionally pick a provider ID to apply its provider-specific metadata fallbacks, and the page renders a summary table (manifest URL, effective provider ID, canvas count, label, attribution, credit HTML, license URL + short name, provider landing URL) plus a per-canvas table (page number, dimensions, IIIF Image Service `@id`). Results URLs are bookmarkable.

## Copyright and License

This package is [open-source software](https://opensource.org/license/MIT) distributed under the terms of the MIT License. See [LICENSE](./LICENSE) for the full text.

## Contributing

All feedback, bug reports, and pull requests are welcome — please [open an issue](https://github.com/tyrann0us/InstantIIIF/issues) to start a discussion.
