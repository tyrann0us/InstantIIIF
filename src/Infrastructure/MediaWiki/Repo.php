<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF\Infrastructure\MediaWiki;

use FileRepo;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use Wikimedia\FileBackend\FSFileBackend;

class Repo extends FileRepo
{
    /**
     * Default image-cache TTL: one year. IIIF object bytes (and their
     * manifests) are effectively immutable, so caching is enabled by
     * default; set `imageCacheExpiry => 0` in the repo config to disable.
     */
    public const DEFAULT_IMAGE_CACHE_EXPIRY = 31536000;

    /**
     * Container — and default URL leaf under $wgUploadPath — for cached IIIF
     * image bytes. Kept in a dedicated zone so the cache is isolated from
     * genuine local uploads (safe to prune wholesale, excludable from
     * backups, clearly an ephemeral hot-cache rather than a republication).
     */
    private const CACHE_CONTAINER = 'iiif-cache';

    /** @var array<int, array<string, mixed>> */
    private array $iiifSources = [];

    private int $imageCacheExpiry = 0;

    /**
     * @param array<string, mixed> $info
     */
    public function __construct(array $info)
    {
        // onRegistration() defaults 'directory' in the global config; this
        // covers repos constructed directly (tests) without that callback.
        // Core constructs the repo from the $info array, so there is no
        // constructor DI here — read the upload dir from the Config service.
        if (!isset($info['directory'])) {
            $info['directory'] = (string) MediaWikiServices::getInstance()
                ->getMainConfig()
                ->get(MainConfigNames::UploadDirectory);
        }

        $expiry = (int) ($info['imageCacheExpiry'] ?? self::DEFAULT_IMAGE_CACHE_EXPIRY);
        if ($expiry > 0) {
            $info = self::withCacheZone($info);
        }

        parent::__construct($info);

        $this->imageCacheExpiry = $expiry;
        $sources = $info['iiifSources'] ?? [];
        self::assertSourcesHaveIdPattern($sources);
        $this->iiifSources = $sources;
    }

    /**
     * Configure the `thumb` zone used to store cached IIIF image bytes.
     *
     * Leaves the repo's `directory` (the auto-backend root) untouched and
     * isolates the cache in its own container served from a dedicated URL,
     * defaulting to `{$wgUploadPath}/iiif-cache`. Supplying the URL is the
     * one genuinely new piece of config: FileRepo gives every repo a `thumb`
     * zone by default but with no `url`, so it cannot be served otherwise.
     * An explicitly configured `zones.thumb` is respected and never
     * overridden, so admins can relocate the cache the native FileRepo way.
     *
     * @param array<string, mixed> $info
     * @return array<string, mixed>
     */
    private static function withCacheZone(array $info): array
    {
        $zones = $info['zones'] ?? [];
        if (!is_array($zones)) {
            $zones = [];
        }
        if (isset($zones['thumb'])) {
            return $info;
        }
        $uploadPath = (string) MediaWikiServices::getInstance()
            ->getMainConfig()
            ->get(MainConfigNames::UploadPath);
        $zones['thumb'] = [
            'container' => self::CACHE_CONTAINER,
            'url' => rtrim($uploadPath, '/') . '/' . self::CACHE_CONTAINER,
        ];
        $info['zones'] = $zones;
        return $info;
    }

    /**
     * extension.json registration callback.
     *
     * `FileBackendGroup` builds a repo's auto-backend straight from the raw
     * `$wgForeignFileRepos` config — it never instantiates this class — and
     * reads `directory` unguarded, which core only defaults for
     * ForeignAPIRepo. Left alone, every request logs an "Undefined array key
     * directory" warning, and the auto-backend knows neither absolute paths
     * nor the `iiif-cache` container, so every cache write fails.
     *
     * Runs after SetupDynamicConfig (upload dir resolved, backend names
     * assigned) and before any service is built, so it may only touch
     * globals.
     */
    public static function onRegistration(): void
    {
        global $wgForeignFileRepos, $wgFileBackends, $wgUploadDirectory, $wgDirectoryMode;

        self::registerCacheBackends(
            $wgForeignFileRepos,
            $wgFileBackends,
            (string) $wgUploadDirectory,
            (int) $wgDirectoryMode
        );
    }

    /**
     * Default `directory` for every InstantIIIF repo entry and, where we manage
     * the default cache zone, register a named FSFileBackend in
     * `$wgFileBackends` that includes the `iiif-cache` container. A registered
     * backend makes FileBackendGroup skip its auto-backend for the repo and
     * build ours lazily via ObjectFactory, as core does for LocalRepo.
     *
     * An injected backend object (tests, FileBackendMultiWrite, admin config),
     * a relocated `zones.thumb`, disabled caching or an already registered
     * backend name are left untouched.
     *
     * @param array<int|string, array<string, mixed>> $repos
     * @param array<int|string, array<string, mixed>> $backends
     */
    public static function registerCacheBackends(
        array &$repos,
        array &$backends,
        string $uploadDirectory,
        int $directoryMode
    ): void {

        $registered = array_column($backends, 'name');
        foreach ($repos as &$info) {
            if (!is_a((string) ($info['class'] ?? ''), self::class, true)) {
                continue;
            }
            $info['directory'] ??= $uploadDirectory;
            $name = (string) $info['name'];
            $info['backend'] ??= $name . '-backend';
            $taken = in_array($info['backend'], $registered, true);
            if ($taken || !self::managesCacheBackend($info)) {
                continue;
            }
            $directory = rtrim((string) $info['directory'], '/');
            // Same shape as FileBackendGroup's auto-backend, plus our container.
            $backends[] = [
                'name' => $info['backend'],
                'class' => FSFileBackend::class,
                'lockManager' => $info['lockManager'] ?? 'fsLockManager',
                'containerPaths' => [
                    "{$name}-public" => $directory,
                    "{$name}-thumb" => $info['thumbDir'] ?? "{$directory}/thumb",
                    "{$name}-transcoded" => $info['transcodedDir'] ?? "{$directory}/transcoded",
                    "{$name}-deleted" => $info['deletedDir'] ?? false,
                    "{$name}-temp" => "{$directory}/temp",
                    self::CACHE_CONTAINER => "{$directory}/" . self::CACHE_CONTAINER,
                ],
                'fileMode' => $info['fileMode'] ?? 0644,
                'directoryMode' => $directoryMode,
            ];
            $registered[] = $info['backend'];
        }
        unset($info);
    }

    /**
     * Whether caching is on, no backend object was injected, and the `thumb`
     * zone is (or will be, via withCacheZone()) our dedicated cache container
     * rather than one the admin relocated and backs themselves.
     *
     * @param array<string, mixed> $info
     */
    private static function managesCacheBackend(array $info): bool
    {
        $expiry = (int) ($info['imageCacheExpiry'] ?? self::DEFAULT_IMAGE_CACHE_EXPIRY);
        if ($expiry <= 0 || is_object($info['backend'] ?? null)) {
            return false;
        }
        $zones = $info['zones'] ?? [];
        $thumb = is_array($zones) ? ($zones['thumb'] ?? null) : null;
        return $thumb === null
            || (is_array($thumb) && ($thumb['container'] ?? null) === self::CACHE_CONTAINER);
    }

    /**
     * Whether local image caching is enabled for this repo.
     */
    public function cacheImagesEnabled(): bool
    {
        return $this->imageCacheExpiry > 0;
    }

    /**
     * Image-cache TTL in seconds; 0 when caching is disabled. Also governs
     * the manifest/info.json WAN cache (see CachedHttpManifestFetcher).
     */
    public function imageCacheExpiry(): int
    {
        return $this->imageCacheExpiry;
    }

    /**
     * Every configured IIIF source must declare a non-empty `idPattern`.
     *
     * The pattern scopes which identifiers route to a source. Without one a
     * source would match every identifier, so the resolver would fire an HTTP
     * manifest request to it for ids that belong to another provider (or to
     * no provider at all). Requiring it keeps `IIIFFile::tryProvider()` from
     * making those needless calls. A single-provider setup that genuinely
     * wants to accept anything can use a catch-all regex such as `/./`.
     *
     * @param array<int, mixed> $sources
     * @throws \InvalidArgumentException when a source omits a valid idPattern.
     */
    private static function assertSourcesHaveIdPattern(array $sources): void
    {
        foreach ($sources as $index => $src) {
            $pattern = is_array($src) ? ($src['idPattern'] ?? null) : null;
            if (is_string($pattern) && $pattern !== '') {
                continue;
            }
            $id = is_array($src) ? ($src['id'] ?? null) : null;
            $label = is_string($id) && $id !== '' ? "'{$id}'" : "#{$index}";
            throw new \InvalidArgumentException(
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal config-error message, never rendered as web output
                "InstantIIIF: iiifSources entry {$label} must define a non-empty "
                . "'idPattern' (a PHP regex with delimiters, e.g. '/^df_/'). It "
                . 'scopes which identifiers route to the source and prevents '
                . 'needless IIIF manifest fetches; use \'/./\' to accept any id.'
            );
        }
    }

    /**
     * @inheritDoc
     * @param Title|string $title
     * @param string|false $time
     */
    // phpcs:ignore Syde.Functions.ArgumentTypeDeclaration.NoArgumentType -- MediaWiki FileRepo override
    public function newFile($title, $time = false): IIIFFile
    {
        if (!$title instanceof Title) {
            $title = Title::newFromText((string) $title);
        }
        if ($title === null) {
            throw new \InvalidArgumentException('Invalid title provided');
        }
        return new IIIFFile($this, $title, $time);
    }

    /**
     * Get provider configuration from the repository.
     *
     * @return array<int, array<string, mixed>>
     */
    public function iiifSources(): array
    {
        return $this->iiifSources;
    }

    /**
     * Configured IIIF source ID patterns (PHP-style with delimiters).
     *
     * @return list<string>
     */
    public function idPatterns(): array
    {
        $patterns = [];
        foreach ($this->iiifSources as $src) {
            $patterns[] = (string) $src['idPattern'];
        }
        return $patterns;
    }

    /**
     * Override to add an explicit `apiurl` so the JS-side
     * MediaSearchProvider hits a URL we can recognise (it is matched
     * against the apiurls advertised in `wgInstantIIIFRepos`).
     *
     * Without our `apiurl`, MediaResourceProvider falls back to
     * `scriptDirUrl + '/api.php'`, which still works as an API endpoint but
     * differs in formatting between repos and is harder to match on.
     *
     * @return array<string, mixed>
     */
    // phpcs:ignore Syde.Classes.DisallowGetterSetter.GetterFound -- MediaWiki FileRepo override
    public function getInfo(): array
    {
        $info = parent::getInfo();
        $info['apiurl'] = self::resolveLocalApiUrl();
        return $info;
    }

    /**
     * Absolute URL to this wiki's `api.php`, matching what MediaWiki's JS
     * MediaResourceProvider would build for a local request.
     */
    public static function resolveLocalApiUrl(): string
    {
        $services = MediaWikiServices::getInstance();
        $scriptPath = (string) $services->getMainConfig()->get(MainConfigNames::ScriptPath);
        return (string) $services->getUrlUtils()->expand(
            $scriptPath . '/api.php',
            PROTO_CURRENT
        );
    }
}
