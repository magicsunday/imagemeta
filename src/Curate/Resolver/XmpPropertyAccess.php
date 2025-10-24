<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Curate\Resolver;

use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;

use function explode;
use function is_array;
use function is_numeric;
use function is_string;
use function preg_match;
use function str_contains;
use function trim;

/**
 * Helper methods for resolvers that need to read values from XMP documents.
 */
trait XmpPropertyAccess
{
    /**
     * Reads a string value from the given XMP document.
     */
    protected function xmpString(?XmpDocument $document, string $namespaceUri, string $localName): ?string
    {
        $value = $this->xmpValue($document, $namespaceUri, $localName);

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
     * Reads an integer value from the given XMP document.
     */
    protected function xmpInt(?XmpDocument $document, string $namespaceUri, string $localName): ?int
    {
        $string = $this->xmpString($document, $namespaceUri, $localName);

        if ($string === null) {
            return null;
        }

        $numeric = $this->parseNumericString($string);

        return $numeric !== null ? (int) $numeric : null;
    }

    /**
     * Reads a floating point value from the given XMP document.
     */
    protected function xmpFloat(?XmpDocument $document, string $namespaceUri, string $localName): ?float
    {
        $string = $this->xmpString($document, $namespaceUri, $localName);

        if ($string === null) {
            return null;
        }

        return $this->parseNumericString($string);
    }

    /**
     * Returns the raw value stored in the XMP document.
     *
     * @return array<int|string, mixed>|string|null
     */
    protected function xmpValue(?XmpDocument $document, string $namespaceUri, string $localName): array|string|null
    {
        return $document instanceof XmpDocument ? $document->get($namespaceUri, $localName) : null;
    }

    /**
     * Attempts to convert a textual representation into a floating point value.
     */
    protected function parseNumericString(string $value): ?float
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
