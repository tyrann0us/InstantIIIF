<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF\Domain;

/**
 * 1-based page number into a IIIF manifest's canvas list.
 *
 * Page values arrive from wikitext parameters, request `?page=` query
 * strings, MMV's `iiurlparam=pageN-Wpx`, and prev/next-nav transforms.
 * Anything sub-1 (zero, negative, non-numeric, null) is normalised to
 * page 1 so downstream code never sees an invalid value.
 */
final class Page
{
    private function __construct(public readonly int $value)
    {
    }

    public static function normalize(mixed $value): self
    {
        return new self(max((int) $value, 1));
    }

    public function zeroIndexed(): int
    {
        return $this->value - 1;
    }
}
