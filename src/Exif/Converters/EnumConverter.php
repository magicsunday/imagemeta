<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Exif\Converters;

use BackedEnum;
use MagicSunday\ImageMeta\Model\Exif\ExifNumericList;
use MagicSunday\ImageMeta\Model\Exif\ExifRational;
use MagicSunday\ImageMeta\Model\Exif\ExifRationalList;
use Throwable;

use function ctype_digit;
use function fmod;
use function is_float;
use function is_int;
use function is_string;

/**
 * Converts EXIF values to PHP enums and boolean flags.
 *
 * Provides type-safe conversion of raw EXIF values to backed enum instances.
 */
final readonly class EnumConverter
{
    public function __construct(
        private RationalConverter $rationalConverter,
    ) {
    }

    /**
     * Attempts to map a raw value to a backed enum instance.
     *
     * @template T of BackedEnum
     *
     * @param class-string<T> $enumClass
     * @param int|string|null $raw
     *
     * @return T|null
     */
    public function toEnumOrNull(string $enumClass, int|string|null $raw): ?BackedEnum
    {
        if ($raw === null) {
            return null;
        }

        if ($raw === '') {
            return null;
        }

        $value = $raw;
        if (is_string($raw) && ctype_digit($raw)) {
            $value = (int) $raw;
        }

        /** @var class-string<T> $enumClass */
        try {
            /** @var T $resolved */
            $resolved = $enumClass::from($value);

            return $resolved;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Converts the maker note safety flag into a boolean representation.
     *
     * EXIF 3.0 §4.6.8 (MakerNoteSafety).
     *
     * @param ExifNumericList|ExifRationalList|ExifRational|int|float|string|null $value Raw value.
     */
    public function makerNoteSafety(
        ExifNumericList|ExifRationalList|ExifRational|int|float|string|null $value,
    ): ?bool {
        if ($value instanceof ExifNumericList) {
            $value = $value->values[0] ?? null;
        }

        if ($value instanceof ExifRationalList) {
            $value = $value->values[0] ?? null;
        }

        if ($value instanceof ExifRational) {
            $value = $this->rationalConverter->toFloat($value);
        }

        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            $intValue = $value;
        } elseif (is_float($value)) {
            if (fmod($value, 1.0) !== 0.0) {
                return null;
            }

            $intValue = (int) $value;
        } else {
            if (!ctype_digit($value)) {
                return null;
            }

            $intValue = (int) $value;
        }

        return match ($intValue) {
            0       => false,
            1       => true,
            default => null,
        };
    }
}
