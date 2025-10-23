<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model\Xmp;

/**
 * Immutable representation of an extracted XMP document keyed by Clark notation.
 */
final readonly class XmpDocument
{
    /**
     * @param array<string, string|array<int, string>> $data Map of Clark notation => value
     */
    public function __construct(public readonly array $data)
    {
    }

    /**
     * Looks up a property by namespace URI and local name.
     *
     * @return array<int, string>|string|null
     */
    public function get(string $namespaceUri, string $localName): array|string|null
    {
        $key   = $this->buildClarkName($namespaceUri, $localName);
        $value = $this->data[$key] ?? null;

        return is_string($value) || is_array($value) ? $value : null;
    }

    /**
     * Finds the first property with the given local name independent of the namespace.
     *
     * @return array<int, string>|string|null
     */
    public function find(string $localName): array|string|null
    {
        return array_find(
            $this->data,
            fn (mixed $value, int|string $key): bool => $this->matchesLocalName((string) $key, $localName)
                && (is_string($value) || is_array($value))
        );
    }

    /**
     * Builds a Clark notation key for the given namespace/local name pair.
     *
     * @param string $namespaceUri Namespace URI that qualifies the property.
     * @param string $localName    Local property name as declared in the document.
     *
     * @return string Fully-qualified Clark notation value.
     */
    private function buildClarkName(string $namespaceUri, string $localName): string
    {
        return $namespaceUri !== ''
            ? sprintf('{%s}%s', $namespaceUri, $localName)
            : $localName;
    }

    /**
     * Checks whether the provided Clark notation belongs to the given local name.
     *
     * @param string $clark     Property key expressed in Clark notation.
     * @param string $localName Local property name to compare against.
     *
     * @return bool True when the local name part of the key matches the provided name.
     */
    private function matchesLocalName(string $clark, string $localName): bool
    {
        if ($clark === $localName) {
            return true;
        }

        if ($clark !== '' && $clark[0] === '{') {
            $pos = strpos($clark, '}');
            if ($pos !== false) {
                return substr($clark, $pos + 1) === $localName;
            }
        }

        return false;
    }
}
