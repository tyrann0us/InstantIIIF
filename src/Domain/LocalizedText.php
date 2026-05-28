<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF\Domain;

/**
 * Resolves a IIIF value that may be a plain string, a v2 language object,
 * a v2 list of language objects, or a v3 language map to a single string.
 *
 * Accepted shapes:
 *  - plain string: `"Foo"`
 *  - v2 single language object: `{"@value": "Foo", "@language": "en"}`
 *  - v2 list of language objects: `[{"@language":"de","@value":"…"}, …]`
 *  - v3 language map: `{"de": ["…"], "en": ["…"]}`
 *
 * Picks the first match against the caller-supplied preferred-languages
 * list, then falls back to the first translation present.
 */
final class LocalizedText
{
    /**
     * @param list<string> $preferred Ordered preferred language codes.
     */
    public static function resolve(mixed $value, array $preferred): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (!is_array($value)) {
            return '';
        }
        // v2 single language object.
        if (isset($value['@value']) && is_string($value['@value'])) {
            return $value['@value'];
        }
        // v2 list of language objects.
        if (isset($value[0]) && is_array($value[0]) && isset($value[0]['@value'])) {
            return self::pickFromLanguageObjectList($value, $preferred);
        }
        // v3 language map.
        return self::pickFromLanguageMap($value, $preferred);
    }

    /**
     * @param array<int, mixed> $entries
     * @param list<string> $preferred
     */
    private static function pickFromLanguageObjectList(array $entries, array $preferred): string
    {
        foreach ($preferred as $lang) {
            foreach ($entries as $entry) {
                if (
                    is_array($entry)
                    && ($entry['@language'] ?? null) === $lang
                    && is_string($entry['@value'] ?? null)
                ) {
                    return $entry['@value'];
                }
            }
        }
        foreach ($entries as $entry) {
            if (is_array($entry) && is_string($entry['@value'] ?? null)) {
                return $entry['@value'];
            }
        }
        return '';
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string> $preferred
     */
    private static function pickFromLanguageMap(array $value, array $preferred): string
    {
        foreach ($preferred as $lang) {
            $candidate = $value[$lang] ?? null;
            if (is_array($candidate) && isset($candidate[0]) && is_string($candidate[0])) {
                return $candidate[0];
            }
        }
        foreach ($value as $langValues) {
            if (is_array($langValues) && isset($langValues[0]) && is_string($langValues[0])) {
                return $langValues[0];
            }
        }
        return '';
    }
}
