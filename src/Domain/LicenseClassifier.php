<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF\Domain;

/**
 * Derives a human-readable short name from a license URL.
 *
 * MMV only renders its License object when LicenseShortName is set;
 * without it the license link falls back to filePageUrl with a
 * meaningless `?uselang=…#…` fragment. Empty string means the URL
 * isn't a recognised license — callers fall back to a generic
 * "(Lizenz)" message.
 */
final class LicenseClassifier
{
    public static function shortNameFor(string $url): string
    {
        if (preg_match('#creativecommons\.org/licenses/([a-z-]+)/(\d+\.\d+)#i', $url, $match)) {
            return 'CC ' . strtoupper($match[1]) . ' ' . $match[2];
        }
        if (str_contains($url, 'creativecommons.org/publicdomain/zero')) {
            return 'CC0';
        }
        if (str_contains($url, 'creativecommons.org/publicdomain/mark')) {
            return 'Public Domain';
        }
        if (preg_match('#rightsstatements\.org/vocab/([^/]+)/#i', $url, $match)) {
            return str_replace('-', ' ', $match[1]);
        }
        return '';
    }
}
