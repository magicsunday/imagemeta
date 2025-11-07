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
 * Opto-Electronic Conversion Function (OECF) data structure.
 *
 * EXIF 3.0 §4.6.3 Table 15: OECF describes the relationship between camera's optical input
 * and the image file values. The structure contains:
 * - Dimensions (columns × rows matrix)
 * - Column labels (typically input/pixel values)
 * - Row labels (typically output/luminance values)
 * - Matrix of SRATIONAL values representing the conversion function
 */
final readonly class Oecf
{
    /**
     * Creates an OECF value object.
     *
     * @param int                          $columns      Number of columns in the OECF matrix.
     * @param int                          $rows         Number of rows in the OECF matrix.
     * @param list<string>                 $columnLabels Labels for each column (input values).
     * @param list<string>                 $rowLabels    Labels for each row (output values).
     * @param list<list<float|null>>       $values       Matrix of SRATIONAL conversion values.
     */
    public function __construct(
        public int $columns,
        public int $rows,
        public array $columnLabels,
        public array $rowLabels,
        public array $values,
    ) {
    }

    /**
     * Creates an OECF from decoded matrix structure.
     *
     * EXIF 3.0 §4.6.3 Table 15: OECF matrix format with dimensions, labels, and values.
     *
     * @param array{columns:int, rows:int, labels:array{columns:list<string>, rows:list<string>}, values:list<list<float|null>>}|null $matrix Decoded OECF matrix.
     *
     * @return self|null OECF value object or null if matrix is invalid.
     */
    public static function fromMatrix(?array $matrix): ?self
    {
        if ($matrix === null || !is_array($matrix)) {
            return null;
        }

        $columns      = $matrix['columns'] ?? null;
        $rows         = $matrix['rows'] ?? null;
        $labels       = $matrix['labels'] ?? null;
        $values       = $matrix['values'] ?? null;

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
