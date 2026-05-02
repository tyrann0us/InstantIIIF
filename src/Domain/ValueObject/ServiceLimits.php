<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF\Domain\ValueObject;

/**
 * Represents IIIF Image API service limits from info.json.
 *
 * Contains original dimensions and optional maxWidth, maxHeight, maxArea constraints.
 */
final class ServiceLimits
{
    private ImageDimensions $originalDimensions;
    private ?int $maxWidth;
    private ?int $maxHeight;
    private ?int $maxArea;

    public function __construct(
        ImageDimensions $originalDimensions,
        ?int $maxWidth = null,
        ?int $maxHeight = null,
        ?int $maxArea = null
    ) {

        $this->originalDimensions = $originalDimensions;
        $this->maxWidth = $maxWidth;
        $this->maxHeight = $maxHeight;
        $this->maxArea = $maxArea;
    }

    /**
     * @param array<string, mixed> $infoJson
     */
    public static function fromInfoJson(array $infoJson): self
    {
        $width = isset($infoJson['width']) ? (int) $infoJson['width'] : 0;
        $height = isset($infoJson['height']) ? (int) $infoJson['height'] : 0;

        return new self(
            new ImageDimensions($width, $height),
            isset($infoJson['maxWidth']) ? (int) $infoJson['maxWidth'] : null,
            isset($infoJson['maxHeight']) ? (int) $infoJson['maxHeight'] : null,
            isset($infoJson['maxArea']) ? (int) $infoJson['maxArea'] : null
        );
    }

    public static function unlimited(): self
    {
        return new self(ImageDimensions::empty());
    }

    public function originalDimensions(): ImageDimensions
    {
        return $this->originalDimensions;
    }

    public function maxWidth(): int
    {
        return $this->maxWidth ?? $this->originalDimensions->width();
    }

    public function maxHeight(): int
    {
        return $this->maxHeight ?? $this->originalDimensions->height();
    }

    public function maxArea(): ?int
    {
        return $this->maxArea;
    }

    public function hasAreaLimit(): bool
    {
        return $this->maxArea !== null && $this->maxArea > 0;
    }
}
