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
 * Opto-Electronic Conversion Function (OECF) data structure.
 *
 * EXIF 3.0 §4.6.6.7.6 (Figure 16, Table 11) describes the relationship between the camera's
 * optical input and the image file values. The structure contains:
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
     * @param int                                $columns      Number of columns in the OECF matrix.
     * @param int                                $rows         Number of rows in the OECF matrix.
     * @param list<string>                       $columnLabels Labels for each column (input values).
     * @param list<string>                       $rowLabels    Labels for each row (output values).
     * @param array<int, array<int, float|null>> $values       Matrix of SRATIONAL conversion values.
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
     * Creates an OECF from the decoded matrix structure.
     *
     * EXIF 3.0 §4.6.6.7.6 (Figure 16, Table 11): OECF matrix format with dimensions, labels,
     * and SRATIONAL values.
     *
     * @param array<string, array|int>|null $matrix Decoded OECF matrix. OECF matrix.
     *
     * @return self|null OECF value object or null if matrix is invalid.
     */
    public static function fromMatrix(?array $matrix): ?self
    {
        $parts = MatrixValidator::validateMatrix($matrix, true, true);
        if ($parts === null) {
            return null;
        }

        if ($parts->rowLabels === null) {
            return null;
        }

        return new self(
            $parts->columns,
            $parts->rows,
            $parts->columnLabels,
            $parts->rowLabels,
            $parts->values
        );
    }
}
