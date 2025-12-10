<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value;

use function count;
use function is_array;
use function is_float;
use function is_int;
use function is_string;

/**
 * Spatial Frequency Response (SFR) value object.
 *
 * Matches the binary layout described in EXIF 3.0 §4.6.6.7.25
 * and Figure 20 "Spatial Frequency Response Description" as well
 * as the legacy EXIF 2.32 §4.6.3 layout:
 *
 *  - columns (n): number of spatial frequency columns
 *  - rows    (m): number of SFR rows (directions/colour planes)
 *  - column item names: spatial frequency labels
 *  - row item names: direction labels (width/height/diagonal)
 *  - m×n RATIONAL values: SFR[row][column]
 */
final readonly class SpatialFrequencyResponse
{
    /**
     * @param int                                  $columns            Number of frequency columns (n).
     * @param int                                  $rows               Number of SFR rows (m).
     * @param list<string>                         $spatialFrequencies Column item names (spatial frequencies).
     * @param list<string>                         $directions         Row item names (directions/colour planes).
     * @param array<int, array<int, float|null>>   $values             SFR values matrix [row][column].
     */
    public function __construct(
        public int $columns,
        public int $rows,
        public array $spatialFrequencies,
        public array $directions,
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
     *         'rows'    => list<string>,             // row item names
     *     ],
     *     'values'  => array<list<float|null>>       // SFR[row][column]
     * ]
     *
     * @param array<string, mixed>|null $matrix
     *
     * @return self|null
     */
    public static function fromMatrix(?array $matrix): ?self
    {
        if ($matrix === null) {
            return null;
        }

        $columns = $matrix['columns'] ?? null;
        $rows    = $matrix['rows'] ?? null;
        $labels  = $matrix['labels'] ?? null;
        $values  = $matrix['values'] ?? null;

        if (
            !is_int($columns)
            || ($columns <= 0)
            || !is_int($rows)
            || ($rows <= 0)
            || !is_array($labels)
            || !is_array($values)
        ) {
            return null;
        }

        $columnLabels = $labels['columns'] ?? null;
        $rowLabels    = $labels['rows'] ?? null;
        if (!is_array($columnLabels) || !is_array($rowLabels)) {
            return null;
        }

        // Validate and normalize column item names to list<string>
        $frequencies = [];
        foreach ($columnLabels as $label) {
            if (!is_string($label)) {
                return null;
            }

            $frequencies[] = $label;
        }

        // Validate and normalize row item names to list<string>
        $directions = [];
        foreach ($rowLabels as $label) {
            if (!is_string($label)) {
                return null;
            }

            $directions[] = $label;
        }

        if (count($frequencies) !== $columns || count($directions) !== $rows) {
            return null;
        }

        // Validate that values form an exact m×n matrix of RATIONALs (null if denominator was zero)
        if (count($values) !== $rows) {
            return null;
        }

        $typedValues = [];

        foreach ($values as $rowIndex => $row) {
            if (!is_array($row)) {
                return null;
            }

            $typedRow = [];

            foreach ($row as $cell) {
                // Spec: RATIONAL ⇒ numeric value; decoder maps zero denominators to null
                if (!is_float($cell) && ($cell !== null)) {
                    return null;
                }

                $typedRow[] = $cell;
            }

            if (count($typedRow) !== $columns) {
                return null;
            }

            $typedValues[$rowIndex] = $typedRow;
        }

        return new self(
            $columns,
            $rows,
            $frequencies,
            $directions,
            $typedValues
        );
    }
}
