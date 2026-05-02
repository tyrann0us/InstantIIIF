<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF\Application\ReadModel;

/**
 * Read model representing a thumbnail result.
 */
final class ThumbnailResult
{
    private string $url;
    private int $width;
    private int $height;

    private function __construct(string $url, int $width, int $height)
    {
        $this->url = $url;
        $this->width = $width;
        $this->height = $height;
    }

    public static function create(string $url, int $width, int $height): self
    {
        return new self($url, $width, $height);
    }

    public function url(): string
    {
        return $this->url;
    }

    public function width(): int
    {
        return $this->width;
    }

    public function height(): int
    {
        return $this->height;
    }

    /**
     * @return array{url: string, width: int, height: int}
     */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'width' => $this->width,
            'height' => $this->height,
        ];
    }
}
