<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Exif\Converters;

use MagicSunday\ImageMeta\Exif\Model\ExifNumericList;
use MagicSunday\ImageMeta\Exif\Model\ExifRational;
use MagicSunday\ImageMeta\Exif\Model\ExifRationalList;
use MagicSunday\ImageMeta\Value\FlashInfo;

use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function round;

/**
 * Converts EXIF flash bit field values.
 *
 * EXIF 3.0 §4.6.6.7.21 (Flash) defines the flash tag as a SHORT bit field.
 */
final readonly class FlashConverter
{
    /**
     * Converts the EXIF flash bit field into a typed value object.
     *
     * EXIF 3.0 §4.6.6.7.21 (Flash): Flash is a SHORT, so UInt64 cannot occur.
     *
     * @param int|float|string|ExifRational|ExifRationalList|ExifNumericList|null $value Flash tag value.
     */
    public function fromShort(
        int|float|string|ExifRational|ExifRationalList|ExifNumericList|null $value,
    ): ?FlashInfo {
        if ($value instanceof ExifNumericList) {
            $first = $value->values[0] ?? null;

            if (!is_int($first) && !is_float($first)) {
                return null;
            }

            $value = $first;
        }

        if ($value instanceof ExifRational) {
            if ($value->denominator === 0) {
                return null;
            }

            $value = (int) round((float) $value->numerator / (float) $value->denominator);
        }

        if ($value instanceof ExifRationalList) {
            $first = $value->values[0] ?? null;

            return $this->fromShort($first);
        }

        if (is_float($value) || is_int($value)) {
            return ExifFlash::fromExifValue((int) $value);
        }

        if (is_string($value) && is_numeric($value)) {
            return ExifFlash::fromExifValue((int) $value);
        }

        return null;
    }
}
