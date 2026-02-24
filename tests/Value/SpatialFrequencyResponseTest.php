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
 * Exercises SpatialFrequencyResponse creation from decoded matrix payloads.
 * It validates column/row sizing and SFR value matrices derived from EXIF data.
 * The suite ensures missing labels or malformed matrices are rejected.
 * This keeps SFR metadata aligned with the EXIF structure and expectations.
 */
#[CoversClass(SpatialFrequencyResponse::class)]
final class SpatialFrequencyResponseTest extends TestCase
{
    /**
     * Builds spatial frequency responses from decoded matrix data.
     * It validates the transformation using representative inputs.
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
     * Returns null for empty or missing SFR matrices.
     * It verifies the error path and guardrail handling.
     */
    #[Test]
    public function invalidMatrixReturnsNull(): void
    {
        self::assertNull(SpatialFrequencyResponse::fromMatrix(null));
        self::assertNull(SpatialFrequencyResponse::fromMatrix([]));
        self::assertNull(SpatialFrequencyResponse::fromMatrix(['columns' => 0]));
    }

    /**
     * Returns null when required SFR fields are missing.
     * It ensures missing or invalid inputs yield no value.
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
     * Rejects SFR matrices with invalid dimension declarations.
     * It verifies the error path and guardrail handling.
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
