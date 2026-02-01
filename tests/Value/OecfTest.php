<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Value;

use MagicSunday\ImageMeta\Value\Oecf;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the Oecf value object creation from decoded matrix payloads.
 * It validates column/row dimensions, labels, and SRATIONAL value matrices.
 * The suite ensures invalid or incomplete matrices return null rather than partial data.
 * This keeps OECF metadata aligned with EXIF-defined structure and expectations.
 */
#[CoversClass(Oecf::class)]
final class OecfTest extends TestCase
{
    /**
     * Builds OECF values from decoded matrix data.
     * It validates the transformation using representative inputs.
     *
     * @return void
     */
    #[Test]
    public function createOecfFromDecodedMatrix(): void
    {
        $matrix = [
            'columns' => 2,
            'rows'    => 3,
            'labels'  => [
                'columns' => ['Input1', 'Input2'],
                'rows'    => ['Output1', 'Output2', 'Output3'],
            ],
            'values' => [
                [0.0, 0.5],
                [0.5, 1.0],
                [1.0, 1.5],
            ],
        ];

        $oecf = Oecf::fromMatrix($matrix);

        self::assertNotNull($oecf);
        self::assertSame(2, $oecf->columns);
        self::assertSame(3, $oecf->rows);
        self::assertSame(['Input1', 'Input2'], $oecf->columnLabels);
        self::assertSame(['Output1', 'Output2', 'Output3'], $oecf->rowLabels);
        self::assertSame(
            [
                [0.0, 0.5],
                [0.5, 1.0],
                [1.0, 1.5],
            ],
            $oecf->values
        );
    }

    /**
     * Accepts null entries within OECF matrices.
     * It ensures missing or invalid inputs yield no value.
     *
     * @return void
     */
    #[Test]
    public function createOecfWithNullValues(): void
    {
        $matrix = [
            'columns' => 2,
            'rows'    => 1,
            'labels'  => [
                'columns' => ['Col1', 'Col2'],
                'rows'    => ['Row1'],
            ],
            'values' => [
                [null, 1.0],
            ],
        ];

        $oecf = Oecf::fromMatrix($matrix);

        self::assertNotNull($oecf);
        self::assertSame([[null, 1.0]], $oecf->values);
    }

    /**
     * Returns null for empty or missing OECF matrix inputs.
     * It verifies the error path and guardrail handling.
     *
     * @return void
     */
    #[Test]
    public function invalidMatrixReturnsNull(): void
    {
        self::assertNull(Oecf::fromMatrix(null));
        self::assertNull(Oecf::fromMatrix([]));
        self::assertNull(Oecf::fromMatrix(['columns' => 0]));
    }

    /**
     * Returns null when required OECF fields are missing.
     * It ensures missing or invalid inputs yield no value.
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

        self::assertNull(Oecf::fromMatrix($incomplete));
    }

    /**
     * Rejects OECF matrices with invalid dimension declarations.
     * It verifies the error path and guardrail handling.
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
                'columns' => ['Col1'],
                'rows'    => ['Row1'],
            ],
            'values' => [
                [1.0, 2.0],
            ],
        ];

        // Column count mismatch (labels has 1, but columns says 2)
        self::assertNull(Oecf::fromMatrix($matrix));
    }

    /**
     * Rejects OECF matrices when row counts do not match.
     * It ensures missing or invalid inputs yield no value.
     *
     * @return void
     */
    #[Test]
    public function mismatchingValueRowCountReturnsNull(): void
    {
        $matrix = [
            'columns' => 2,
            'rows'    => 2,
            'labels'  => [
                'columns' => ['Col1', 'Col2'],
                'rows'    => ['Row1', 'Row2'],
            ],
            'values' => [
                [1.0, 2.0],
            ],
        ];

        self::assertNull(Oecf::fromMatrix($matrix));
    }

    /**
     * Rejects OECF matrices when column counts do not match.
     * It ensures missing or invalid inputs yield no value.
     *
     * @return void
     */
    #[Test]
    public function mismatchingValueColumnsReturnNull(): void
    {
        $matrix = [
            'columns' => 2,
            'rows'    => 1,
            'labels'  => [
                'columns' => ['Col1', 'Col2'],
                'rows'    => ['Row1'],
            ],
            'values' => [
                [1.0],
            ],
        ];

        self::assertNull(Oecf::fromMatrix($matrix));
    }
}
