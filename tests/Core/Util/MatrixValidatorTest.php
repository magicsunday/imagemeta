<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Core\Util;

use MagicSunday\ImageMeta\Core\Util\MatrixParts;
use MagicSunday\ImageMeta\Core\Util\MatrixValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

use function array_map;

/**
 * Exercises MatrixValidator for validating decoded matrix payloads.
 * It verifies column/row labels and matrix shapes are accepted when well-formed.
 * The suite checks invalid structures return null rather than partial results.
 * This ensures matrix-backed EXIF tags are validated consistently.
 *
 * @internal
 */
#[CoversClass(MatrixValidator::class)]
#[CoversClass(MatrixParts::class)]
final class MatrixValidatorTest extends TestCase
{
    #[Test]
    public function usesDedicatedLabelNormalizationHelper(): void
    {
        $reflection = new ReflectionClass(MatrixValidator::class);
        $methods    = array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            $reflection->getMethods(ReflectionMethod::IS_PRIVATE),
        );

        self::assertContains('normalizeLabelList', $methods);
    }

    /**
     * Validates matrices that include required row and column labels.
     * It exercises the scenario described by the test name.
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

        $parts  = MatrixValidator::validateMatrix($matrix, true, true);

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
     * Allows missing row labels when not required.
     * It ensures missing or invalid inputs yield no value.
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

        $parts  = MatrixValidator::validateMatrix($matrix, false, false);

        self::assertNotNull($parts);
        self::assertNull($parts->rowLabels);
        self::assertSame([[0.9], [0.8]], $parts->values);
    }

    /**
     * Rejects null matrix values when nulls are not allowed.
     * It verifies the error path and guardrail handling.
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
     * Allows null matrix values when configured.
     * It ensures missing or invalid inputs yield no value.
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

        $parts  = MatrixValidator::validateMatrix($matrix, true, true);

        self::assertNotNull($parts);
        self::assertSame([[null]], $parts->values);
    }

    /**
     * Rejects matrices missing required row labels.
     * It exercises the scenario described by the test name.
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
