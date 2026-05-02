<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF\Domain\ValueObject;

/**
 * Represents a IIIF object identifier (e.g., "df_bs_0007727_postkarte").
 *
 * Handles normalization including:
 * - Removing spoofed image extensions (MMV workaround)
 * - Case normalization (lcfirst)
 */
final class ObjectId
{
    private const IMAGE_EXTENSION_PATTERN = '/\.(jpg|jpeg|png|gif|bmp|webp)$/i';

    private string $value;

    public function __construct(string $value)
    {
        $cleaned = preg_replace(self::IMAGE_EXTENSION_PATTERN, '', $value) ?? $value;
        $cleaned = lcfirst(trim($cleaned));

        if ($cleaned === '') {
            throw new \InvalidArgumentException('Object ID cannot be empty');
        }

        $this->value = $cleaned;
    }

    public static function fromDbKey(string $dbKey): self
    {
        return new self($dbKey);
    }

    public function asString(): string
    {
        return $this->value;
    }

    public function matchesPattern(string $regex): bool
    {
        // Wrap pattern if not already wrapped
        if (!str_starts_with($regex, '/') && !str_starts_with($regex, '#')) {
            $regex = '/' . $regex . '/';
        }

        return (bool) preg_match($regex, $this->value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
