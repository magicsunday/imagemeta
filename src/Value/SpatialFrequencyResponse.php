<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value;

/**
 * Spatial Frequency Response (SFR) value object.
 *
 * Matches the binary layout described in EXIF 3.0 §4.6.6.7.25
 * and Figure 20 "Spatial Frequency Response Description":
 *
 *  - columns (n): number of spatial frequency columns
 *  - rows    (m): number of SFR rows
 *  - column item names: spatial frequency labels
 *  - m×n RATIONAL values: SFR[row][column]
 */
final readonly class SpatialFrequencyResponse
{
    /**
     * @param int                $columns            Number of frequency columns (n).
     * @param int                $rows               Number of SFR rows (m).
     * @param list<string>       $spatialFrequencies Column item names (spatial frequencies).
     * @param array<list<float>> $values             SFR values matrix [row][column].
     */
    public function __construct(
        public int $columns,
        public int $rows,
        public array $spatialFrequencies,
        public array $values,
    ) {
    }

    /**
     * Creates a SpatialFrequencyResponse from a decoded matrix structure.
     *
     * Expected decoded structure (already parsed from tag 41484):
     *
     * [
     *     'columns' => int,                          // n
     *     'rows'    => int,                          // m
     *     'labels'  => [
     *         'columns' => list<string>,             // column item names
     *     ],
     *     'values'  => array<list<float>> // SFR[row][column]
     * ]
     *
     * @param array<string, array|int>|null $matrix
     *
     * @return self|null
     */
    public static function fromMatrix(?array $matrix): ?self
    {
        $parts = MatrixValidator::validateMatrix($matrix, false, false);
        if ($parts === null) {
            return null;
        }

        return new self(
            $parts->columns,
            $parts->rows,
            $parts->columnLabels,
            $parts->values
        );
    }
}
