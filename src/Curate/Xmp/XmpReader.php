<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Curate\Xmp;

use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;

use function array_filter;
use function array_map;
use function array_values;
use function explode;
use function is_array;
use function is_numeric;
use function is_string;
use function preg_match;
use function str_contains;
use function strtolower;
use function trim;

/**
 * Provides shared helpers for reading values from optional XMP documents.
 */
final class XmpReader
{
    /**
     * Returns the raw value stored in the XMP document.
     *
     * @return array<int|string, mixed>|string|null
     */
    public static function value(?XmpDocument $document, string $namespaceUri, string $localName)
    {
        return $document instanceof XmpDocument ? $document->get($namespaceUri, $localName) : null;
    }

    /**
     * Reads a string value from the document.
     */
    public static function string(?XmpDocument $document, string $namespaceUri, string $localName): ?string
    {
        $value = self::value($document, $namespaceUri, $localName);

        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed !== '' ? $trimmed : null;
        }

        if (!is_array($value)) {
            return null;
        }

        foreach ($value as $element) {
            if (!is_string($element)) {
                continue;
            }

            $trimmed = trim($element);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return null;
    }

    /**
     * Reads an integer value from the document.
     */
    public static function int(?XmpDocument $document, string $namespaceUri, string $localName): ?int
    {
        $string = self::string($document, $namespaceUri, $localName);

        if ($string === null) {
            return null;
        }

        $numeric = self::parseNumericString($string);

        return $numeric !== null ? (int) $numeric : null;
    }

    /**
     * Reads a floating point value from the document.
     */
    public static function float(?XmpDocument $document, string $namespaceUri, string $localName): ?float
    {
        $string = self::string($document, $namespaceUri, $localName);

        if ($string === null) {
            return null;
        }

        return self::parseNumericString($string);
    }

    /**
     * Reads a bag or sequence of string values from the document.
     *
     * @return list<string>
     */
    public static function stringList(?XmpDocument $document, string $namespaceUri, string $localName): array
    {
        $value = self::value($document, $namespaceUri, $localName);

        if (is_string($value)) {
            $parts = array_map(trim(...), explode(',', $value));

            return array_values(array_filter($parts, static fn (string $item): bool => $item !== ''));
        }

        if (!is_array($value)) {
            return [];
        }

        $items = array_map(trim(...), array_values($value));

        return array_values(array_filter($items, static fn (string $item): bool => $item !== ''));
    }

    /**
     * Reads a boolean flag from the document when present.
     */
    public static function bool(?XmpDocument $document, string $namespaceUri, string $localName): ?bool
    {
        $value = self::string($document, $namespaceUri, $localName);

        if ($value === null) {
            return null;
        }

        $normalized = strtolower($value);

        return match ($normalized) {
            'true', '1' => true,
            'false', '0' => false,
            default => null,
        };
    }

    /**
     * Checks whether the document exposes the specified property.
     */
    public static function has(?XmpDocument $document, string $namespaceUri, string $localName): bool
    {
        return self::value($document, $namespaceUri, $localName) !== null;
    }

    /**
     * Attempts to convert a textual representation into a floating point value.
     */
    public static function parseNumericString(string $value): ?float
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        if (is_numeric($trimmed)) {
            return (float) $trimmed;
        }

        if (str_contains($trimmed, '/')) {
            [$numerator, $denominator] = explode('/', $trimmed, 2);
            $numerator                 = trim($numerator);
            $denominator               = trim($denominator);

            if ($denominator === '0' || $denominator === '-0') {
                return null;
            }

            if (is_numeric($numerator) && is_numeric($denominator)) {
                return (float) $numerator / (float) $denominator;
            }
        }

        if (preg_match('/(-?\d+(?:\.\d+)?)/', $trimmed, $matches) === 1) {
            return (float) $matches[1];
        }

        return null;
    }
}
