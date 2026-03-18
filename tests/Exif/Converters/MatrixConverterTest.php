<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Exif\Converters;

use MagicSunday\ImageMeta\Exif\Converters\MatrixConverter;
use MagicSunday\ImageMeta\Exif\Converters\NumericConverter;
use MagicSunday\ImageMeta\Exif\Converters\RationalConverter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function pack;

/**
 * Validates OECF and SpatialFrequencyResponse structured matrix payload decoding.
 */
#[CoversClass(MatrixConverter::class)]
#[UsesClass(RationalConverter::class)]
#[UsesClass(NumericConverter::class)]
final class MatrixConverterTest extends TestCase
{
    /**
     * A valid 1x1 OECF payload with exact length decodes successfully.
     */
    #[Test]
    public function decodesValidOecfPayload(): void
    {
        $payload = $this->buildMatrixPayload(1, 1, ['C'], ['R'], [[1, 2]]);

        $result  = $this->converter()->decodeOecf($payload);

        self::assertNotNull($result);
        self::assertSame(1, $result['columns']);
        self::assertSame(1, $result['rows']);
    }

    /**
     * OECF payload with trailing bytes is rejected.
     */
    #[Test]
    public function rejectsOecfPayloadWithTrailingBytes(): void
    {
        $payload = $this->buildMatrixPayload(1, 1, ['C'], ['R'], [[1, 2]]) . "\xFF";

        $result  = $this->converter()->decodeOecf($payload);

        self::assertNull($result);
    }

    /**
     * A valid 1x1 SpatialFrequencyResponse payload with exact length decodes.
     */
    #[Test]
    public function decodesValidSpatialFrequencyResponsePayload(): void
    {
        $payload = $this->buildMatrixPayload(1, 1, ['C'], ['R'], [[3, 4]]);

        $result  = $this->converter()->decodeSpatialFrequencyResponse($payload);

        self::assertNotNull($result);
        self::assertSame(1, $result['columns']);
    }

    /**
     * SpatialFrequencyResponse payload with trailing bytes is rejected.
     */
    #[Test]
    public function rejectsSpatialFrequencyResponsePayloadWithTrailingBytes(): void
    {
        $payload = $this->buildMatrixPayload(1, 1, ['C'], ['R'], [[3, 4]]) . "\x00";

        $result  = $this->converter()->decodeSpatialFrequencyResponse($payload);

        self::assertNull($result);
    }

    /**
     * A valid 2x3 OECF matrix (2 columns, 3 rows) decodes column labels, row labels, and values correctly.
     */
    #[Test]
    public function decodesOecfMatrixWithTwoColumnsAndThreeRows(): void
    {
        // 2 columns: "Lum", "R"; 3 rows: "R0", "R1", "R2"
        // Values in row-major order, each row has 2 rational pairs: [num0, den0, num1, den1]
        $payload = $this->buildMatrixPayload(
            2,
            3,
            ['Lum', 'R'],
            ['R0', 'R1', 'R2'],
            [
                [1, 4, 2, 4],   // row 0: Lum=1/4=0.25, R=2/4=0.5
                [3, 4, 4, 4],   // row 1: Lum=3/4=0.75, R=4/4=1.0
                [5, 4, 6, 4],   // row 2: Lum=5/4=1.25, R=6/4=1.5
            ],
        );

        $result  = $this->converter()->decodeOecf($payload);

        self::assertNotNull($result);
        self::assertSame(2, $result['columns']);
        self::assertSame(3, $result['rows']);
        self::assertSame(['Lum', 'R'], $result['labels']['columns']);
        self::assertSame(['R0', 'R1', 'R2'], $result['labels']['rows']);
        self::assertSame([[0.25, 0.5], [0.75, 1.0], [1.25, 1.5]], $result['values']);
    }

    /**
     * A valid 3x2 SpatialFrequencyResponse matrix (3 columns, 2 rows) decodes column labels, row labels, and values correctly.
     */
    #[Test]
    public function decodesSpatialFrequencyResponseMatrixWithThreeColumnsAndTwoRows(): void
    {
        // 3 columns: "MTF CWF", "MTF D65", "MTF A"; 2 rows: "10", "20"
        // Values in row-major order, each row has 3 rational pairs: [num0, den0, num1, den1, num2, den2]
        $payload = $this->buildMatrixPayload(
            3,
            2,
            ['MTF CWF', 'MTF D65', 'MTF A'],
            ['10', '20'],
            [
                [9, 10, 8, 10, 7, 10],   // row 0: 0.9, 0.8, 0.7
                [6, 10, 5, 10, 4, 10],   // row 1: 0.6, 0.5, 0.4
            ],
        );

        $result  = $this->converter()->decodeSpatialFrequencyResponse($payload);

        self::assertNotNull($result);
        self::assertSame(3, $result['columns']);
        self::assertSame(2, $result['rows']);
        self::assertSame(['MTF CWF', 'MTF D65', 'MTF A'], $result['labels']['columns']);
        self::assertSame(['10', '20'], $result['labels']['rows']);
        self::assertSame([[0.9, 0.8, 0.7], [0.6, 0.5, 0.4]], $result['values']);
    }

    /**
     * Builds a structured SRATIONAL matrix payload.
     *
     * @param int             $columns      Number of columns.
     * @param int             $rows         Number of rows.
     * @param list<string>    $columnLabels NUL-terminated column labels.
     * @param list<string>    $rowLabels    NUL-terminated row labels.
     * @param list<list<int>> $values       Matrix values as [numerator, denominator] pairs per cell.
     */
    private function buildMatrixPayload(int $columns, int $rows, array $columnLabels, array $rowLabels, array $values): string
    {
        $payload = pack('n', $columns) . pack('n', $rows);

        foreach ($columnLabels as $label) {
            $payload .= $label . "\0";
        }

        foreach ($rowLabels as $label) {
            $payload .= $label . "\0";
        }

        foreach ($values as $row) {
            for ($i = 0; $i < $columns * 2; $i += 2) {
                $payload .= pack('N', $row[$i]) . pack('N', $row[$i + 1]);
            }
        }

        return $payload;
    }

    private function converter(): MatrixConverter
    {
        return new MatrixConverter(new RationalConverter(new NumericConverter()));
    }
}
