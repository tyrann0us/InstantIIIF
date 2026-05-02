# InstantIIIF

MediaWiki extension to provide a `FileRepo` for file links (`[[File:...]]`) that hotlinks images from IIIF sources (Presentation API v2/v3).

## Features

- Embed images from any IIIF-compliant provider using standard wiki syntax
- Support for both IIIF Presentation API v2 and v3 manifests
- Multi-page document support with `|page=N|` parameter
- Automatic thumbnail generation respecting IIIF service limits
- MultimediaViewer integration
- Zero external PHP dependencies (native manifest parser)

## Architecture

This extension follows Domain-Driven Design principles with a three-layer architecture:

```
src/
├── Domain/           # Business logic, no framework dependencies
│   ├── Entity/       # IIIFResource aggregate
│   ├── ValueObject/  # ObjectId, ImageDimensions, ServiceId, etc.
│   ├── Service/      # ImageUrlBuilder, ServiceLimitCalculator
│   ├── Repository/   # Interfaces for data access
│   └── Parser/       # ManifestParserInterface
├── Application/      # Use cases, orchestration
│   ├── Query/        # ResolveResourceQuery, GetThumbnailQuery
│   ├── Service/      # ResourceResolver, ThumbnailService
│   └── ReadModel/    # ResolvedResource, ThumbnailResult
└── Infrastructure/   # Framework integrations
    ├── MediaWiki/    # Repo, IIIFFile, Hooks
    ├── Repository/   # HTTP implementations with caching
    └── Parser/       # NativeManifestParser
```

## Installation

1. Place this directory into `extensions/InstantIIIF`.
2. Run `composer install` in the extension directory.
3. Add to `LocalSettings.php`:

```php
wfLoadExtension( 'InstantIIIF' );

$wgForeignFileRepos[] = [
    'name'  => 'iiif',
    'class' => \MediaWiki\Extension\InstantIIIF\Infrastructure\MediaWiki\Repo::class,
    'hashLevels' => 0,
    'directory' => $wgUploadDirectory,
    'iiifSources' => [
        [
            'id' => 'fotothek',
            'idPattern' => '^[A-Za-z0-9_\-]+$',
            'manifestPattern'   => 'https://iiif.arthistoricum.net/proxy/fotothek/$1/manifest.json',
            'landingUrlPattern' => 'https://www.deutschefotothek.de/$1'
        ],
        // add more providers here…
    ],
];

$wgInstantIIIFDefaultTimeout = 8; // optional, default: 5
```

## Usage

```wikitext
[[File:df_bs_0007727_postkarte|400px]]
[[File:df_bs_0007727_postkarte|page=3|500px]]
```

- `page` is 1-based
- When a page index is out of range, a transform error is returned

## Configuration

### Provider Configuration

Each provider in `iiifSources` accepts:

| Key | Required | Description |
|-----|----------|-------------|
| `id` | Yes | Unique provider identifier |
| `idPattern` | No | Regex pattern to match object IDs (without delimiters) |
| `manifestPattern` | Yes | URL pattern with `$1` placeholder for object ID |
| `landingUrlPattern` | No | Human-readable page URL pattern |

### Global Settings

| Setting | Default | Description |
|---------|---------|-------------|
| `$wgInstantIIIFDefaultTimeout` | 5 | HTTP timeout in seconds |

## Development

```bash
# Install dependencies
composer install

# Run code style checks
composer phpcs

# Run static analysis
composer phpstan

# Run layer dependency checks
composer deptrac

# Run tests
composer test

# Run tests with coverage
composer test:coverage
```

## Testing

The extension includes unit tests for Domain and Application layers that run without MediaWiki:

```bash
composer test
```

## Copyright and License

This package is [open-source software](https://opensource.org/license/MIT) distributed under the terms of the MIT License. See [LICENSE](./LICENSE) for details.

## Contributing

All feedback, bug reports and pull requests are welcome.
