<?php
/**
 * PHPStan stubs for MediaWiki namespaced classes.
 */

namespace MediaWiki\Title;

class Title {
    public static function newFromText(string $text, int $defaultNamespace = 0): ?Title {}
    public function getDBkey(): string {}
    public function getText(): string {}
    public function getNamespace(): int {}
    public function getNsText(): string {}
}

namespace MediaWiki;

use GlobalVarConfig;
use MediaWiki\Http\HttpRequestFactory;
use WANObjectCache;

class MediaWikiServices {
    public static function getInstance(): self {}
    public function getMainConfig(): GlobalVarConfig {}
    public function getMainWANObjectCache(): WANObjectCache {}
    public function getHttpRequestFactory(): HttpRequestFactory {}
}

namespace MediaWiki\Http;

use MWHttpRequest;

class HttpRequestFactory {
    /**
     * @param string $url
     * @param array<string, mixed> $options
     * @param string|null $caller
     */
    public function create(string $url, array $options = [], ?string $caller = null): MWHttpRequest {}
}

namespace MediaWiki\Context;

interface IContextSource {
}

namespace MediaWiki\Page;

use File;
use OutputPage;

class ImageHistoryList {
    public function getOutput(): OutputPage {}
}

class ImagePage {
    public function getDisplayedFile(): File {}
}
