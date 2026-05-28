<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF\Domain;

/**
 * Width × height pair.
 *
 * Zero means "unknown" — manifests may omit canvas dimensions and the
 * fallback info.json lookup may not have run yet. Use isKnown() before
 * relying on the values.
 */
final class Dimensions
{
    private function __construct(
        public readonly int $width,
        public readonly int $height
    ) {
    }

    public static function of(int $width, int $height): self
    {
        return new self(max(0, $width), max(0, $height));
    }

    public static function unknown(): self
    {
        return new self(0, 0);
    }

    public function isKnown(): bool
    {
        return $this->width > 0 && $this->height > 0;
    }
}
