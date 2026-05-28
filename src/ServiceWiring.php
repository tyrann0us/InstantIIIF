<?php

declare(strict_types=1);

use MediaWiki\Extension\InstantIIIF\MetadataExtractor;
use MediaWiki\MediaWikiServices;

return [
    'InstantIIIF.MetadataExtractor' => static function (
        MediaWikiServices $services
    ): MetadataExtractor {
        return new MetadataExtractor($services->getContentLanguage());
    },
];
