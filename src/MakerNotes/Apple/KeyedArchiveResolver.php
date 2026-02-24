<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\MakerNotes\Apple;

use MagicSunday\ImageMeta\Core\ParseError;

use function array_all;
use function array_any;
use function array_find;
use function array_is_list;
use function array_key_exists;
use function array_keys;
use function is_array;
use function is_string;
use function strpos;
use function substr;

/**
 * Decodes binary property lists, resolves NSKeyedArchive structures,
 * and converts between plist value objects and native PHP types.
 *
 * @phpstan-type NativePlistScalar bool|float|int|string|null
 * @phpstan-type NativePlistValue NativePlistScalar|array<int|string, NativePlistScalar|array<int|string, NativePlistScalar|array<int|string, NativePlistScalar|array<int|string, NativePlistScalar|array<int|string, NativePlistScalar|array<int|string, NativePlistScalar|array<int|string, NativePlistScalar>>>>>>>
 * @phpstan-type NativePlistDictionary array<string, NativePlistValue>
 */
final readonly class KeyedArchiveResolver
{
    /**
     * Attempts to decode the supplied payload as binary property list.
     *
     * @param string $raw Raw maker note data stream.
     *
     * @return NativePlistValue
     *
     * @phpstan-return NativePlistValue
     */
    public function decodeBinaryPropertyList(string $raw): array|string|int|float|bool|null
    {
        $signatureOffset = strpos($raw, 'bplist00');
        if ($signatureOffset === false) {
            return null;
        }

        $payload = substr($raw, $signatureOffset);

        try {
            $value = (new BinaryPlistDecoder())->decode($payload);
        } catch (ParseError) {
            // Apple binary plist formats vary across iOS versions; decode failures yield null.
            return null;
        }

        return $this->plistValueToPhp($value);
    }

    /**
     * Resolves and unarchives a keyed archive dictionary.
     *
     * @param array<int|string, NativePlistValue> $dictionary Raw dictionary from binary plist.
     *
     * @return NativePlistDictionary|null Unarchived dictionary or null if not a keyed archive.
     *
     * @phpstan-return NativePlistDictionary|null
     */
    public function resolveKeyedArchiveDictionary(array $dictionary): ?array
    {
        $unarchived = $this->unarchiveKeyedArchive($dictionary);
        if ($unarchived !== null) {
            return $unarchived;
        }

        foreach ($dictionary as $value) {
            if (!is_array($value)) {
                continue;
            }

            $candidate = $this->resolveNestedKeyedArchive($value);
            if ($candidate !== null) {
                return $candidate;
            }
        }

        if (self::isStringKeyedDictionary($dictionary)) {
            /** @var NativePlistDictionary $dictionary */
            return $dictionary;
        }

        return null;
    }

    /**
     * Ensures the array uses string keys.
     *
     * @param array<int|string, NativePlistValue> $value Array to inspect.
     *
     * @phpstan-assert array<string, NativePlistValue> $value
     */
    public static function isStringKeyedDictionary(array $value): bool
    {
        return array_all(array_keys($value), static fn ($key): bool => is_string($key));
    }

    /**
     * Converts a property list value into native PHP types.
     *
     * @return NativePlistValue
     *
     * @phpstan-return NativePlistValue
     */
    private function plistValueToPhp(ApplePlistValueInterface $value): array|string|int|float|bool|null
    {
        if ($value instanceof ApplePlistScalar) {
            return $value->value();
        }

        if ($value instanceof ApplePlistArray) {
            $result = [];
            foreach ($value->values() as $entry) {
                $result[] = $this->plistValueToPhp($entry);
            }

            /** @phpstan-ignore-next-line */
            return $result;
        }

        if ($value instanceof ApplePlistDictionary) {
            $entries = [];
            foreach ($value->entries() as $key => $entry) {
                $entries[$key] = $this->plistValueToPhp($entry);
            }

            /** @phpstan-ignore-next-line */
            return $entries;
        }

        throw new ParseError('Unsupported property list value.', 1118);
    }

    /**
     * @param NativePlistValue $value
     *
     * @phpstan-param NativePlistValue $value
     */
    private function nativeToPlistValue(array|bool|float|int|string|null $value): ApplePlistValueInterface
    {
        if (!is_array($value)) {
            return new ApplePlistScalar($value);
        }

        if (array_is_list($value)) {
            $entries = [];
            foreach ($value as $entry) {
                $entries[] = $this->nativeToPlistValue($entry);
            }

            return new ApplePlistArray($entries);
        }

        $entries = [];
        foreach ($value as $key => $entry) {
            if (!is_string($key)) {
                throw new ParseError('Property list dictionaries must use string keys.', 1119);
            }

            $entries[$key] = $this->nativeToPlistValue($entry);
        }

        return new ApplePlistDictionary($entries);
    }

    /**
     * Recursively searches for and resolves nested keyed archive structures.
     *
     * @param array<int|string, NativePlistValue> $value Value that may contain nested keyed archives.
     *
     * @return NativePlistDictionary|null Resolved archive or null if not found.
     *
     * @phpstan-return NativePlistDictionary|null
     */
    private function resolveNestedKeyedArchive(array $value): ?array
    {
        $unarchived = $this->unarchiveKeyedArchive($value);
        if ($unarchived !== null) {
            return $unarchived;
        }

        if (array_is_list($value)) {
            foreach ($value as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $candidate = $this->resolveNestedKeyedArchive($entry);
                if ($candidate !== null) {
                    return $candidate;
                }
            }

            return null;
        }

        foreach ($value as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $candidate = $this->resolveNestedKeyedArchive($entry);
            if ($candidate !== null) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Unarchives an NSKeyedArchive dictionary to plain dictionary.
     *
     * @param array<int|string, NativePlistValue> $dictionary Keyed archive structure.
     *
     * @return NativePlistDictionary|null Unarchived dictionary or null if invalid.
     *
     * @phpstan-return NativePlistDictionary|null
     */
    private function unarchiveKeyedArchive(array $dictionary): ?array
    {
        if ($this->isKeyedArchive($dictionary)) {
            return $this->unarchiveNormalizedKeyedArchive($dictionary);
        }

        $normalized = $this->normalizeKeyedArchive($dictionary);
        if ($normalized === null) {
            return null;
        }

        return $this->unarchiveNormalizedKeyedArchive($normalized);
    }

    /**
     * Unarchives a normalized keyed archive dictionary.
     *
     * @param array<int|string, NativePlistValue> $dictionary Normalized keyed archive structure.
     *
     * @return NativePlistDictionary|null Unarchived dictionary or null if invalid.
     *
     * @phpstan-return NativePlistDictionary|null
     */
    private function unarchiveNormalizedKeyedArchive(array $dictionary): ?array
    {
        try {
            /** @phpstan-ignore-next-line */
            $plist = $this->nativeToPlistValue($dictionary);
            if (!$plist instanceof ApplePlistDictionary) {
                return null;
            }

            $resolved = (new KeyedArchiveUnarchiver())->unarchive($plist);
            $native   = $this->plistValueToPhp($resolved);

            if (!is_array($native) || array_is_list($native)) {
                return null;
            }

            /** @phpstan-ignore-next-line */
            return self::isStringKeyedDictionary($native) ? $native : null;
        } catch (ParseError) {
            // Keyed archive blobs may use unsupported archive versions; decode failures yield null.
            return null;
        }
    }

    /**
     * Checks if a dictionary represents an NSKeyedArchive structure.
     *
     * @param array<int|string, NativePlistValue> $dictionary Dictionary to check.
     *
     * @return bool True if dictionary is a keyed archive.
     */
    private function isKeyedArchive(array $dictionary): bool
    {
        if (!array_key_exists('$archiver', $dictionary)) {
            return false;
        }

        if (!array_key_exists('$top', $dictionary) || !is_array($dictionary['$top'])) {
            return false;
        }

        if (!array_key_exists('$objects', $dictionary) || !is_array($dictionary['$objects'])) {
            return false;
        }

        $top = $dictionary['$top'];

        return $this->containsUidReference($top);
    }

    /**
     * Recursively checks if a value contains UID references.
     *
     * @param array<int|string, NativePlistValue> $value Value to inspect.
     *
     * @return bool True if value or nested values contain CF$UID keys.
     */
    private function containsUidReference(array $value): bool
    {
        if (array_key_exists('CF$UID', $value)) {
            return true;
        }

        return array_any($value, fn ($entry): bool => is_array($entry) && $this->containsUidReference($entry));
    }

    /**
     * Normalizes a keyed archive dictionary to standard structure.
     *
     * @param array<int|string, NativePlistValue> $dictionary Raw keyed archive dictionary.
     *
     * @return NativePlistDictionary|null Normalized structure or null if invalid.
     *
     * @phpstan-return NativePlistDictionary|null
     */
    private function normalizeKeyedArchive(array $dictionary): ?array
    {
        $objectsKey = $this->firstExistingKey($dictionary, '$objects', 'objects');
        if ($objectsKey === null) {
            return null;
        }

        $topKey = $this->firstExistingKey($dictionary, '$top', 'top');
        if ($topKey === null) {
            return null;
        }

        $objects = $dictionary[$objectsKey];
        $top     = $dictionary[$topKey];

        if (!is_array($objects) || !is_array($top)) {
            return null;
        }

        if (!$this->containsUidReference($top)) {
            return null;
        }

        $normalized             = $dictionary;
        $normalized['$objects'] = $objects;
        $normalized['$top']     = $top;

        if (!array_key_exists('$archiver', $normalized)) {
            $archiverKey = $this->firstExistingKey($dictionary, '$archiver', 'archiver');
            if ($archiverKey !== null) {
                $normalized['$archiver'] = $dictionary[$archiverKey];
            }
        }

        if (!array_key_exists('$version', $normalized)) {
            $versionKey = $this->firstExistingKey($dictionary, '$version', 'version');
            if ($versionKey !== null) {
                $normalized['$version'] = $dictionary[$versionKey];
            }
        }

        /** @phpstan-ignore-next-line */
        return $normalized;
    }

    /**
     * Returns the first existing key from a prioritized list.
     *
     * @param array<int|string, NativePlistValue> $dictionary Dictionary to search.
     * @param string                              ...$keys    Priority-ordered keys to check.
     *
     * @return string|null First matching key or null if none exist.
     */
    private function firstExistingKey(array $dictionary, string ...$keys): ?string
    {
        return array_find(
            $keys,
            static fn (string $key): bool => array_key_exists($key, $dictionary)
        );
    }
}
