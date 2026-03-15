<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Exif\Converters;

use MagicSunday\ImageMeta\Core\Util\UInt64;
use MagicSunday\ImageMeta\Exif\Model\ExifNumericList;
use MagicSunday\ImageMeta\Exif\Model\ExifRational;
use MagicSunday\ImageMeta\Exif\Model\ExifRationalList;

use function array_all;
use function array_any;
use function array_filter;
use function array_values;
use function count;
use function explode;
use function implode;
use function is_numeric;
use function str_replace;

/**
 * Converts EXIF component configuration and subsampling values.
 *
 * EXIF 3.0 §4.6.5.1.3 (ComponentsConfiguration) and §4.6.5.1.12 (YCbCrSubSampling).
 */
final readonly class ComponentsConverter
{
    /**
     * Creates the converter with its numeric dependency.
     *
     * @param NumericConverter $numericConverter Dependency for numeric conversions.
     */
    public function __construct(
        private NumericConverter $numericConverter,
    ) {
    }

    /**
     * Normalizes the components configuration tag into a list of component identifiers.
     *
     * EXIF 3.0 §4.6.5.1.3 defines allowed component codes: 0 (does not exist),
     * 1 (Y), 2 (Cb), 3 (Cr), 4 (R), 5 (G), 6 (B). Values outside this set are
     * rejected as non-conformant.
     *
     * @param array<int, int|float|string|UInt64>|ExifNumericList|ExifRationalList|ExifRational|UInt64|string|int|float|null $value Raw EXIF value.
     *
     * @return list<int>|null
     */
    public function configuration(
        array|ExifNumericList|ExifRationalList|ExifRational|UInt64|string|int|float|null $value,
    ): ?array {
        $components = $this->numericConverter->toIntList($value);

        if ($components === null) {
            return null;
        }

        if (!array_all($components, static fn (int $code): bool => $code >= 0 && $code <= 6)) {
            return null;
        }

        return $components;
    }

    /**
     * Formats a components configuration payload into human readable channel labels.
     *
     * @param array<int, int|float|string|UInt64>|ExifNumericList|ExifRationalList|ExifRational|UInt64|string|int|float|null $value Raw EXIF value.
     *
     * @return list<string>|null
     */
    public function configurationLabels(
        array|ExifNumericList|ExifRationalList|ExifRational|UInt64|string|int|float|null $value,
    ): ?array {
        $components = $this->numericConverter->toIntList($value);

        if ($components === null || $components === []) {
            return null;
        }

        $labels = [];

        foreach ($components as $component) {
            $label = match ($component) {
                0       => '-',
                1       => 'Y',
                2       => 'Cb',
                3       => 'Cr',
                4       => 'R',
                5       => 'G',
                6       => 'B',
                default => null,
            };

            if ($label === null) {
                return null;
            }

            $labels[] = $label;
        }

        return $labels;
    }

    /**
     * Returns a human readable description for the components configuration.
     *
     * @param array<int, int|float|string|UInt64>|ExifNumericList|ExifRationalList|ExifRational|UInt64|string|int|float|null $value Raw EXIF value.
     */
    public function configurationDescription(
        array|ExifNumericList|ExifRationalList|ExifRational|UInt64|string|int|float|null $value,
    ): ?string {
        $labels = $this->configurationLabels($value);

        return $labels !== null ? implode(' ', $labels) : null;
    }

    /**
     * Converts a textual YCbCr subsampling representation into integer pairs.
     *
     * EXIF 3.0 §4.6.5.1.12 (YCbCrSubSampling) defines only [2,1] (YCbCr4:2:2) and
     * [2,2] (YCbCr4:2:0) as legal values.
     *
     * @return array{0:int,1:int}|null
     */
    public function ycbcrSubSamplingToPair(?string $val): ?array
    {
        if ($val === null || $val === '') {
            return null;
        }

        $parts = array_values(array_filter(
            explode(' ', str_replace([',', ';'], ' ', $val)),
            static fn (string $part): bool => $part !== '',
        ));

        if (count($parts) !== 2) {
            return null;
        }

        if (!is_numeric($parts[0]) || !is_numeric($parts[1])) {
            return null;
        }

        $horizontal = (int) $parts[0];
        $vertical   = (int) $parts[1];

        // EXIF 3.0 §4.6.5.1.12: legal values are [2,1] (YCbCr4:2:2) and [2,2] (YCbCr4:2:0)
        $legalValues = [
            [2, 1],
            [2, 2],
        ];

        $result = array_any(
            $legalValues,
            fn ($legal): bool => $horizontal === $legal[0] && $vertical === $legal[1]
        );

        if ($result) {
            return [
                $horizontal,
                $vertical,
            ];
        }

        return null;
    }
}
