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

use function array_key_exists;
use function count;
use function is_int;
use function is_string;

/**
 * Minimal keyed archive unarchiver converting `CF$UID` based dictionaries into associative arrays.
 *
 * @phpstan-type KeyedArchiveValue ApplePlistArray|ApplePlistDictionary|ApplePlistScalar
 * @phpstan-type KeyedArchiveArray list<KeyedArchiveValue>
 * @phpstan-type KeyedArchiveDictionary array<string, KeyedArchiveValue>
 */
final class KeyedArchiveUnarchiver
{
    /**
     * @var KeyedArchiveArray
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
     * Unarchives a keyed plist dictionary into a resolved dictionary tree.
     *
     * @param ApplePlistDictionary $archive Root keyed archive dictionary.
     *
     * @return ApplePlistDictionary Resolved root dictionary.
     */
    public function unarchive(ApplePlistDictionary $archive): ApplePlistDictionary
    {
        $objectsValue = $archive->get('$objects');
        if (!($objectsValue instanceof ApplePlistArray) || $objectsValue->isEmpty()) {
            throw new ParseError('The keyed archive object table is malformed.', 1091);
        }

        $topValue = $archive->get('$top');
        if (!$topValue instanceof ApplePlistDictionary) {
            throw new ParseError('The keyed archive is missing the top object reference.', 1092);
        }

        $rootValue = $topValue->get('root');
        if (!$rootValue instanceof ApplePlistDictionary) {
            throw new ParseError('The keyed archive does not define a root object.', 1093);
        }

        /** @var KeyedArchiveArray $objects */
        $objects       = $objectsValue->values();
        $this->objects = $objects;

        $this->resolved   = [];
        $this->inProgress = [];

        $root = $this->resolveValue($rootValue);
        if (!$root instanceof ApplePlistDictionary) {
            throw new ParseError('The keyed archive root object must be a dictionary.', 1094);
        }

        return $root;
    }

    /**
     * @phpstan-return ApplePlistArray|ApplePlistDictionary|ApplePlistScalar
     */
    private function resolveValue(ApplePlistValueInterface $value): ApplePlistArray|ApplePlistDictionary|ApplePlistScalar
    {
        if ($value instanceof ApplePlistDictionary) {
            if ($this->isUidReference($value)) {
                $uidValue = $value->get('CF$UID');
                if (!$uidValue instanceof ApplePlistScalar) {
                    throw new ParseError('The keyed archive UID reference is invalid.', 1095);
                }

                $uid = $uidValue->value();
                if (!is_int($uid)) {
                    throw new ParseError('The keyed archive UID reference is invalid.', 1096);
                }

                return $this->resolveUid($uid);
            }

            if (
                $value->has('NS.keys')
                && $value->has('NS.objects')
            ) {
                return $this->resolveDictionary($value);
            }

            if ($value->has('NS.objects') && !$value->has('NS.keys')) {
                return $this->resolveArray($value);
            }

            /** @var KeyedArchiveDictionary $resolved */
            $resolved = [];
            foreach ($value->entries() as $key => $entry) {
                if ($key === '$class') {
                    continue;
                }

                $resolved[$key] = $this->resolveValue($entry);
            }

            return new ApplePlistDictionary($resolved);
        }

        if ($value instanceof ApplePlistArray) {
            /** @var KeyedArchiveArray $resolved */
            $resolved = [];
            foreach ($value->values() as $entry) {
                $resolved[] = $this->resolveValue($entry);
            }

            return new ApplePlistArray($resolved);
        }

        if ($value instanceof ApplePlistScalar) {
            return $value;
        }

        throw new ParseError('Unsupported keyed archive value encountered.', 1097);
    }

    /**
     * Determines whether a dictionary is a CF$UID reference.
     *
     * @param ApplePlistDictionary $reference Dictionary to inspect.
     *
     * @return bool True when the dictionary is a UID reference.
     */
    private function isUidReference(ApplePlistDictionary $reference): bool
    {
        $entries = $reference->entries();
        if (count($entries) !== 1 || !array_key_exists('CF$UID', $entries)) {
            return false;
        }

        $value = $entries['CF$UID'];

        if (!$value instanceof ApplePlistScalar) {
            return false;
        }

        $uid = $value->value();

        return is_int($uid) || is_string($uid);
    }

    /**
     * Resolves a UID reference to its corresponding plist value.
     *
     * @param int $uid Object UID to resolve.
     *
     * @return ApplePlistArray|ApplePlistDictionary|ApplePlistScalar Resolved plist value.
     *
     * @phpstan-return ApplePlistArray|ApplePlistDictionary|ApplePlistScalar
     *
     * @throws ParseError If UID is invalid or creates circular reference.
     */
    private function resolveUid(int $uid): ApplePlistArray|ApplePlistDictionary|ApplePlistScalar
    {
        if (array_key_exists($uid, $this->resolved)) {
            return $this->resolved[$uid];
        }

        if (array_key_exists($uid, $this->inProgress)) {
            throw new ParseError('Recursive keyed archive reference detected.', 1098);
        }

        if (!array_key_exists($uid, $this->objects)) {
            throw new ParseError('The keyed archive object reference is invalid.', 1099);
        }

        $this->inProgress[$uid] = true;

        $object = $this->objects[$uid];
        $value  = $this->resolveValue($object);

        unset($this->inProgress[$uid]);

        $this->resolved[$uid] = $value;

        return $value;
    }

    /**
     * Resolves a keyed archive dictionary structure.
     *
     * @param ApplePlistDictionary $dictionary Dictionary containing NS.keys and NS.objects.
     *
     * @return ApplePlistDictionary Resolved dictionary with string keys.
     *
     * @phpstan-return ApplePlistDictionary
     *
     * @throws ParseError If dictionary structure is invalid.
     */
    private function resolveDictionary(ApplePlistDictionary $dictionary): ApplePlistDictionary
    {
        $keysValue   = $dictionary->get('NS.keys');
        $valuesValue = $dictionary->get('NS.objects');

        if (!$keysValue instanceof ApplePlistArray) {
            throw new ParseError('The keyed archive dictionary keys are invalid.', 1100);
        }

        if (!$valuesValue instanceof ApplePlistArray) {
            throw new ParseError('The keyed archive dictionary values are invalid.', 1101);
        }

        $keys   = $keysValue->values();
        $values = $valuesValue->values();

        if (count($keys) !== count($values)) {
            throw new ParseError('The keyed archive dictionary contains mismatched entries.', 1102);
        }

        /** @var KeyedArchiveDictionary $result */
        $result = [];
        foreach ($keys as $index => $keyReference) {
            $key = $this->resolveValue($keyReference);
            if (!$key instanceof ApplePlistScalar) {
                continue;
            }

            $scalar = $key->value();
            if (!is_string($scalar) && !is_int($scalar)) {
                continue;
            }

            $value                    = $this->resolveValue($values[$index]);
            $result[(string) $scalar] = $value;
        }

        return new ApplePlistDictionary($result);
    }

    /**
     * Resolves a keyed archive array structure.
     *
     * @param ApplePlistDictionary $array Dictionary containing NS.objects array.
     *
     * @return ApplePlistArray Resolved array of plist values.
     *
     * @phpstan-return ApplePlistArray
     *
     * @throws ParseError If array structure is invalid.
     */
    private function resolveArray(ApplePlistDictionary $array): ApplePlistArray
    {
        $objects = $array->get('NS.objects');
        if (!$objects instanceof ApplePlistArray) {
            throw new ParseError('The keyed archive array contents are invalid.', 1103);
        }

        /** @var KeyedArchiveArray $resolved */
        $resolved = [];
        foreach ($objects->values() as $entry) {
            $resolved[] = $this->resolveValue($entry);
        }

        return new ApplePlistArray($resolved);
    }
}
