<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Value;

use MagicSunday\ImageMeta\Value\MatrixParts;
use MagicSunday\ImageMeta\Value\MatrixValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MatrixValidator::class)]
#[CoversClass(MatrixParts::class)]
final class MatrixValidatorTest extends TestCase
{
    /**
     * Verifies that $parts is not null.
     *
     * @return void
     */
    #[Test]
    public function validateMatrixWithRequiredRowLabels(): void
    {
        $matrix = [
            'columns' => 2,
            'rows'    => 2,
            'labels'  => [
                'columns' => ['Input1', 'Input2'],
                'rows'    => ['Output1', 'Output2'],
            ],
            'values' => [
                [0.0, 0.5],
                [0.5, 1.0],
            ],
        ];

        $parts = MatrixValidator::validateMatrix($matrix, true, true);

        self::assertNotNull($parts);
        self::assertSame(2, $parts->columns);
        self::assertSame(2, $parts->rows);
        self::assertSame(['Input1', 'Input2'], $parts->columnLabels);
        self::assertSame(['Output1', 'Output2'], $parts->rowLabels);
        self::assertSame(
            [
                [0.0, 0.5],
                [0.5, 1.0],
            ],
            $parts->values
        );
    }

    /**
     * Verifies that $parts is not null.
     *
     * @return void
     */
    #[Test]
    public function validateMatrixAllowsMissingRowLabelsWhenOptional(): void
    {
        $matrix = [
            'columns' => 1,
            'rows'    => 2,
            'labels'  => [
                'columns' => ['0.1'],
            ],
            'values' => [
                [0.9],
                [0.8],
            ],
        ];

        $parts = MatrixValidator::validateMatrix($matrix, false, false);

        self::assertNotNull($parts);
        self::assertNull($parts->rowLabels);
        self::assertSame([[0.9], [0.8]], $parts->values);
    }

    /**
     * Verifies that MatrixValidator::validateMatrix($matrix, false, false) is null.
     *
     * @return void
     */
    #[Test]
    public function validateMatrixRejectsNullValuesWhenNotAllowed(): void
    {
        $matrix = [
            'columns' => 1,
            'rows'    => 1,
            'labels'  => [
                'columns' => ['Col1'],
            ],
            'values' => [
                [null],
            ],
        ];

        self::assertNull(MatrixValidator::validateMatrix($matrix, false, false));
    }

    /**
     * Verifies that $parts is not null.
     *
     * @return void
     */
    #[Test]
    public function validateMatrixAllowsNullValuesWhenConfigured(): void
    {
        $matrix = [
            'columns' => 1,
            'rows'    => 1,
            'labels'  => [
                'columns' => ['Col1'],
                'rows'    => ['Row1'],
            ],
            'values' => [
                [null],
            ],
        ];

        $parts = MatrixValidator::validateMatrix($matrix, true, true);

        self::assertNotNull($parts);
        self::assertSame([[null]], $parts->values);
    }

    /**
     * Verifies that MatrixValidator::validateMatrix($matrix, true, true) is null.
     *
     * @return void
     */
    #[Test]
    public function validateMatrixRequiresRowLabelsWhenConfigured(): void
    {
        $matrix = [
            'columns' => 1,
            'rows'    => 1,
            'labels'  => [
                'columns' => ['Col1'],
            ],
            'values' => [
                [0.1],
            ],
        ];

        self::assertNull(MatrixValidator::validateMatrix($matrix, true, true));
    }
}
