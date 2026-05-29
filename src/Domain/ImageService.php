<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF\Domain;

/**
 * One IIIF Image API service: base URL plus the limits advertised in
 * its `info.json`. Builds Image API v2 URLs and clamps requested sizes
 * to the limits so we never ask a remote service for more pixels than
 * it will serve.
 */
final class ImageService
{
    private function __construct(
        public readonly string $baseUrl,
        public readonly Dimensions $originalDims,
        private readonly int $maxWidth,
        private readonly int $maxHeight,
        private readonly int $maxArea
    ) {
    }

    /**
     * @param array<string, mixed> $infoJson
     */
    public static function fromInfoJson(string $baseUrl, array $infoJson): self
    {
        $origW = (int) ($infoJson['width'] ?? 0);
        $origH = (int) ($infoJson['height'] ?? 0);
        return new self(
            rtrim($baseUrl, '/'),
            Dimensions::of($origW, $origH),
            (int) ($infoJson['maxWidth'] ?? $origW),
            (int) ($infoJson['maxHeight'] ?? $origH),
            (int) ($infoJson['maxArea'] ?? 0),
        );
    }

    /**
     * Full-resolution Image API URL without needing an info.json fetch.
     * "/full/full" is unconstrained and always valid.
     */
    public static function fullUrlFor(string $baseUrl): string
    {
        return rtrim($baseUrl, '/') . '/full/full/0/default.jpg';
    }

    public function fullUrl(): string
    {
        return $this->baseUrl . '/full/full/0/default.jpg';
    }

    /**
     * Image API URL sized to (width, height). Either dimension may be 0
     * to mean "no explicit constraint on that axis". Both 0 yields the
     * full-resolution URL.
     */
    public function sizedUrl(int $width, int $height): string
    {
        if (!$width && !$height) {
            return $this->fullUrl();
        }
        [$clampedW, $clampedH] = $this->clamp($width, $height);
        return $this->baseUrl . '/full/' . self::sizeParam($clampedW, $clampedH) . '/0/default.jpg';
    }

    /**
     * Clamp a requested (w, h) pair against this service's maxWidth /
     * maxHeight / maxArea limits. Either dimension may be 0 ("no constraint").
     *
     * @return array{0: int, 1: int}
     */
    public function clamp(int $width, int $height): array
    {
        if (!$width && !$height) {
            return [$width, $height];
        }

        [$width, $height] = $this->applyDimensionLimits($width, $height);
        return $this->applyAreaLimit($width, $height);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function applyDimensionLimits(int $width, int $height): array
    {
        if ($width && $height) {
            return $this->scaleBothToFit($width, $height);
        }
        if ($width && $this->maxWidth && $width > $this->maxWidth) {
            $width = $this->maxWidth;
        }
        if ($height && $this->maxHeight && $height > $this->maxHeight) {
            $height = $this->maxHeight;
        }
        return [$width, $height];
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function scaleBothToFit(int $width, int $height): array
    {
        $scale = 1.0;
        if ($this->maxWidth && $width > $this->maxWidth) {
            $scale = min($scale, $this->maxWidth / $width);
        }
        if ($this->maxHeight && $height > $this->maxHeight) {
            $scale = min($scale, $this->maxHeight / $height);
        }
        if ($scale < 1.0) {
            $width = max(1, (int) floor($width * $scale));
            $height = max(1, (int) floor($height * $scale));
        }
        return [$width, $height];
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function applyAreaLimit(int $width, int $height): array
    {
        $origW = $this->originalDims->width;
        $origH = $this->originalDims->height;
        if (!$this->maxArea || !$origW || !$origH) {
            return [$width, $height];
        }

        if ($width && $height) {
            return $this->scaleForArea($width, $height);
        }

        if ($width) {
            $estimatedHeight = (int) round($width * $origH / $origW);
            if ($width * $estimatedHeight > $this->maxArea) {
                $width = max(1, (int) floor(sqrt($this->maxArea * $origW / $origH)));
            }
        } elseif ($height) {
            $estimatedWidth = (int) round($height * $origW / $origH);
            if ($height * $estimatedWidth > $this->maxArea) {
                $height = max(1, (int) floor(sqrt($this->maxArea * $origH / $origW)));
            }
        }
        return [$width, $height];
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function scaleForArea(int $width, int $height): array
    {
        $area = $width * $height;
        if ($area > $this->maxArea) {
            $scale = sqrt($this->maxArea / $area);
            $width = max(1, (int) floor($width * $scale));
            $height = max(1, (int) floor($height * $scale));
        }
        return [$width, $height];
    }

    /**
     * Only called from sizedUrl(), which short-circuits to fullUrl()
     * when both axes are zero — so we never reach this with (0, 0).
     */
    private static function sizeParam(int $width, int $height): string
    {
        if ($width && $height) {
            return $width . ',' . $height;
        }
        if ($width) {
            return $width . ',';
        }
        return ',' . $height;
    }
}
