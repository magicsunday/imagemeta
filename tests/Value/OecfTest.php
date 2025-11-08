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
use PHPUnit\Framework\TestCase;

/**
 * Tests for OECF (Opto-Electronic Conversion Function) value object.
 *
 * EXIF 3.0 §4.6.3 Table 15 defines OECF structure:
 * - columns: number of input (pixel value) columns
 * - rows: number of output (luminance) rows
 * - columnLabels: names for each column
 * - rowLabels: names for each row
 * - values: matrix of SRATIONAL values
 */
#[CoversClass(Oecf::class)]
final class OecfTest extends TestCase
{
    public function testCreateOecfFromDecodedMatrix(): void
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

    public function testCreateOecfWithNullValues(): void
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

    public function testInvalidMatrixReturnsNull(): void
    {
        self::assertNull(Oecf::fromMatrix(null));
        self::assertNull(Oecf::fromMatrix([]));
        self::assertNull(Oecf::fromMatrix(['columns' => 0]));
    }

    public function testMissingRequiredFieldsReturnsNull(): void
    {
        $incomplete = [
            'columns' => 2,
            'rows'    => 1,
        ];

        self::assertNull(Oecf::fromMatrix($incomplete));
    }

    public function testInvalidDimensionsReturnsNull(): void
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
}
