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

/**
 * Spatial Frequency Response (SFR) data structure.
 *
 * EXIF 3.0 §4.6.3 Table 16: SFR records camera and optical system's spatial frequency
 * response characteristics. The structure contains:
 * - Dimensions (columns × rows matrix)
 * - Spatial frequency values (column labels)
 * - Direction labels (row labels, e.g., horizontal/vertical)
 * - Matrix of SRATIONAL response values
 */
final readonly class SpatialFrequencyResponse
{
    /**
     * Creates a spatial frequency response value object.
     *
     * @param int                    $columns            Number of frequency columns.
     * @param int                    $rows               Number of direction rows.
     * @param list<string>           $spatialFrequencies Spatial frequency values (cycles/pixel).
     * @param list<string>           $directions         Direction labels (e.g., "Horizontal", "Vertical").
     * @param list<list<float|null>> $values             Matrix of SRATIONAL response values.
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
     * Creates a SpatialFrequencyResponse from decoded matrix structure.
     *
     * EXIF 3.0 §4.6.3 Table 16: SFR matrix format with frequencies, directions, and values.
     *
     * @param array{columns:int, rows:int, labels:array{columns:list<string>, rows:list<string>}, values:list<list<float|null>>}|null $matrix Decoded SFR matrix.
     *
     * @return self|null SpatialFrequencyResponse value object or null if matrix is invalid.
     */
    public static function fromMatrix(?array $matrix): ?self
    {
        if ($matrix === null || !is_array($matrix)) {
            return null;
        }

        $columns = $matrix['columns'] ?? null;
        $rows    = $matrix['rows'] ?? null;
        $labels  = $matrix['labels'] ?? null;
        $values  = $matrix['values'] ?? null;

        if (!is_int($columns) || !is_int($rows) || !is_array($labels) || !is_array($values)) {
            return null;
        }

        $columnLabels = $labels['columns'] ?? null;
        $rowLabels    = $labels['rows'] ?? null;

        if (!is_array($columnLabels) || !is_array($rowLabels)) {
            return null;
        }

        // Validate dimensions match label counts
        if (count($columnLabels) !== $columns || count($rowLabels) !== $rows) {
            return null;
        }

        return new self($columns, $rows, $columnLabels, $rowLabels, $values);
    }
}
