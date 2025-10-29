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
 */
final class KeyedArchiveUnarchiver
{
    /**
     * @var list<ApplePlistValue>
     */
    private array $objects = [];

    /**
     * @var array<int, ApplePlistValue>
     */
    private array $resolved = [];

    /**
     * @var array<int, true>
     */
    private array $inProgress = [];

    public function unarchive(ApplePlistDictionary $archive): ApplePlistDictionary
    {
        $objectsValue = $archive->get('$objects');
        if (!$objectsValue instanceof ApplePlistArray || $objectsValue->isEmpty()) {
            throw new ParseError('The keyed archive object table is malformed.');
        }

        $topValue = $archive->get('$top');
        if (!$topValue instanceof ApplePlistDictionary) {
            throw new ParseError('The keyed archive is missing the top object reference.');
        }

        $rootValue = $topValue->get('root');
        if (!$rootValue instanceof ApplePlistDictionary) {
            throw new ParseError('The keyed archive does not define a root object.');
        }

        $this->objects    = $objectsValue->values();
        $this->resolved   = [];
        $this->inProgress = [];

        $root = $this->resolveValue($rootValue);
        if (!$root instanceof ApplePlistDictionary) {
            throw new ParseError('The keyed archive root object must be a dictionary.');
        }

        return $root;
    }

    private function resolveValue(ApplePlistValue $value): ApplePlistValue
    {
        if ($value instanceof ApplePlistDictionary) {
            if ($this->isUidReference($value)) {
                $uidValue = $value->get('CF$UID');
                if (!$uidValue instanceof ApplePlistScalar) {
                    throw new ParseError('The keyed archive UID reference is invalid.');
                }

                $uid = $uidValue->value();
                if (!is_int($uid)) {
                    throw new ParseError('The keyed archive UID reference is invalid.');
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
            $resolved = [];
            foreach ($value->values() as $entry) {
                $resolved[] = $this->resolveValue($entry);
            }

            return new ApplePlistArray($resolved);
        }

        return $value;
    }

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

    private function resolveUid(int $uid): ApplePlistValue
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

        $object = $this->objects[$uid];
        $value  = $this->resolveValue($object);

        unset($this->inProgress[$uid]);

        $this->resolved[$uid] = $value;

        return $value;
    }

    private function resolveDictionary(ApplePlistDictionary $dictionary): ApplePlistDictionary
    {
        $keysValue   = $dictionary->get('NS.keys');
        $valuesValue = $dictionary->get('NS.objects');

        if (!$keysValue instanceof ApplePlistArray) {
            throw new ParseError('The keyed archive dictionary keys are invalid.');
        }

        if (!$valuesValue instanceof ApplePlistArray) {
            throw new ParseError('The keyed archive dictionary values are invalid.');
        }

        $keys   = $keysValue->values();
        $values = $valuesValue->values();

        if (count($keys) !== count($values)) {
            throw new ParseError('The keyed archive dictionary contains mismatched entries.');
        }

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

            $value = $this->resolveValue($values[$index]);
            $result[(string) $scalar] = $value;
        }

        return new ApplePlistDictionary($result);
    }

    private function resolveArray(ApplePlistDictionary $array): ApplePlistArray
    {
        $objects = $array->get('NS.objects');
        if (!$objects instanceof ApplePlistArray) {
            throw new ParseError('The keyed archive array contents are invalid.');
        }

        $resolved = [];
        foreach ($objects->values() as $entry) {
            $resolved[] = $this->resolveValue($entry);
        }

        return new ApplePlistArray($resolved);
    }
}
