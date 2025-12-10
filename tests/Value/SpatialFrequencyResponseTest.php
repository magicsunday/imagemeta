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
 * EXIF 3.0 §4.6.6.7.25 Figure 20/Table 12 defines the SFR structure:
 * - columns: number of spatial frequency columns
 * - rows: number of SFR value rows (directions/colour planes)
 * - columnLabels: frequency values
 * - rowLabels: direction labels
 * - values: matrix of RATIONAL response values
 */
#[CoversClass(SpatialFrequencyResponse::class)]
final class SpatialFrequencyResponseTest extends TestCase
{
    #[Test]
    public function createSfrFromDecodedMatrix(): void
    {
        $matrix = [
            'columns' => 3,
            'rows'    => 2,
            'labels'  => [
                'columns' => ['0.1', '0.2', '0.3'],
                'rows'    => ['Width', 'Height'],
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
        self::assertSame(['Width', 'Height'], $sfr->directions);
        self::assertSame(
            [
                [1.00, 0.90, 0.80],
                [1.00, 0.95, 0.85],
            ],
            $sfr->values
        );
    }

    #[Test]
    public function acceptsNullValuesWhenDenominatorMissing(): void
    {
        $matrix = [
            'columns' => 2,
            'rows'    => 1,
            'labels'  => [
                'columns' => ['0.1', '0.2'],
                'rows'    => ['Diagonal'],
            ],
            'values' => [
                [0.5, null],
            ],
        ];

        $sfr = SpatialFrequencyResponse::fromMatrix($matrix);

        self::assertNotNull($sfr);
        self::assertSame([[0.5, null]], $sfr->values);
    }

    #[Test]
    public function invalidMatrixReturnsNull(): void
    {
        self::assertNull(SpatialFrequencyResponse::fromMatrix(null));
        self::assertNull(SpatialFrequencyResponse::fromMatrix([]));
        self::assertNull(SpatialFrequencyResponse::fromMatrix(['columns' => 0]));
    }

    #[Test]
    public function missingRequiredFieldsReturnsNull(): void
    {
        $incomplete = [
            'columns' => 2,
            'rows'    => 1,
        ];

        self::assertNull(SpatialFrequencyResponse::fromMatrix($incomplete));
    }

    #[Test]
    public function invalidDimensionsReturnsNull(): void
    {
        $matrix = [
            'columns' => 2,
            'rows'    => 1,
            'labels'  => [
                'columns' => ['10'],
                'rows'    => ['Along Image Width', 'Along Image Height'],
            ],
            'values' => [
                [1.0, 0.9],
            ],
        ];

        // Column/row count mismatch compared to declared dimensions
        self::assertNull(SpatialFrequencyResponse::fromMatrix($matrix));
    }
}
