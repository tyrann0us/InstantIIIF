<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF\Domain\ValueObject;

/**
 * Represents a 1-based page number for multi-page IIIF documents.
 *
 * Internally converts to 0-based index for array access.
 */
final class PageNumber
{
    private int $value;

    public function __construct(int $page)
    {
        $this->value = max(1, $page);
    }

    public static function first(): self
    {
        return new self(1);
    }

    public static function fromMixed(mixed $value): self
    {
        return new self((int) $value);
    }

    public function asInt(): int
    {
        return $this->value;
    }

    /**
     * Returns 0-based index for array access.
     */
    public function asIndex(): int
    {
        return $this->value - 1;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
