<?php
/**
 * PHPStan stubs for MediaWiki namespaced classes.
 */

namespace MediaWiki\Title;

class Title {
    public static function newFromText(string $text, int $defaultNamespace = 0): ?Title {}
    public static function makeTitleSafe(int $ns, string $title): ?Title {}
    public function getDBkey(): string {}
    public function getNsText(): string {}
    public function getNamespace(): int {}
    /** @param string|string[] $query */
    public function getFullURL($query = '', $query2 = false, $proto = PROTO_RELATIVE): string {}
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
    public function getRepoGroup(): \RepoGroup {}
    public function getContentLanguage(): \Language {}
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

use MediaWiki\Title\Title;

interface IContextSource {
    public function getLanguage(): \Language;
}

class RequestContext implements IContextSource {
    public static function getMain(): self {}
    public function getTitle(): ?Title {}
    public function getRequest(): \WebRequest {}
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
