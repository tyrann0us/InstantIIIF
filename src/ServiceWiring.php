<?php

declare(strict_types=1);

use MediaWiki\Extension\InstantIIIF\Infrastructure\MediaWiki\MetadataExtractor;
use MediaWiki\MediaWikiServices;

return [
    'InstantIIIF.MetadataExtractor' => static function (
        MediaWikiServices $services
    ): MetadataExtractor {
        return new MetadataExtractor($services->getContentLanguage());
    },
];
