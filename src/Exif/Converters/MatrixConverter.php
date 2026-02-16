<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Exif\Converters;

use JsonException;
use MagicSunday\ImageMeta\Core\BitMask;
use MagicSunday\ImageMeta\Core\Util\UInt64;
use MagicSunday\ImageMeta\Exif\Model\ExifNumericList;
use MagicSunday\ImageMeta\Exif\Model\ExifRational;
use MagicSunday\ImageMeta\Exif\Model\ExifRationalList;

use function array_slice;
use function count;
use function implode;
use function intdiv;
use function is_array;
use function is_float;
use function is_int;
use function is_numeric;
use function json_encode;
use function strlen;
use function strpos;
use function substr;
use function trim;
use function unpack;

use const JSON_PRESERVE_ZERO_FRACTION;
use const JSON_THROW_ON_ERROR;
use const PHP_INT_MAX;

/**
 * Converts EXIF matrix and chromaticity values.
 *
 * EXIF 3.0 Annex C.3 defines SRATIONAL matrices for DNG ColorMatrix/ForwardMatrix encodings.
 */
final readonly class MatrixConverter
{
    /**
     * EXIF 3.0 Annex C.3: maximum SRATIONAL matrix dimension is 64×64.
     */
    private const int MAX_SRATIONAL_MATRIX_DIMENSION = 64;

    /**
     * EXIF 3.0 Annex C.3 limits SRATIONAL matrix labels to 255 bytes.
     */
    private const int MAX_SRATIONAL_MATRIX_LABEL_LENGTH = 255;

    /**
     * SRATIONAL entries use two signed 32-bit integers per EXIF 3.0 Annex C.3.
     */
    private const int SRATIONAL_VALUE_SIZE = 8;

    /**
     * Creates the converter with its rational dependency.
     *
     * @param RationalConverter $rationalConverter Dependency for rational conversions.
     */
    public function __construct(
        private RationalConverter $rationalConverter,
    ) {
    }

    /**
     * Converts a rational pair into a white point array.
     *
     * EXIF 3.0 §4.6.4 (WhitePoint tag) defines X and Y chromaticity as two-component rational pairs.
     *
     * @param array<int, int|float|string|array<int, int|float|string>>|ExifRationalList|ExifNumericList|null $rational
     *
     * @return array{0:float,1:float}|null
     */
    public function toWhitePoint(ExifRationalList|ExifNumericList|array|null $rational): ?array
    {
        $values = $this->normaliseRationalValues($rational);

        if ($values === null || count($values) !== 2) {
            return null;
        }

        $x = $this->rationalConverter->toFloat($values[0]);
        $y = $this->rationalConverter->toFloat($values[1]);

        if ($x === null || $y === null) {
            return null;
        }

        return [$x, $y];
    }

    /**
     * Converts rational chromaticity pairs into a flat float array.
     *
     * EXIF 3.0 §4.6.4 (PrimaryChromaticities) requires three rational pairs ordered as
     * (RedX, RedY, GreenX, GreenY, BlueX, BlueY).
     *
     * @param array<int, int|float|string|array<int, int|float|string>>|ExifRationalList|ExifNumericList|null $rational
     *
     * @return array{0:float,1:float,2:float,3:float,4:float,5:float}|null
     */
    public function toPrimaryChromaticities(ExifRationalList|ExifNumericList|array|null $rational): ?array
    {
        $values = $this->normaliseRationalValues($rational);

        if ($values === null || count($values) !== 6) {
            return null;
        }

        $result = [];
        foreach (array_slice($values, 0, 6) as $component) {
            $float = $this->rationalConverter->toFloat($component);
            if ($float === null) {
                return null;
            }

            $result[] = $float;
        }

        /** @var array{0:float,1:float,2:float,3:float,4:float,5:float} $result */
        return $result;
    }

    /**
     * Normalises a rational payload into a value list for conversion.
     *
     * @param array<int, int|float|string|array<int, int|float|string>>|ExifRationalList|ExifNumericList|null $rational
     *
     * @return list<array<int, int|float|string>|int|float|ExifRational|UInt64>|null
     */
    private function normaliseRationalValues(ExifRationalList|ExifNumericList|array|null $rational): ?array
    {
        if ($rational === null) {
            return null;
        }

        if ($rational instanceof ExifRationalList || $rational instanceof ExifNumericList) {
            return $rational->values;
        }

        $values = [];
        foreach ($rational as $component) {
            if (is_array($component)) {
                /** @var array<int, int|float|string> $pair */
                $pair     = array_values($component);
                $values[] = $pair;
            } elseif (is_int($component) || is_float($component)) {
                $values[] = $component;
            } else {
                // string type
                if (!is_numeric($component)) {
                    return null;
                }

                $values[] = (float) $component;
            }
        }

        return $values;
    }

    /**
     * Serialises a DNG matrix or CFA pattern into a reproducible string representation.
     *
     * EXIF 3.0 Annex C.3 (SRATIONAL matrices) guidance for DNG ColorMatrix/ForwardMatrix encodings.
     *
     * @param array<int, int|float|string|array<int, int|float|string>>|ExifRationalList|ExifNumericList|null $matrix
     */
    public function dngMatrixToString(ExifRationalList|ExifNumericList|array|null $matrix): ?string
    {
        if ($matrix === null) {
            return null;
        }

        if ($matrix instanceof ExifRationalList || $matrix instanceof ExifNumericList) {
            $raw = $matrix->values;
        } else {
            $raw = [];
            foreach ($matrix as $component) {
                if (is_array($component)) {
                    /** @var array<int, int|float|string> $pair */
                    $pair  = array_values($component);
                    $raw[] = $pair;
                    continue;
                }

                if (is_int($component) || is_float($component)) {
                    $raw[] = $component;
                    continue;
                }

                // string type
                if (!is_numeric($component)) {
                    return null;
                }

                $raw[] = (float) $component;
            }
        }

        if ($raw === []) {
            return null;
        }

        /** @var list<array<int, int|float|string>|int|float|ExifRational> $raw */
        $values = [];
        foreach ($raw as $component) {
            $float = $this->rationalConverter->toFloat($component);
            if ($float === null) {
                return null;
            }

            $values[] = $float;
        }

        try {
            return json_encode($values, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return implode(',', $values);
        }
    }

    /**
     * Decodes the spatial frequency response payload.
     *
     * EXIF 3.0 §4.6.3 (figure 14).
     *
     * @param string|null $payload Raw UNDEFINED payload captured from the EXIF tag.
     *
     * @return array{columns:int, rows:int, labels:array{columns:list<string>, rows:list<string>}, values:list<list<float|null>>}|null
     */
    public function decodeSpatialFrequencyResponse(?string $payload): ?array
    {
        return $this->decodeSrationalMatrix($payload);
    }

    /**
     * Decodes the opto-electronic conversion function payload.
     *
     * EXIF 3.0 §4.6.6.7.6 (figure 16, table 11).
     *
     * @param string|null $payload Raw UNDEFINED payload captured from the EXIF tag.
     *
     * @return array{columns:int, rows:int, labels:array{columns:list<string>, rows:list<string>}, values:list<list<float|null>>}|null
     */
    public function decodeOecf(?string $payload): ?array
    {
        return $this->decodeSrationalMatrix($payload);
    }

    /**
     * Decodes an EXIF SRATIONAL matrix that contains labelled columns and rows.
     *
     * @param string|null $payload Raw UNDEFINED payload captured from the EXIF tag.
     *
     * @return array{columns:int, rows:int, labels:array{columns:list<string>, rows:list<string>}, values:list<list<float|null>>}|null
     */
    private function decodeSrationalMatrix(?string $payload): ?array
    {
        if ($payload === null || $payload === '') {
            return null;
        }

        $length = strlen($payload);
        if ($length < 4) {
            return null;
        }

        $header = @unpack('ncolumns/nrows', substr($payload, 0, 4));
        if (!is_array($header)) {
            return null;
        }

        $columnsRaw = $header['columns'] ?? null;
        $rowsRaw    = $header['rows'] ?? null;
        if (!is_int($columnsRaw) || !is_int($rowsRaw)) {
            return null;
        }

        $columns = $columnsRaw;
        $rows    = $rowsRaw;

        if ($columns <= 0 || $rows <= 0) {
            return null;
        }

        if ($columns > self::MAX_SRATIONAL_MATRIX_DIMENSION || $rows > self::MAX_SRATIONAL_MATRIX_DIMENSION) {
            return null;
        }

        if ($columns > intdiv(PHP_INT_MAX, $rows)) {
            return null;
        }

        $offset       = 4;
        $columnLabels = [];
        for ($i = 0; $i < $columns; ++$i) {
            $labelData = $this->consumeSrationalMatrixLabel($payload, $offset, $length);
            if ($labelData === null) {
                return null;
            }

            [$label, $offset] = $labelData;
            $columnLabels[]   = $label;
        }

        $rowLabels = [];
        for ($i = 0; $i < $rows; ++$i) {
            $labelData = $this->consumeSrationalMatrixLabel($payload, $offset, $length);
            if ($labelData === null) {
                return null;
            }

            [$label, $offset] = $labelData;
            $rowLabels[]      = $label;
        }

        $cells = $columns * $rows;
        if ($cells > intdiv(PHP_INT_MAX, self::SRATIONAL_VALUE_SIZE)) {
            return null;
        }

        $required = $cells * self::SRATIONAL_VALUE_SIZE;
        if ($required > ($length - $offset)) {
            return null;
        }

        $values = [];
        for ($rowIndex = 0; $rowIndex < $rows; ++$rowIndex) {
            $rowValues = [];

            for ($colIndex = 0; $colIndex < $columns; ++$colIndex) {
                $numerator   = $this->readSrationalInt32($payload, $offset, $length);
                $denominator = $this->readSrationalInt32($payload, $offset + 4, $length);
                if ($numerator === null || $denominator === null) {
                    return null;
                }

                $offset += self::SRATIONAL_VALUE_SIZE;

                if ($denominator === 0) {
                    $rowValues[] = null;
                    continue;
                }

                $rowValues[] = (float) $numerator / (float) $denominator;
            }

            $values[] = $rowValues;
        }

        // EXIF 3.0 §4.6.6.7.6/§4.6.6.7.24: the structured payload must be
        // fully consumed; trailing bytes indicate a malformed matrix.
        if ($offset !== $length) {
            return null;
        }

        return [
            'columns' => $columns,
            'rows'    => $rows,
            'labels'  => [
                'columns' => $columnLabels,
                'rows'    => $rowLabels,
            ],
            'values' => $values,
        ];
    }

    /**
     * Extracts a null-terminated label from the SRATIONAL matrix payload.
     *
     * @return array{0:string,1:int}|null
     */
    private function consumeSrationalMatrixLabel(string $payload, int $offset, int $length): ?array
    {
        if ($offset >= $length) {
            return null;
        }

        $end = strpos($payload, "\0", $offset);
        if ($end === false) {
            return null;
        }

        $labelLength = $end - $offset;
        if ($labelLength < 0 || $labelLength > self::MAX_SRATIONAL_MATRIX_LABEL_LENGTH) {
            return null;
        }

        $label  = trim(substr($payload, $offset, $labelLength));
        $offset = $end + 1;

        return [$label, $offset];
    }

    /**
     * Reads a signed 32-bit integer from the SRATIONAL matrix payload.
     */
    private function readSrationalInt32(string $payload, int $offset, int $length): ?int
    {
        if ($offset + 4 > $length) {
            return null;
        }

        $value = @unpack('N', substr($payload, $offset, 4));
        if (!is_array($value)) {
            return null;
        }

        $raw = $value[1] ?? null;
        if (!is_int($raw)) {
            return null;
        }

        $int = $raw;
        if ($int >= BitMask::SIGN_BIT_32) {
            $int -= BitMask::UINT32_BASE;
        }

        return $int;
    }
}
