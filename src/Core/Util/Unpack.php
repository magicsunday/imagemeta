<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Core\Util;

use MagicSunday\ImageMeta\Core\ParseError;

use function is_float;
use function is_int;
use function unpack;

/**
 * Provides helpers for unpacking numeric values from binary data.
 */
final class Unpack
{
    /**
     * Unpacks a value from the provided bytes and returns it as an integer.
     *
     * @param string $format  Format accepted by {@see unpack}.
     * @param string $bytes   Bytes to unpack the value from.
     * @param string $context Human-readable description used in error messages.
     *
     * @return int
     */
    public static function int(string $format, string $bytes, string $context): int
    {
        return (int) self::numeric($format, $bytes, $context);
    }

    /**
     * Unpacks a value from the provided bytes and returns it as a float.
     *
     * @param string $format  Format accepted by {@see unpack}.
     * @param string $bytes   Bytes to unpack the value from.
     * @param string $context Human-readable description used in error messages.
     *
     * @return float
     */
    public static function float(string $format, string $bytes, string $context): float
    {
        return (float) self::numeric($format, $bytes, $context);
    }

    /**
     * Combines two 32-bit unsigned integers into a single 64-bit value.
     *
     * @param int $hi High-order 32 bits.
     * @param int $lo Low-order 32 bits.
     *
     * @return UInt64
     */
    public static function combineUint32(int $hi, int $lo): UInt64
    {
        return UInt64::fromUInt32($hi, $lo);
    }

    /**
     * Unpacks an unsigned 64-bit integer into a {@see UInt64} instance.
     *
     * @param string $bytes        Raw bytes to unpack.
     * @param bool   $littleEndian Whether the bytes use little-endian order.
     * @param string $context      Human-readable description used in error messages.
     *
     * @return UInt64
     */
    public static function uint64(string $bytes, bool $littleEndian, string $context): UInt64
    {
        $format = $littleEndian ? 'V2' : 'N2';
        $parts  = @unpack($format, $bytes);

        if ($parts === false || !isset($parts[1], $parts[2])) {
            throw new ParseError('Failed to unpack ' . $context . '.');
        }

        $first  = $parts[1];
        $second = $parts[2];

        if ((!is_int($first) && !is_float($first)) || (!is_int($second) && !is_float($second))) {
            throw new ParseError('Unpacked ' . $context . ' is not numeric.');
        }

        if ($littleEndian) {
            $lo = (int) $first;
            $hi = (int) $second;
        } else {
            $hi = (int) $first;
            $lo = (int) $second;
        }

        return self::combineUint32($hi, $lo);
    }

    /**
     * Unpacks a numeric value using {@see unpack} while validating the result.
     *
     * @param string $format  Format accepted by {@see unpack}.
     * @param string $bytes   Bytes to unpack the value from.
     * @param string $context Human-readable description used in error messages.
     *
     * @return int|float
     */
    private static function numeric(string $format, string $bytes, string $context): int|float
    {
        $result = @unpack($format, $bytes);

        if ($result === false || !isset($result[1])) {
            throw new ParseError('Failed to unpack ' . $context . '.');
        }

        $value = $result[1];
        if (!is_int($value) && !is_float($value)) {
            throw new ParseError('Unpacked ' . $context . ' is not numeric.');
        }

        return $value;
    }
}
