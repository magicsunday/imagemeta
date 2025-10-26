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
     * @return int
     */
    public static function combineUint32(int $hi, int $lo): int
    {
        if (PHP_INT_SIZE < 8) {
            throw new ParseError('64-bit integers are not supported on this platform.');
        }

        $hiUnsigned = $hi & 0xFFFFFFFF;
        $loUnsigned = $lo & 0xFFFFFFFF;

        $isNegative = ($hiUnsigned & 0x80000000) !== 0;

        if ($isNegative) {
            $hiComplement = (~$hiUnsigned) & 0xFFFFFFFF;
            $loComplement = (~$loUnsigned) & 0xFFFFFFFF;
            $magnitude    = ($hiComplement << 32) | $loComplement;

            return -1 - $magnitude;
        }

        $maxHi = PHP_INT_MAX >> 32;
        $maxLo = PHP_INT_MAX & 0xFFFFFFFF;

        if ($hiUnsigned > $maxHi || ($hiUnsigned === $maxHi && $loUnsigned > $maxLo)) {
            throw new ParseError('64-bit unsigned value exceeds PHP_INT_MAX.');
        }

        return ($hiUnsigned << 32) | $loUnsigned;
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
        $result = unpack($format, $bytes);

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
