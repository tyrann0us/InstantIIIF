# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

### PHP

```bash
composer phpcs           # code style check
composer phpcs:fix       # auto-fix style issues
composer phpstan         # static analysis
composer tests           # PHPUnit unit tests
composer tests:coverage  # unit tests + coverage report
composer tests:integration  # integration tests (requires Docker — see below)
```

Run a single test class:

```bash
vendor/bin/phpunit --filter ClassName tests/phpunit/Unit/
```

### JavaScript

```bash
npm test                 # Jest unit tests
npm run test:watch       # Jest in watch mode
npm run test:coverage    # Jest with coverage
npm run test:e2e         # Playwright end-to-end (requires Docker)
npm run lint:js          # ESLint (requires Node ≥ 16.9)
npm run lint:md          # Markdown lint
```

`lint:js` requires Node ≥ 16.9. The nvm default may be 14 — activate a suitable version first:

```bash
. ~/.nvm/nvm.sh && nvm use 20
```

### Docker (local wiki + mock IIIF server)

```bash
npm run docker:up   # start MediaWiki 1.44 at localhost:8080, mock IIIF at localhost:8111
npm run docker:down # tear down with volumes
```

Integration tests run inside the container; `composer tests:integration` handles this automatically.

---

## Architecture

The extension registers a virtual `FileRepo` so `[[File:…]]` wikitext hotlinks images from IIIF Presentation API v2/v3 sources without uploading anything.

### Layout

```text
src/
├── Domain/                        # Pure business logic — no MediaWiki dependencies
│   ├── Manifest.php               # Parses IIIF v2/v3 manifests; central value object
│   ├── Page.php                   # Value object: 1-based page/canvas index
│   ├── ImageService.php           # IIIF Image API wrapper; builds sized URLs
│   ├── Dimensions.php             # Width/height value object with "unknown" state
│   ├── LocalizedText.php          # Resolves IIIF language-map arrays to a string
│   ├── LicenseClassifier.php      # Maps license URLs → short names for MMV
│   ├── ProviderQuirks.php         # Provider-specific metadata fallbacks
│   └── ManifestFetcher.php        # Interface: fetch + decode a manifest by URL
├── Infrastructure/
│   ├── CachedHttpManifestFetcher.php  # ManifestFetcher impl: WAN cache + HTTP
│   └── MediaWiki/                 # MW adapter layer
│       ├── HookHandler.php        # 5 hooks: BeforePageDisplay, ThumbnailBeforeProduceHTML,
│       │                          #   ImagePageFileHistoryLine, ImagePageShowTOC,
│       │                          #   GetExtendedMetadata
│       ├── IIIFFile.php           # extends File — virtual file backed by a manifest
│       ├── IIIFHandler.php        # extends ImageHandler — adds page= param support
│       ├── IIIFTitle.php          # Static utility for the .jpg spoofing mechanism
│       ├── MetadataExtractor.php  # Builds extmetadata for MMV and the inspector page
│       ├── Repo.php               # extends FileRepo — newFile() returns IIIFFile
│       └── SpecialInstantIIIFInspect.php  # Admin diagnostic special page
└── ServiceWiring.php              # Registers InstantIIIF.MetadataExtractor service
```

### Data flow

```text
[[File:df_dk_0007450.jpg|thumb|page=2]]
       ↓
IIIFHandler::parseParamString()        (wikitext params → page number)
       ↓
IIIFFile::transform() / getUrl()
       ↓  ensureResolved():
Repo::iiifSources() → CachedHttpManifestFetcher::fetch(manifest URL)
       ↓
Manifest::imageServiceIdFor(page) → CachedHttpManifestFetcher::fetch(info.json)
       ↓
ImageService::sizedUrl()              (IIIF Image API v2 URL, clamped to service limits)
       ↓
ThumbnailImage returned to MediaWiki renderer

Metadata path (MMV + inspector):
HookHandler::onGetExtendedMetadata() / SpecialInstantIIIFInspect
       ↓
MetadataExtractor::extract(IIIFFile, language)
       ↓  LocalizedText, LicenseClassifier, ProviderQuirks
extmetadata array
```

### Dependency injection

`extension.json` declares the `HookHandler` with services `RepoGroup`, `NamespaceInfo`, and `InstantIIIF.MetadataExtractor`. `ServiceWiring.php` wires the extractor with `ContentLanguage`. `IIIFFile` and `Repo` are constructed by MW's FileRepo machinery and call `MediaWikiServices::getInstance()` directly (no constructor DI possible there).

---

## Key invariants

**`.jpg` spoofing** — `IIIFTitle::SPOOF_EXTENSION = 'jpg'` appends `.jpg` to all IIIF object IDs so MMV's `isValidExtension()` accepts them. Any code that round-trips a title back to the database must strip it with `IIIFTitle::unspoof()`.

**No-timestamp sentinel** — `IIIFFile::NO_TIMESTAMP_SENTINEL = '<>'` is returned by `getTimestamp()` to blank the API `timestamp` field. Do not return a falsy value — `wfTimestamp(TS_*, false)` silently returns "now".

**MMV `originalWidth`** — MMV reads `data-file-width` as `originalWidth` and caps lightbox thumbnails at that value. `IIIFFile::getWidth($page)` must return the canvas's full pixel width, not the rendered thumbnail's clamped width.

**Hook parameter types** — Hook interface method parameters must be left untyped (PHP LSP constraint). Suppress `Syde.Functions.ArgumentTypeDeclaration.NoArgumentType` via `// phpcs:ignore` on those methods. Return types can be added (covariant).

**PHPUnit suite is standalone** — bootstrapped from `tests/phpunit/bootstrap.php` with hand-written stubs under `tests/phpunit/stubs/`. It does not extend `MediaWikiIntegrationTestCase`. The `wfTimestamp()` stub always returns `false`. Use `createStub()` instead of `createMock()` — PHPUnit 13 emits "no expectations configured" notices for the latter.

**Multi-page AJAX pagination** — MW's `mediawiki.page.image.pagination` does an AJAX content swap and fires `mw.hook('wikipage.content')` with `$content = .mw-filepage-multipage` (not `#mw-content-text`). JS that reads DOM state must re-initialise on that hook.
