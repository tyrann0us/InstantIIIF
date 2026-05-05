<?php
/**
 * PHPStan stubs for MediaWiki namespaced classes.
 */

namespace MediaWiki\Title;

class Title {
    public static function newFromText(string $text, int $defaultNamespace = 0): ?Title {}
    public static function makeTitleSafe(int $ns, string $title, string $fragment = '', string $interwiki = ''): ?Title {}
    public function getDBkey(): string {}
    public function getText(): string {}
    public function getNamespace(): int {}
    public function getNsText(): string {}
    public function getPrefixedText(): string {}
}

namespace MediaWiki;

use GlobalVarConfig;
use MediaWiki\Http\HttpRequestFactory;
use RepoGroup;
use WANObjectCache;

class MediaWikiServices {
    public static function getInstance(): self {}
    public function getMainConfig(): GlobalVarConfig {}
    public function getMainWANObjectCache(): WANObjectCache {}
    public function getHttpRequestFactory(): HttpRequestFactory {}
    public function getRepoGroup(): RepoGroup {}
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
