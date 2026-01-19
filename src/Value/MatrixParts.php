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
 * Normalized matrix parts used by value objects.
 *
 * @param int                    $columns      Column count.
 * @param int                    $rows         Row count.
 * @param list<string>           $columnLabels Column labels.
 * @param list<string>|null      $rowLabels    Optional row labels.
 * @param list<list<float|null>> $values       Matrix values.
 */
final readonly class MatrixParts
{
    /**
     * @param int                    $columns      Column count.
     * @param int                    $rows         Row count.
     * @param list<string>           $columnLabels Column labels.
     * @param list<string>|null      $rowLabels    Optional row labels.
     * @param list<list<float|null>> $values       Matrix values.
     */
    public function __construct(
        public int $columns,
        public int $rows,
        public array $columnLabels,
        public ?array $rowLabels,
        public array $values,
    ) {
    }
}
