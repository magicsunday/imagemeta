<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value;

use MagicSunday\ImageMeta\Value\Enum\CfaPatternColor;

use function array_chunk;
use function count;

/**
 * Represents the colour filter array pattern layout for a one-chip colour area sensor.
 *
 * EXIF 3.0 §4.6.6.7.34 defines the CFA pattern payload as two SHORT repeat units followed
 * by m×n component identifiers (colour values per Table 13).
 */
final readonly class CfaPattern
{
    /** @var list<CfaPatternColor> Flattened colour list (row-major, vertical × horizontal). */
    public array $colors;

    /**
     * @param positive-int          $horizontalRepeatPixelUnit Number of lateral pixels before the pattern repeats.
     * @param positive-int          $verticalRepeatPixelUnit   Number of vertical pixels before the pattern repeats.
     * @param list<CfaPatternColor> $colors                    Flattened colour list (row-major, vertical × horizontal).
     */
    private function __construct(
        public int $horizontalRepeatPixelUnit,
        public int $verticalRepeatPixelUnit,
        array $colors,
    ) {
        $this->colors = [...$colors];
    }

    /**
     * Builds a CFA pattern from EXIF component identifiers.
     *
     * @param int       $horizontalRepeatPixelUnit Number of lateral pixels before the pattern repeats.
     * @param int       $verticalRepeatPixelUnit   Number of vertical pixels before the pattern repeats.
     * @param list<int> $componentIdentifiers      Raw component identifiers from the EXIF tag payload.
     */
    public static function fromComponents(
        int $horizontalRepeatPixelUnit,
        int $verticalRepeatPixelUnit,
        array $componentIdentifiers,
    ): ?self {
        if ($horizontalRepeatPixelUnit <= 0 || $verticalRepeatPixelUnit <= 0) {
            return null;
        }

        $expected = $horizontalRepeatPixelUnit * $verticalRepeatPixelUnit;

        if (count($componentIdentifiers) < $expected) {
            return null;
        }

        $colors = [];

        for ($index = 0; $index < $expected; ++$index) {
            $color = CfaPatternColor::fromExifValue($componentIdentifiers[$index]);

            if (!$color instanceof CfaPatternColor) {
                return null;
            }

            $colors[] = $color;
        }

        return new self($horizontalRepeatPixelUnit, $verticalRepeatPixelUnit, $colors);
    }

    /**
     * Returns the colour layout as a 2D grid organised by rows (vertical) and columns (horizontal).
     *
     * @return list<list<CfaPatternColor>>
     */
    public function grid(): array
    {
        return array_chunk($this->colors, $this->horizontalRepeatPixelUnit);
    }
}
