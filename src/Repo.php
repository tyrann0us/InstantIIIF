<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF;

use FileRepo;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

class Repo extends FileRepo
{
    /** @var array<int, array<string, mixed>> */
    private array $iiifSources = [];

    /**
     * @param array<string, mixed> $info
     */
    public function __construct(array $info)
    {
        // FileBackendGroup requires 'directory' for auto-backend creation;
        // IIIF has no local storage, so we default to the upload dir.
        // Core constructs the repo from the $info array, so there is no
        // constructor DI here — read the upload dir from the Config service.
        if (!isset($info['directory'])) {
            $info['directory'] = (string) MediaWikiServices::getInstance()
                ->getMainConfig()
                ->get(MainConfigNames::UploadDirectory);
        }
        parent::__construct($info);
        $this->iiifSources = $info['iiifSources'] ?? [];
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
            if (!is_array($src)) {
                continue;
            }
            $pattern = $src['idPattern'] ?? null;
            if (is_string($pattern) && $pattern !== '') {
                $patterns[] = $pattern;
            }
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
