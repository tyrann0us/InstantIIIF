<?php
/**
 * PHPStan stubs for MediaWiki namespaced classes.
 */

namespace MediaWiki;

class MediaWikiServices {
    public static function getInstance(): self {}
    public function getMainConfig(): \GlobalVarConfig {}
    public function getMainWANObjectCache(): \WANObjectCache {}
    public function getHttpRequestFactory(): \MediaWiki\Http\HttpRequestFactory {}
}

namespace MediaWiki\Http;

class HttpRequestFactory {
    /**
     * @param string $url
     * @param array<string, mixed> $options
     * @param string|null $caller
     */
    public function create(string $url, array $options = [], ?string $caller = null): \MWHttpRequest {}
}

class GlobalVarConfig {
    /** @return mixed */
    public function get(string $name) {}
}
