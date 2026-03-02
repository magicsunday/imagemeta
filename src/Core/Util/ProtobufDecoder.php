<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Core\Util;

use function count;
use function in_array;
use function ord;
use function strlen;
use function substr;

/**
 * Minimal Protocol Buffers wire-format decoder.
 *
 * Decodes protobuf binary data at the wire level (varints, fixed32/64, length-delimited)
 * without requiring a schema. Used to extract metadata from DJI telemetry streams embedded
 * in ISO BMFF mdat boxes.
 *
 * @phpstan-type ProtobufField array{field: int, wireType: int, value: int|string}
 */
final readonly class ProtobufDecoder
{
    private const int MAX_FIELD_NUMBER = 500;

    /**
     * Reads a protobuf varint at the given offset.
     *
     * @param string $data   Binary data to read from.
     * @param int    $offset Byte offset to start reading.
     *
     * @return array{0: int, 1: int}|null [value, bytesConsumed] or null on truncated input.
     */
    public static function readVarint(string $data, int $offset): ?array
    {
        $result = 0;
        $shift  = 0;
        $len    = strlen($data);

        for ($i = 0; $i < 10; ++$i) {
            if (($offset + $i) >= $len) {
                return null;
            }

            $byte = ord($data[$offset + $i]);
            $result |= ($byte & 0x7F) << $shift;
            $shift += 7;

            if (($byte & 0x80) === 0) {
                return [$result, $i + 1];
            }
        }

        return null;
    }

    /**
     * Parses protobuf fields from a binary blob.
     *
     * @param string   $data   Binary protobuf data.
     * @param int      $offset Starting byte offset.
     * @param int|null $end    Optional end boundary.
     *
     * @return list<ProtobufField>
     */
    public static function parseFields(string $data, int $offset = 0, ?int $end = null): array
    {
        $fields = [];
        $len    = $end ?? strlen($data);

        while ($offset < $len) {
            $tagResult = self::readVarint($data, $offset);
            if ($tagResult === null) {
                break;
            }

            [$tagByte, $consumed] = $tagResult;
            $offset += $consumed;

            $fieldNumber = $tagByte >> 3;
            $wireType    = $tagByte & 0x07;

            if (($fieldNumber === 0) || ($fieldNumber > self::MAX_FIELD_NUMBER)) {
                break;
            }

            $value = match ($wireType) {
                0       => self::consumeVarint($data, $offset),
                1       => self::consumeFixed($data, $offset, 8, $len),
                2       => self::consumeLengthDelimited($data, $offset, $len),
                5       => self::consumeFixed($data, $offset, 4, $len),
                default => null,
            };

            if ($value === null) {
                break;
            }

            [$decodedValue, $offset] = $value;

            $fields[] = [
                'field'    => $fieldNumber,
                'wireType' => $wireType,
                'value'    => $decodedValue,
            ];
        }

        return $fields;
    }

    /**
     * Heuristic check: parsed fields look like valid protobuf (at least 2 fields, no excessive field numbers).
     *
     * @param list<ProtobufField> $fields Parsed fields to validate.
     */
    public static function fieldsCoverBytes(array $fields): bool
    {
        if (count($fields) < 2) {
            return false;
        }

        foreach ($fields as $f) {
            if ($f['field'] > self::MAX_FIELD_NUMBER) {
                return false;
            }

            if (!in_array($f['wireType'], [0, 1, 2, 5], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Prevent instantiation of this static utility class.
     */
    private function __construct()
    {
    }

    /**
     * @return array{0: int, 1: int}|null [value, newOffset]
     */
    private static function consumeVarint(string $data, int $offset): ?array
    {
        $result = self::readVarint($data, $offset);
        if ($result === null) {
            return null;
        }

        return [$result[0], $offset + $result[1]];
    }

    /**
     * @return array{0: string, 1: int}|null [bytes, newOffset]
     */
    private static function consumeFixed(string $data, int $offset, int $size, int $len): ?array
    {
        if (($offset + $size) > $len) {
            return null;
        }

        return [substr($data, $offset, $size), $offset + $size];
    }

    /**
     * @return array{0: string, 1: int}|null [bytes, newOffset]
     */
    private static function consumeLengthDelimited(string $data, int $offset, int $len): ?array
    {
        $lenResult = self::readVarint($data, $offset);
        if ($lenResult === null) {
            return null;
        }

        [$dataLen, $consumed] = $lenResult;
        $offset += $consumed;

        if (($offset + $dataLen) > $len) {
            return null;
        }

        $value = substr($data, $offset, $dataLen);

        return [$value, $offset + $dataLen];
    }
}
