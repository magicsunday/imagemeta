<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model\Xmp;

final class XmpDocument
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
        foreach ($this->data as $key => $value) {
            if ($this->matchesLocalName($key, $localName) && (is_string($value) || is_array($value))) {
                return $value;
            }
        }

        return null;
    }

    private function buildClarkName(string $namespaceUri, string $localName): string
    {
        return $namespaceUri !== ''
            ? sprintf('{%s}%s', $namespaceUri, $localName)
            : $localName;
    }

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
