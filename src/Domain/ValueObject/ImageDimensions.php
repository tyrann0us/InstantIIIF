<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF\Domain\ValueObject;

/**
 * Represents image dimensions (width and height).
 *
 * Immutable: scaling operations return new instances.
 */
final class ImageDimensions
{
    private int $width;
    private int $height;

    public function __construct(int $width, int $height)
    {
        $this->width = max(0, $width);
        $this->height = max(0, $height);
    }

    public static function empty(): self
    {
        return new self(0, 0);
    }

    public static function fromArray(mixed $width, mixed $height): self
    {
        return new self((int) $width, (int) $height);
    }

    public function width(): int
    {
        return $this->width;
    }

    public function height(): int
    {
        return $this->height;
    }

    public function isEmpty(): bool
    {
        return $this->width === 0 || $this->height === 0;
    }

    public function hasWidth(): bool
    {
        return $this->width > 0;
    }

    public function hasHeight(): bool
    {
        return $this->height > 0;
    }

    public function hasBoth(): bool
    {
        return $this->width > 0 && $this->height > 0;
    }

    public function area(): int
    {
        return $this->width * $this->height;
    }

    public function aspectRatio(): float
    {
        if ($this->height === 0) {
            return 0.0;
        }
        return $this->width / $this->height;
    }

    public function scaledToWidth(int $targetWidth): self
    {
        if ($this->width === 0 || $targetWidth <= 0) {
            return new self($targetWidth, 0);
        }
        $ratio = $targetWidth / $this->width;
        return new self($targetWidth, (int) round($this->height * $ratio));
    }

    public function scaledToHeight(int $targetHeight): self
    {
        if ($this->height === 0 || $targetHeight <= 0) {
            return new self(0, $targetHeight);
        }
        $ratio = $targetHeight / $this->height;
        return new self((int) round($this->width * $ratio), $targetHeight);
    }

    /**
     * Scale to fit within given bounds while preserving aspect ratio.
     */
    public function scaledToFit(int $maxWidth, int $maxHeight): self
    {
        if ($this->isEmpty()) {
            return $this;
        }

        $scaleX = $maxWidth > 0 ? $maxWidth / $this->width : PHP_FLOAT_MAX;
        $scaleY = $maxHeight > 0 ? $maxHeight / $this->height : PHP_FLOAT_MAX;
        $scale = min($scaleX, $scaleY);

        if ($scale >= 1.0) {
            return $this;
        }

        return new self(
            max(1, (int) floor($this->width * $scale)),
            max(1, (int) floor($this->height * $scale))
        );
    }

    /**
     * Scale to fit within a maximum area while preserving aspect ratio.
     */
    public function scaledToArea(int $maxArea): self
    {
        if ($this->isEmpty() || $maxArea <= 0) {
            return $this;
        }

        $currentArea = $this->area();
        if ($currentArea <= $maxArea) {
            return $this;
        }

        $scale = sqrt($maxArea / $currentArea);
        return new self(
            max(1, (int) floor($this->width * $scale)),
            max(1, (int) floor($this->height * $scale))
        );
    }

    public function withWidth(int $width): self
    {
        return new self($width, $this->height);
    }

    public function withHeight(int $height): self
    {
        return new self($this->width, $height);
    }

    public function equals(self $other): bool
    {
        return $this->width === $other->width && $this->height === $other->height;
    }
}
