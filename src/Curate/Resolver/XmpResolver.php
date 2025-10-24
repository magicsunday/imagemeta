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
use MagicSunday\ImageMeta\Value\Xmp;

use function array_filter;
use function array_map;
use function array_values;
use function explode;
use function is_array;
use function is_string;
use function strtolower;
use function trim;

/**
 * Provides convenient accessors for parsed XMP documents.
 */
final readonly class XmpResolver
{
    public function __construct(private ?XmpDocument $document)
    {
    }

    /**
     * Returns the wrapped value object used in the public API.
     */
    public function value(): Xmp
    {
        return new Xmp($this->document);
    }

    /**
     * Reads a string property from the document using the provided namespace and local name.
     */
    public function string(string $namespace, string $localName): ?string
    {
        $value = $this->document?->get($namespace, $localName);

        return is_string($value) ? trim($value) : null;
    }

    /**
     * Reads a bag/sequence of string values from the document.
     *
     * @return list<string>
     */
    public function stringList(string $namespace, string $localName): array
    {
        $value = $this->document?->get($namespace, $localName);

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
     * Returns true when the document contains the specified property.
     */
    public function has(string $namespace, string $localName): bool
    {
        $value = $this->document?->get($namespace, $localName);

        return $value !== null;
    }

    /**
     * Interprets the property as a boolean flag.
     */
    public function bool(string $namespace, string $localName): ?bool
    {
        $value = $this->string($namespace, $localName);

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
}
