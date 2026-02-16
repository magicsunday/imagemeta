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

        $result = $this->converter()->decodeOecf($payload);

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

        $result = $this->converter()->decodeOecf($payload);

        self::assertNull($result);
    }

    /**
     * A valid 1x1 SpatialFrequencyResponse payload with exact length decodes.
     */
    #[Test]
    public function decodesValidSpatialFrequencyResponsePayload(): void
    {
        $payload = $this->buildMatrixPayload(1, 1, ['C'], ['R'], [[3, 4]]);

        $result = $this->converter()->decodeSpatialFrequencyResponse($payload);

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

        $result = $this->converter()->decodeSpatialFrequencyResponse($payload);

        self::assertNull($result);
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
