<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF\Domain\Service;

use MediaWiki\Extension\InstantIIIF\Domain\ValueObject\ImageDimensions;
use MediaWiki\Extension\InstantIIIF\Domain\ValueObject\ServiceLimits;

/**
 * Calculates image dimensions respecting IIIF service limits.
 *
 * Handles maxWidth, maxHeight, and maxArea constraints from info.json.
 */
final class ServiceLimitCalculator
{
    /**
     * Clamp requested dimensions to service limits.
     */
    public function clampToLimits(ImageDimensions $requested, ServiceLimits $limits): ImageDimensions
    {
        if ($requested->isEmpty()) {
            return $requested;
        }

        $clamped = $this->applyDimensionLimits($requested, $limits);

        if ($limits->hasAreaLimit()) {
            $clamped = $this->applyAreaLimit($clamped, $limits);
        }

        return $clamped;
    }

    /**
     * Calculate final dimensions for a width-only request.
     */
    public function calculateForWidth(int $requestedWidth, ServiceLimits $limits): ImageDimensions
    {
        $maxWidth = $limits->maxWidth();
        $clampedWidth = $maxWidth > 0 ? min($requestedWidth, $maxWidth) : $requestedWidth;

        $original = $limits->originalDimensions();
        if ($original->isEmpty()) {
            return new ImageDimensions($clampedWidth, 0);
        }

        $result = $original->scaledToWidth($clampedWidth);

        if ($limits->hasAreaLimit()) {
            $result = $this->applyAreaLimitForSingleDimension($result, $limits, true);
        }

        return $result;
    }

    /**
     * Calculate final dimensions for a height-only request.
     */
    public function calculateForHeight(int $requestedHeight, ServiceLimits $limits): ImageDimensions
    {
        $maxHeight = $limits->maxHeight();
        $clampedHeight = $maxHeight > 0 ? min($requestedHeight, $maxHeight) : $requestedHeight;

        $original = $limits->originalDimensions();
        if ($original->isEmpty()) {
            return new ImageDimensions(0, $clampedHeight);
        }

        $result = $original->scaledToHeight($clampedHeight);

        if ($limits->hasAreaLimit()) {
            $result = $this->applyAreaLimitForSingleDimension($result, $limits, false);
        }

        return $result;
    }

    private function applyDimensionLimits(ImageDimensions $requested, ServiceLimits $limits): ImageDimensions
    {
        $maxWidth = $limits->maxWidth();
        $maxHeight = $limits->maxHeight();

        // Both dimensions specified: scale to fit
        if ($requested->hasBoth()) {
            return $requested->scaledToFit($maxWidth, $maxHeight);
        }

        // Width only
        if ($requested->hasWidth()) {
            $clampedWidth = $maxWidth > 0 ? min($requested->width(), $maxWidth) : $requested->width();
            return $requested->withWidth($clampedWidth);
        }

        // Height only
        if ($requested->hasHeight()) {
            $clampedHeight = $maxHeight > 0 ? min($requested->height(), $maxHeight) : $requested->height();
            return $requested->withHeight($clampedHeight);
        }

        return $requested;
    }

    private function applyAreaLimit(ImageDimensions $dimensions, ServiceLimits $limits): ImageDimensions
    {
        $maxArea = $limits->maxArea();
        if ($maxArea === null || $maxArea <= 0) {
            return $dimensions;
        }

        return $dimensions->scaledToArea($maxArea);
    }

    private function applyAreaLimitForSingleDimension(
        ImageDimensions $dimensions,
        ServiceLimits $limits,
        bool $widthConstrained
    ): ImageDimensions {

        $maxArea = $limits->maxArea();
        if ($maxArea === null || $maxArea <= 0) {
            return $dimensions;
        }

        $original = $limits->originalDimensions();
        if ($original->isEmpty()) {
            return $dimensions;
        }

        if ($dimensions->area() <= $maxArea) {
            return $dimensions;
        }

        // Calculate the maximum dimension that fits within maxArea
        $aspectRatio = $original->aspectRatio();
        if ($aspectRatio <= 0) {
            return $dimensions;
        }

        if ($widthConstrained) {
            // width * (width / aspectRatio) <= maxArea
            // width^2 <= maxArea * aspectRatio
            $maxWidth = (int) floor(sqrt($maxArea * $aspectRatio));
            return $original->scaledToWidth($maxWidth);
        }

        // height * (height * aspectRatio) <= maxArea
        // height^2 <= maxArea / aspectRatio
        $maxHeight = (int) floor(sqrt($maxArea / $aspectRatio));
        return $original->scaledToHeight($maxHeight);
    }
}
