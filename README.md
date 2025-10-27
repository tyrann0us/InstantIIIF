# InstantIIIF

MediaWiki extension to provide a `FileRepo` for file links (`[[File:...]]`) that hotlinks images from IIIF sources (Presentation API v2/v3).

## Table of Contents

* [Installation](#installation)
* [Usage](#usage)
* [Copyright and License](#copyright-and-license)
* [Contributing](#contributing)

## Installation

1. Place this directory into `extensions/InstantIIIF`.
2. Add to `LocalSettings.php`:

```php
wfLoadExtension( 'InstantIIIF' );

$wgForeignFileRepos[] = [
    'name'  => 'iiif',
    'class' => \MediaWiki\Extension\InstantIIIF\Repo::class,
    'hashLevels' => 0,
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

$wgInstantIIIFDefaultTimeout = 8; // optional
```
> [!NOTE]
> Currently, [Deutsche Fotothek](https://www.deutschefotothek.de/) is the only IIIF provider supported.

## Usage

```wikitext
[[File:df_bs_0007727_postkarte|400px]]
[[File:df_bs_0007727_postkarte|page=3|500px]]
```

`page` is 1-based. When a page index is out of range, a simple transform error is returned.

## Copyright and License

This package is [open-source software](https://opensource.org/license/MIT) distributed under
the terms of the MIT License. For the full license, see [LICENSE](./LICENSE).

## Contributing

All feedback, bug reports and pull requests are welcome.
