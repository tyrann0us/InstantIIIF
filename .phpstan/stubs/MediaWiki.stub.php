<?php
/**
 * PHPStan stubs for MediaWiki global classes used by InstantIIIF.
 *
 * These stubs provide type information for static analysis without
 * requiring MediaWiki to be installed.
 */

// Global constants
use MediaWiki\Title\Title;

define('MEDIATYPE_BITMAP', 'BITMAP');
define('NS_FILE', 6);

/**
 * @phpstan-type RepoInfo array{name?: string, class?: string, iiifSources?: array}
 */
class FileRepo {
    /** @var array<string, mixed> */
    protected $info;

    /** @param array<string, mixed> $info */
    public function __construct(array $info = []) {}

    public function getName(): string {}
}

class MediaTransformOutput {
}

abstract class MediaHandler {
    /** @return array<string, string> */
    public function getParamMap(): array {}
    /** @param string $name @param mixed $value */
    public function validateParam($name, $value): bool {}
    /** @param array<string, mixed> $params @return string|false */
    public function makeParamString($params) {}
    /** @param string $str @return array<string, mixed>|false */
    public function parseParamString($str) {}
    /** @param mixed $state @param string $path @return array<string, mixed> */
    public function getSizeAndMetadata($state, $path): array {}
    /** @param File $file */
    public function mustRender($file): bool {}
    /** @param File $file */
    public function isExpensiveToThumbnail($file): bool {}
    /**
     * @param File $image
     * @param string $dstPath
     * @param string $dstUrl
     * @param array<string, mixed> $params
     * @param int $flags
     * @return MediaTransformOutput
     */
    abstract public function doTransform($image, $dstPath, $dstUrl, $params, $flags = 0);
}

abstract class ImageHandler extends MediaHandler {
}

class File {
    /** @var FileRepo */
    public $repo;

    /** @var MediaHandler|false */
    protected $handler;

    /**
     * @param Title $title
     * @param FileRepo $repo
     * @param string|false $time
     */
    public function __construct(Title $title, FileRepo $repo, $time = false) {}
    public function exists(): bool {}
    public function getSize(): int {}
    public function getMimeType(): string {}
    public function getMediaType(): string {}
    public function isMultipage(): bool {}
    public function pageCount(): int {}
    /** @return MediaHandler|false */
    public function getHandler() {}
    /** @param int $page */
    public function getWidth($page = 1): int {}
    /** @param int $page */
    public function getHeight($page = 1): int {}
    public function getUrl(): string {}
    public function getFullUrl(): string {}
    public function getDescriptionUrl(): string {}
    public function getTitle(): ?Title {}
    /**
     * @param array<string, mixed> $params
     * @param int $flags
     * @return ThumbnailImage|MediaTransformError
     */
    public function transform($params, $flags = 0) {}
}

class ThumbnailImage extends MediaTransformOutput {
    /**
     * @param File $file
     * @param string $url
     * @param string|false $path
     * @param array<string, mixed> $parameters
     */
    public function __construct($file, $url, $path = false, $parameters = []) {}
    public function getFile(): File {}
    public function getWidth(): int {}
    public function getHeight(): int {}
}

class MediaTransformError {
    public function __construct(string $msg, int $width, int $height, string $path = '') {}
}

class OutputPage {
    /** @param string|string[] $modules */
    public function addModules($modules): void {}
    public function addInlineStyle(string $style): void {}
}

class Skin {
}

class MWNamespace {
    public static function getCanonicalName(int $index): string|false {}
}

class ApiQueryBase {
    /** @var ApiQuery */
    protected $mQuery;

    public function __construct(ApiQuery $query, string $moduleName) {}
    public function execute(): void {}
}

class ApiQuery {
}

class GlobalVarConfig {
    /** @return mixed */
    public function get(string $name) {}
}

class WANObjectCache {
    public function makeKey(string ...$components): string {}
    /**
     * @param string $key
     * @param int $ttl
     * @param callable $callback
     * @param array<string, mixed> $opts
     * @return mixed
     */
    public function getWithSetCallback(string $key, int $ttl, callable $callback, array $opts = []) {}
}

class MWHttpRequest {
    public function execute(): StatusValue {}
    public function getContent(): string {}
}

class StatusValue {
    public function isOK(): bool {}
}

/**
 * @param string $key
 * @param mixed ...$params
 * @return Message
 */
function wfMessage(string $key, ...$params): Message {}

class Message {
    public function text(): string {}
    public function plain(): string {}
    public function parse(): string {}
    public function inContentLanguage(): self {}
}
