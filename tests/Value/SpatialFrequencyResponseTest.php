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
use PHPUnit\Framework\TestCase;

/**
 * Tests for SpatialFrequencyResponse value object.
 *
 * EXIF 3.0 §4.6.3 Table 16 defines SFR structure:
 * - columns: number of spatial frequency columns
 * - rows: number of SFR value rows
 * - columnLabels: frequency values
 * - rowLabels: direction/color labels
 * - values: matrix of SRATIONAL response values
 */
#[CoversClass(SpatialFrequencyResponse::class)]
final class SpatialFrequencyResponseTest extends TestCase
{
    public function testCreateSfrFromDecodedMatrix(): void
    {
        $matrix = [
            'columns' => 3,
            'rows'    => 2,
            'labels'  => [
                'columns' => ['10', '20', '30'],
                'rows'    => ['Horizontal', 'Vertical'],
            ],
            'values' => [
                [1.0, 0.9, 0.7],
                [0.95, 0.85, 0.65],
            ],
        ];

        $sfr = SpatialFrequencyResponse::fromMatrix($matrix);

        self::assertNotNull($sfr);
        self::assertSame(3, $sfr->columns);
        self::assertSame(2, $sfr->rows);
        self::assertSame(['10', '20', '30'], $sfr->spatialFrequencies);
        self::assertSame(['Horizontal', 'Vertical'], $sfr->directions);
        self::assertSame(
            [
                [1.0, 0.9, 0.7],
                [0.95, 0.85, 0.65],
            ],
            $sfr->values
        );
    }

    public function testCreateSfrWithNullValues(): void
    {
        $matrix = [
            'columns' => 2,
            'rows'    => 1,
            'labels'  => [
                'columns' => ['10', '20'],
                'rows'    => ['H'],
            ],
            'values' => [
                [null, 0.8],
            ],
        ];

        $sfr = SpatialFrequencyResponse::fromMatrix($matrix);

        self::assertNotNull($sfr);
        self::assertSame([[null, 0.8]], $sfr->values);
    }

    public function testInvalidMatrixReturnsNull(): void
    {
        self::assertNull(SpatialFrequencyResponse::fromMatrix(null));
        self::assertNull(SpatialFrequencyResponse::fromMatrix([]));
        self::assertNull(SpatialFrequencyResponse::fromMatrix(['columns' => 0]));
    }

    public function testMissingRequiredFieldsReturnsNull(): void
    {
        $incomplete = [
            'columns' => 2,
            'rows'    => 1,
        ];

        self::assertNull(SpatialFrequencyResponse::fromMatrix($incomplete));
    }

    public function testInvalidDimensionsReturnsNull(): void
    {
        $matrix = [
            'columns' => 2,
            'rows'    => 1,
            'labels'  => [
                'columns' => ['10'],
                'rows'    => ['H'],
            ],
            'values' => [
                [1.0, 0.9],
            ],
        ];

        // Column count mismatch (labels has 1, but columns says 2)
        self::assertNull(SpatialFrequencyResponse::fromMatrix($matrix));
    }
}
