<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Value;

use MagicSunday\ImageMeta\Value\SpatialFrequencyResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SpatialFrequencyResponse value object.
 *
 * EXIF 3.0 §4.6.3 Table 16 defines SFR structure:
 * - columns: number of spatial frequency columns
 * - rows: number of SFR value rows
 * - columnLabels: frequency values
 * - rowLabels: direction/color labels
 * - values: matrix of RATIONAL response values
 */
#[CoversClass(SpatialFrequencyResponse::class)]
final class SpatialFrequencyResponseTest extends TestCase
{
    /**
     * Verifies that $sfr is not null.
     *
     * @return void
     */
    #[Test]
    public function createSfrFromDecodedMatrix(): void
    {
        $matrix = [
            'columns' => 3,
            'rows'    => 2,
            'labels'  => [
                'columns' => ['0.1', '0.2', '0.3'],
            ],
            'values' => [
                [1.00, 0.90, 0.80],
                [1.00, 0.95, 0.85],
            ],
        ];

        $sfr = SpatialFrequencyResponse::fromMatrix($matrix);

        self::assertNotNull($sfr);
        self::assertSame(3, $sfr->columns);
        self::assertSame(2, $sfr->rows);
        self::assertSame(['0.1', '0.2', '0.3'], $sfr->spatialFrequencies);
        self::assertSame(
            [
                [1.00, 0.90, 0.80],
                [1.00, 0.95, 0.85],
            ],
            $sfr->values
        );
    }

    /**
     * Verifies that SpatialFrequencyResponse::fromMatrix(null) is null.
     *
     * @return void
     */
    #[Test]
    public function invalidMatrixReturnsNull(): void
    {
        self::assertNull(SpatialFrequencyResponse::fromMatrix(null));
        self::assertNull(SpatialFrequencyResponse::fromMatrix([]));
        self::assertNull(SpatialFrequencyResponse::fromMatrix(['columns' => 0]));
    }

    /**
     * Verifies that SpatialFrequencyResponse::fromMatrix($incomplete) is null.
     *
     * @return void
     */
    #[Test]
    public function missingRequiredFieldsReturnsNull(): void
    {
        $incomplete = [
            'columns' => 2,
            'rows'    => 1,
        ];

        self::assertNull(SpatialFrequencyResponse::fromMatrix($incomplete));
    }

    /**
     * Verifies that SpatialFrequencyResponse::fromMatrix($matrix) is null.
     *
     * @return void
     */
    #[Test]
    public function invalidDimensionsReturnsNull(): void
    {
        $matrix = [
            'columns' => 2,
            'rows'    => 1,
            'labels'  => [
                'columns' => ['10'],
            ],
            'values' => [
                [1.0, 0.9],
            ],
        ];

        // Column count mismatch (labels has 1, but columns says 2)
        self::assertNull(SpatialFrequencyResponse::fromMatrix($matrix));
    }
}
