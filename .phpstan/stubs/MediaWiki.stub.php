<?php
/**
 * PHPStan stubs for MediaWiki global classes used by InstantIIIF.
 *
 * These stubs provide type information for static analysis without
 * requiring MediaWiki to be installed.
 */

// Global constants
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

class File {
    /** @var FileRepo */
    public $repo;

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

class Title {
    public static function newFromText(string $text, int $defaultNamespace = 0): ?Title {}
    public static function makeTitleSafe(int $ns, string $title, string $fragment = '', string $interwiki = ''): ?Title {}
    public function getDBkey(): string {}
    public function getText(): string {}
    public function getNamespace(): int {}
    public function getNsText(): string {}
    public function getPrefixedText(): string {}
}

class ThumbnailImage {
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
    public function getUrl(): string {}
}

class MediaTransformError {
    public function __construct(string $msg, int $width, int $height, string $path = '') {}
}

class OutputPage {
    /** @param string|string[] $modules */
    public function addModules($modules): void {}
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

    /** @return array<string, mixed> */
    public function extractRequestParams(): array {}
    public function getModuleName(): string {}
    public function getResult(): ApiResult {}

    /** @return array<string, array<string, mixed>> */
    public function getAllowedParams(): array {}

    /** @return array<string, string> */
    protected function getExamplesMessages(): array {}
}

class ApiQuery {
}

class ApiResult {
    /**
     * @param array<string|int>|null $path
     * @param string|int $name
     * @param mixed $value
     */
    public function addValue($path, $name, $value): bool {}
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
    public function isGood(): bool {}
}

class RepoGroup {
    /** @return array<string, array<string, mixed>> */
    public function getLocalInfo(): array {}
    /** @return array<int, array<string, mixed>> */
    public function getForeignInfo(): array {}
    public function getRepo(string $name): ?FileRepo {}
}
