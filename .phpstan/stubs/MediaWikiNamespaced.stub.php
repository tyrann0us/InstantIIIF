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
    public function getRepoGroup(): \RepoGroup {}
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

namespace Wikimedia\ParamValidator;

class ParamValidator {
    public const PARAM_TYPE = 'param-type';
    public const PARAM_REQUIRED = 'param-required';
    public const PARAM_DEFAULT = 'param-default';
}

namespace Wikimedia\ParamValidator\TypeDef;

class IntegerDef {
    public const PARAM_MIN = 'param-min';
    public const PARAM_MAX = 'param-max';
}
