<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap for standalone (non-MediaWiki) tests.
 *
 * Loads the Composer autoloader and defines the minimal set of MediaWiki
 * constants, functions, and stub classes that the extension code references.
 */

// 1. Composer autoloader (loads extension classes via PSR-4).
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

// 2. MediaWiki constants.
if (!defined('MEDIATYPE_BITMAP')) {
    define('MEDIATYPE_BITMAP', 'BITMAP');
}
if (!defined('NS_FILE')) {
    define('NS_FILE', 6);
}
if (!defined('PROTO_RELATIVE')) {
    define('PROTO_RELATIVE', 2);
}
if (!defined('PROTO_HTTPS')) {
    define('PROTO_HTTPS', 1);
}

// 3. Global function stubs.
if (!function_exists('wfMessage')) {
    function wfMessage(string $key, ...$params): object
    {
        return new class ($key) {
            public function __construct(private string $key)
            {
            }

            public function text(): string
            {
                return "({$this->key})";
            }

            public function plain(): string
            {
                return "({$this->key})";
            }

            public function parse(): string
            {
                return "({$this->key})";
            }

            public function inContentLanguage(): self
            {
                return $this;
            }
        };
    }
}

if (!function_exists('wfTimestamp')) {
    function wfTimestamp(string $type = 'TS_MW', $ts = 0): string|false
    {
        return false;
    }
}

// 4. Load stub files that define MW classes in their proper namespaces.
require_once __DIR__ . '/stubs/global-classes.php';
require_once __DIR__ . '/stubs/mediawiki-namespaced.php';
