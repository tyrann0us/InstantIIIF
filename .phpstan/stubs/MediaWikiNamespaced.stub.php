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
use MediaWiki\Utils\UrlUtils;
use WANObjectCache;

class MediaWikiServices {
    public static function getInstance(): self {}
    public function getMainConfig(): GlobalVarConfig {}
    public function getMainWANObjectCache(): WANObjectCache {}
    public function getHttpRequestFactory(): HttpRequestFactory {}
    public function getRepoGroup(): \RepoGroup {}
    public function getContentLanguage(): \Language {}
    public function getUrlUtils(): UrlUtils {}
}

class MainConfigNames {
    public const ScriptPath = 'ScriptPath';
    public const Script = 'Script';
    public const UploadDirectory = 'UploadDirectory';
}

namespace MediaWiki\Utils;

class UrlUtils {
    /**
     * @param string $url
     * @param int|null $defaultProto
     */
    public function expand(string $url, ?int $defaultProto = null): ?string {}
}

namespace MediaWiki\Html;

class Html {
    /** @param array<string, mixed> $attribs */
    public static function element(string $element, array $attribs = [], string $contents = ''): string {}
    /** @param array<string, mixed> $attribs */
    public static function rawElement(string $element, array $attribs = [], string $contents = ''): string {}
    /** @param array<string, mixed> $attribs */
    public static function openElement(string $element, array $attribs = []): string {}
    public static function closeElement(string $element): string {}
    public static function errorBox(string $html, string $heading = '', string $className = ''): string {}
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
    public function msg(string $key, mixed ...$params): \Message;
}

class RequestContext implements IContextSource {
    public static function getMain(): self {}
    public function getTitle(): ?Title {}
    public function getRequest(): \WebRequest {}
    public function getLanguage(): \Language {}
    public function msg(string $key, mixed ...$params): \Message {}
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

namespace MediaWiki\Output\Hook;

interface BeforePageDisplayHook {
    public function onBeforePageDisplay($out, $skin): void;
}

namespace MediaWiki\Hook;

interface ThumbnailBeforeProduceHTMLHook {
    public function onThumbnailBeforeProduceHTML($thumbnail, &$attribs, &$linkAttribs);
}

interface GetExtendedMetadataHook {
    public function onGetExtendedMetadata(&$combinedMeta, $file, $context, $single, &$maxCacheTime);
}

namespace MediaWiki\Page\Hook;

interface ImagePageFileHistoryLineHook {
    public function onImagePageFileHistoryLine($imageHistoryList, $file, &$line, &$css);
}

interface ImagePageShowTOCHook {
    public function onImagePageShowTOC($page, &$toc);
}
