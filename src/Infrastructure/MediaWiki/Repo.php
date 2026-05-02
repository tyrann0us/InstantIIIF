<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF\Infrastructure\MediaWiki;

use FileRepo;
use MediaWiki\Extension\InstantIIIF\Domain\ValueObject\ProviderConfig;
use Title;

/**
 * MediaWiki FileRepo implementation for IIIF sources.
 *
 * Configured via $wgForeignFileRepos in LocalSettings.php.
 */
class Repo extends FileRepo
{
    /** @var array<int, ProviderConfig> */
    private array $providerConfigs;

    /**
     * @param array<string, mixed> $info
     */
    public function __construct(array $info)
    {
        parent::__construct($info);

        $sources = $info['iiifSources'] ?? [];
        $this->providerConfigs = is_array($sources)
            ? ProviderConfig::fromArrayList($sources)
            : [];
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

        $container = ServiceContainer::getInstance();

        return new IIIFFile(
            $this,
            $title,
            $container->resourceResolver($this->providerConfigs),
            $container->thumbnailService(),
            $time
        );
    }

    /**
     * @return array<int, ProviderConfig>
     */
    public function providerConfigs(): array
    {
        return $this->providerConfigs;
    }

    /**
     * @deprecated Use providerConfigs() instead
     * @return array<int, array<string, mixed>>
     */
    public function iiifSources(): array
    {
        // For backward compatibility, return raw config arrays
        return $this->info['iiifSources'] ?? [];
    }
}
