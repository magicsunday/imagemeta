<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model\Exif;

use function array_values;
use function is_array;
use function is_float;
use function is_int;

/**
 * Represents a single entry within an image file directory (IFD).
 */
final readonly class IfdEntry
{
    public int $tag;

    public int $type;

    public int $count;

    public int|float|string|ExifRational|ExifRationalList|ExifNumericList $value;

    /**
     * Normalises raw decoded values so callers may provide convenient array representations.
     *
     * @param int                                                               $tag   The numeric identifier of the entry.
     * @param int                                                               $type  The TIFF field type code.
     * @param int                                                               $count The number of values stored in the entry.
     * @param int|float|string|ExifRational|ExifRationalList|ExifNumericList|array $value  The raw value or values decoded from the IFD.
     */
    public function __construct(
        int $tag,
        int $type,
        int $count,
        int|float|string|ExifRational|ExifRationalList|ExifNumericList|array $value,
    ) {
        $this->tag   = $tag;
        $this->type  = $type;
        $this->count = $count;
        $this->value = $this->normaliseValue($type, $count, $value);
    }

    /**
     * Converts shorthand array inputs into strongly typed EXIF value objects.
     *
     * @param int                                                               $type  TIFF field type code describing the payload.
     * @param int                                                               $count Number of values stored for the entry.
     * @param int|float|string|ExifRational|ExifRationalList|ExifNumericList|array $value Raw value passed to the constructor.
     *
     * @return int|float|string|ExifRational|ExifRationalList|ExifNumericList
     */
    private function normaliseValue(
        int $type,
        int $count,
        int|float|string|ExifRational|ExifRationalList|ExifNumericList|array $value,
    ): int|float|string|ExifRational|ExifRationalList|ExifNumericList {
        if (!is_array($value)) {
            return $value;
        }

        if ($type === 5 || $type === 10) {
            $rationals = $this->normaliseRationalList($value);

            if (count($rationals) === 1) {
                return $rationals[0];
            }

            return new ExifRationalList($rationals);
        }

        $numericValues = $this->normaliseNumericList($value);

        if ($count === 1 && count($numericValues) === 1) {
            return $numericValues[0];
        }

        return new ExifNumericList($numericValues);
    }

    /**
     * Builds a list of {@see ExifRational} objects from array inputs.
     *
     * @param array<int, int|float|array<int, int|float>> $value The raw array representation.
     *
     * @return list<ExifRational>
     */
    private function normaliseRationalList(array $value): array
    {
        if ($this->isRationalPair($value)) {
            return [$this->pairToRational($value)];
        }

        $rationals = [];
        foreach ($value as $component) {
            if (!$this->isRationalPair($component)) {
                continue;
            }

            $rationals[] = $this->pairToRational($component);
        }

        return $rationals;
    }

    /**
     * Extracts numeric list components from an array representation.
     *
     * @param array<int, int|float> $value Raw numeric components.
     *
     * @return list<int|float>
     */
    private function normaliseNumericList(array $value): array
    {
        $numericValues = [];
        foreach ($value as $component) {
            if (is_int($component) || is_float($component)) {
                $numericValues[] = $component;
            }
        }

        return $numericValues;
    }

    /**
     * Determines whether the provided value represents a rational numerator/denominator pair.
     *
     * @param mixed $value Potential rational pair to inspect.
     */
    private function isRationalPair(mixed $value): bool
    {
        if (!is_array($value)) {
            return false;
        }

        $components = array_values($value);

        return isset($components[0], $components[1])
            && (is_int($components[0]) || is_float($components[0]))
            && (is_int($components[1]) || is_float($components[1]));
    }

    /**
     * Converts a numerator/denominator array into an {@see ExifRational} instance.
     *
     * @param array<int, int|float> $pair Numerator/denominator values.
     */
    private function pairToRational(array $pair): ExifRational
    {
        $components = array_values($pair);

        return new ExifRational((int) $components[0], (int) $components[1]);
    }
}
