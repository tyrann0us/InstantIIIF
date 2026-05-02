<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF\Domain\ValueObject;

/**
 * Represents a IIIF Image API service identifier (base URL).
 *
 * Example: "https://iiif.slub-dresden.de/iiif/2/12345"
 */
final class ServiceId
{
    private string $baseUrl;

    public function __construct(string $baseUrl)
    {
        $normalized = rtrim(trim($baseUrl), '/');

        if ($normalized === '') {
            throw new \InvalidArgumentException('Service ID cannot be empty');
        }

        if (!preg_match('~^https?://~i', $normalized)) {
            throw new \InvalidArgumentException(
                sprintf('Service ID must be a valid HTTP(S) URL, got: %s', $baseUrl)
            );
        }

        $this->baseUrl = $normalized;
    }

    public static function tryFrom(?string $baseUrl): ?self
    {
        if ($baseUrl === null || trim($baseUrl) === '') {
            return null;
        }

        try {
            return new self($baseUrl);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    public function asString(): string
    {
        return $this->baseUrl;
    }

    public function infoJsonUrl(): string
    {
        return $this->baseUrl . '/info.json';
    }

    public function equals(self $other): bool
    {
        return $this->baseUrl === $other->baseUrl;
    }
}
