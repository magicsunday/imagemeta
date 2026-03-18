<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value;

use MagicSunday\ImageMeta\Core\Util\MatrixParts;
use MagicSunday\ImageMeta\Core\Util\MatrixValidator;

use function array_any;
use function in_array;

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
 *
 * @phpstan-type DecodedMatrix array{columns?:scalar|null, rows?:scalar|null, labels?:array{columns?:array<int, scalar|null>|scalar|null, rows?:array<int, scalar|null>|scalar|null}|null, values?:array<int, array<int, scalar|null>|scalar|null>|null}|null
 */
final readonly class SpatialFrequencyResponse
{
    /** @var list<string> Column item names (spatial frequencies). */
    public array $spatialFrequencies;

    /** @var list<list<float>> SFR values matrix [row][column]. */
    public array $values;

    /**
     * @param int               $columns            Number of frequency columns (n).
     * @param int               $rows               Number of SFR rows (m).
     * @param list<string>      $spatialFrequencies Column item names (spatial frequencies).
     * @param list<list<float>> $values             SFR values matrix [row][column].
     */
    public function __construct(
        public int $columns,
        public int $rows,
        array $spatialFrequencies,
        array $values,
    ) {
        $this->spatialFrequencies = [...$spatialFrequencies];
        $this->values             = [...$values];
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
     *     'values'  => list<list<float>> // SFR[row][column]
     * ]
     *
     * @param DecodedMatrix $matrix
     *
     * @return self|null
     */
    public static function fromMatrix(?array $matrix): ?self
    {
        $parts  = MatrixValidator::validateMatrix($matrix, false, false);

        if (!$parts instanceof MatrixParts) {
            return null;
        }

        if (array_any($parts->values, static fn (array $row): bool => in_array(null, $row, true))) {
            return null;
        }

        /** @var list<list<float>> $values */
        $values = $parts->values;

        return new self(
            $parts->columns,
            $parts->rows,
            $parts->columnLabels,
            $values
        );
    }
}
