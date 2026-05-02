<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF\Domain\ValueObject;

/**
 * Represents a IIIF provider configuration.
 *
 * Configured via $wgForeignFileRepos[]['iiifSources'] in LocalSettings.php.
 */
final class ProviderConfig
{
    private string $id;
    private ?string $idPattern;
    private string $manifestPattern;
    private ?string $landingUrlPattern;

    public function __construct(
        string $id,
        ?string $idPattern,
        string $manifestPattern,
        ?string $landingUrlPattern = null
    ) {

        if ($id === '') {
            throw new \InvalidArgumentException('Provider ID cannot be empty');
        }
        if ($manifestPattern === '') {
            throw new \InvalidArgumentException('Manifest pattern cannot be empty');
        }

        $this->id = $id;
        $this->idPattern = $idPattern;
        $this->manifestPattern = $manifestPattern;
        $this->landingUrlPattern = $landingUrlPattern;
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        $id = (string) ($config['id'] ?? 'default');
        $idPattern = isset($config['idPattern']) ? (string) $config['idPattern'] : null;
        $manifestPattern = (string) ($config['manifestPattern'] ?? '');
        $landingUrlPattern = isset($config['landingUrlPattern']) ? (string) $config['landingUrlPattern'] : null;

        return new self($id, $idPattern, $manifestPattern, $landingUrlPattern);
    }

    /**
     * @param array<int, array<string, mixed>> $configs
     * @return array<int, self>
     */
    public static function fromArrayList(array $configs): array
    {
        return array_map(
            static fn (array $config): self => self::fromArray($config),
            $configs
        );
    }

    public function id(): string
    {
        return $this->id;
    }

    public function matchesObjectId(ObjectId $objectId): bool
    {
        if ($this->idPattern === null) {
            return true;
        }

        return $objectId->matchesPattern($this->idPattern);
    }

    public function buildManifestUrl(ObjectId $objectId): string
    {
        return str_replace('$1', $objectId->asString(), $this->manifestPattern);
    }

    public function buildLandingUrl(ObjectId $objectId): ?string
    {
        if ($this->landingUrlPattern === null) {
            return null;
        }

        return str_replace('$1', $objectId->asString(), $this->landingUrlPattern);
    }

    public function hasLandingUrlPattern(): bool
    {
        return $this->landingUrlPattern !== null;
    }
}
