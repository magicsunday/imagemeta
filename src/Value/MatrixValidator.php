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
 * Validates and normalizes decoded matrix structures.
 *
 * @phpstan-type DecodedMatrix array{columns?:scalar|null, rows?:scalar|null, labels?:array{columns?:array<int, scalar|null>|scalar|null, rows?:array<int, scalar|null>|scalar|null}|null, values?:array<int, array<int, scalar|null>|scalar|null>|null}|null
 */
final class MatrixValidator
{
    /**
     * @param DecodedMatrix $matrix           Decoded matrix payload.
     * @param bool          $requireRowLabels Whether row labels are mandatory.
     * @param bool          $allowNullValues  Whether null values are permitted.
     *
     * @return MatrixParts|null Normalized matrix parts or null when invalid.
     */
    public static function validateMatrix(
        ?array $matrix,
        bool $requireRowLabels,
        bool $allowNullValues,
    ): ?MatrixParts {
        if ($matrix === null) {
            return null;
        }

        $columns = $matrix['columns'] ?? null;
        $rows    = $matrix['rows'] ?? null;
        $labels  = $matrix['labels'] ?? null;
        $values  = $matrix['values'] ?? null;

        if (
            !is_int($columns)
            || !is_int($rows)
            || !is_array($labels)
            || !is_array($values)
        ) {
            return null;
        }

        if (($columns <= 0) || ($rows <= 0)) {
            return null;
        }

        $columnLabels = $labels['columns'] ?? null;
        if (!is_array($columnLabels)) {
            return null;
        }

        $rowLabels = $labels['rows'] ?? null;
        if (
            ($rowLabels === null)
            && $requireRowLabels
        ) {
            return null;
        }

        if (
            ($rowLabels !== null)
            && !is_array($rowLabels)
        ) {
            return null;
        }

        $normalizedColumnLabels = [];
        foreach ($columnLabels as $columnLabel) {
            if (!is_string($columnLabel)) {
                return null;
            }

            $normalizedColumnLabels[] = $columnLabel;
        }

        if (count($normalizedColumnLabels) !== $columns) {
            return null;
        }

        $normalizedRowLabels = null;
        if ($rowLabels !== null) {
            $normalizedRowLabels = [];
            foreach ($rowLabels as $rowLabel) {
                if (!is_string($rowLabel)) {
                    return null;
                }

                $normalizedRowLabels[] = $rowLabel;
            }

            if (count($normalizedRowLabels) !== $rows) {
                return null;
            }
        }

        if (count($values) !== $rows) {
            return null;
        }

        $normalizedValues = [];
        foreach ($values as $row) {
            if (!is_array($row)) {
                return null;
            }

            $normalizedRow = [];
            foreach ($row as $cell) {
                if (!is_float($cell)) {
                    if ($allowNullValues && ($cell === null)) {
                        $normalizedRow[] = null;
                        continue;
                    }

                    return null;
                }

                $normalizedRow[] = $cell;
            }

            if (count($normalizedRow) !== $columns) {
                return null;
            }

            $normalizedValues[] = $normalizedRow;
        }

        return new MatrixParts(
            $columns,
            $rows,
            $normalizedColumnLabels,
            $normalizedRowLabels,
            $normalizedValues
        );
    }
}
