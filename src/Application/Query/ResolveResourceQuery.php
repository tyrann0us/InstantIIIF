<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF\Application\Query;

/**
 * Query to resolve a IIIF resource by its object ID.
 */
final class ResolveResourceQuery
{
    private string $objectId;

    public function __construct(string $objectId)
    {
        $this->objectId = $objectId;
    }

    public function objectId(): string
    {
        return $this->objectId;
    }
}
