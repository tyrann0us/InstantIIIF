<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF\Application\Query;

/**
 * Query to get a thumbnail for a resolved IIIF resource.
 */
final class GetThumbnailQuery
{
    private int $page;
    private int $width;
    private int $height;

    public function __construct(int $page = 1, int $width = 0, int $height = 0)
    {
        $this->page = max(1, $page);
        $this->width = max(0, $width);
        $this->height = max(0, $height);
    }

    /**
     * @param array<string, mixed> $params MediaWiki transform parameters
     */
    public static function fromTransformParams(array $params): self
    {
        $page = (int) ($params['page'] ?? 1);
        $width = (int) ($params['width'] ?? $params['w'] ?? 0);
        $height = (int) ($params['height'] ?? $params['h'] ?? 0);

        return new self($page, $width, $height);
    }

    public function page(): int
    {
        return $this->page;
    }

    public function width(): int
    {
        return $this->width;
    }

    public function height(): int
    {
        return $this->height;
    }

    public function hasWidth(): bool
    {
        return $this->width > 0;
    }

    public function hasHeight(): bool
    {
        return $this->height > 0;
    }

    public function hasDimensions(): bool
    {
        return $this->width > 0 || $this->height > 0;
    }
}
