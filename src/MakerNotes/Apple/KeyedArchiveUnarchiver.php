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

use function array_is_list;
use function array_key_exists;
use function count;
use function is_array;
use function is_int;
use function is_string;

/**
 * Minimal keyed archive unarchiver converting `CF$UID` based dictionaries into associative arrays.
 *
 * @phpstan-type KeyedArchiveValue array<int|string, mixed>|bool|float|int|string|null
 * @phpstan-type KeyedArchiveList list<KeyedArchiveValue>
 * @phpstan-type KeyedArchiveDictionary array<int|string, KeyedArchiveValue>
 */
final class KeyedArchiveUnarchiver
{
    /**
     * @var KeyedArchiveList
     */
    private array $objects = [];

    /**
     * @var array<int, KeyedArchiveValue>
     */
    private array $resolved = [];

    /**
     * @var array<int, true>
     */
    private array $inProgress = [];

    /**
     * @param array<int|string, mixed> $archive
     *
     * @return KeyedArchiveDictionary
     */
    public function unarchive(array $archive): array
    {
        if (!array_key_exists('$objects', $archive)) {
            throw new ParseError('The keyed archive does not define any objects.');
        }

        $objects = $archive['$objects'];
        if (!is_array($objects) || !array_is_list($objects) || $objects === []) {
            throw new ParseError('The keyed archive object table is malformed.');
        }

        if (!array_key_exists('$top', $archive) || !is_array($archive['$top'])) {
            throw new ParseError('The keyed archive is missing the top object reference.');
        }

        $top = $archive['$top'];
        if (!array_key_exists('root', $top) || !is_array($top['root'])) {
            throw new ParseError('The keyed archive does not define a root object.');
        }

        /** @var KeyedArchiveList $objects */
        $this->objects    = $objects;
        $this->resolved   = [];
        $this->inProgress = [];

        $root = $this->resolveValue($top['root']);
        if (!is_array($root) || array_is_list($root)) {
            throw new ParseError('The keyed archive root object must be a dictionary.');
        }

        /** @var KeyedArchiveDictionary $root */
        return $root;
    }

    /**
     * @param KeyedArchiveValue $value
     *
     * @return KeyedArchiveValue
     */
    private function resolveValue(array|bool|float|int|string|null $value): array|bool|float|int|string|null
    {
        if (!is_array($value)) {
            return $value;
        }

        if ($this->isUidReference($value)) {
            $uid = $value['CF$UID'];
            if (!is_int($uid)) {
                throw new ParseError('The keyed archive UID reference is invalid.');
            }

            return $this->resolveUid($uid);
        }

        if (array_key_exists('NS.keys', $value) && array_key_exists('NS.objects', $value)) {
            /** @var array<int|string, mixed> $value */
            return $this->resolveDictionary($value);
        }

        if (array_key_exists('NS.objects', $value) && !array_key_exists('NS.keys', $value)) {
            /** @var array<int|string, mixed> $value */
            return $this->resolveArray($value);
        }

        if (array_is_list($value)) {
            $result = [];
            foreach ($value as $entry) {
                /** @var KeyedArchiveValue $entry */
                $result[] = $this->resolveValue($entry);
            }

            return $result;
        }

        $result = [];
        foreach ($value as $key => $entry) {
            if ($key === '$class') {
                continue;
            }

            /** @var KeyedArchiveValue $entry */
            $result[$key] = $this->resolveValue($entry);
        }

        return $result;
    }

    /**
     * @param array<int|string, mixed> $reference
     */
    private function isUidReference(array $reference): bool
    {
        return count($reference) === 1 && array_key_exists('CF$UID', $reference);
    }

    /**
     * @return KeyedArchiveValue
     */
    private function resolveUid(int $uid): array|bool|float|int|string|null
    {
        if (array_key_exists($uid, $this->resolved)) {
            return $this->resolved[$uid];
        }

        if (array_key_exists($uid, $this->inProgress)) {
            throw new ParseError('Recursive keyed archive reference detected.');
        }

        if (!array_key_exists($uid, $this->objects)) {
            throw new ParseError('The keyed archive object reference is invalid.');
        }

        $this->inProgress[$uid] = true;

        /** @var KeyedArchiveValue $object */
        $object = $this->objects[$uid];
        $value  = $this->resolveValue($object);

        unset($this->inProgress[$uid]);

        $this->resolved[$uid] = $value;

        return $value;
    }

    /**
     * @param array<int|string, mixed> $dictionary
     *
     * @return KeyedArchiveDictionary
     */
    private function resolveDictionary(array $dictionary): array
    {
        if (!array_key_exists('NS.keys', $dictionary) || !array_key_exists('NS.objects', $dictionary)) {
            throw new ParseError('The keyed archive dictionary is incomplete.');
        }

        $keys   = $dictionary['NS.keys'];
        $values = $dictionary['NS.objects'];

        if (!is_array($keys) || !array_is_list($keys)) {
            throw new ParseError('The keyed archive dictionary keys are invalid.');
        }

        if (!is_array($values) || !array_is_list($values)) {
            throw new ParseError('The keyed archive dictionary values are invalid.');
        }

        if (count($keys) !== count($values)) {
            throw new ParseError('The keyed archive dictionary contains mismatched entries.');
        }

        /** @var list<KeyedArchiveValue> $keys */
        /** @var list<KeyedArchiveValue> $values */
        $result = [];
        foreach ($keys as $index => $keyReference) {
            /** @var KeyedArchiveValue $keyReference */
            $key = $this->resolveValue($keyReference);
            if (!is_string($key) && !is_int($key)) {
                continue;
            }

            /** @var KeyedArchiveValue $value */
            $value = $this->resolveValue($values[$index]);

            $result[(string) $key] = $value;
        }

        return $result;
    }

    /**
     * @param array<int|string, mixed> $array
     *
     * @return KeyedArchiveList
     */
    private function resolveArray(array $array): array
    {
        if (!array_key_exists('NS.objects', $array)) {
            throw new ParseError('The keyed archive array payload is missing its objects.');
        }

        $objects = $array['NS.objects'];
        if (!is_array($objects) || !array_is_list($objects)) {
            throw new ParseError('The keyed archive array contents are invalid.');
        }

        /** @var list<KeyedArchiveValue> $objects */
        $result = [];
        foreach ($objects as $entry) {
            /** @var KeyedArchiveValue $entry */
            $result[] = $this->resolveValue($entry);
        }

        return $result;
    }
}
