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
use ValueError;

use function is_float;
use function is_int;
use function strlen;
use function unpack;

/**
 * Provides helpers for unpacking numeric values from binary data.
 */
final class Unpack
{
    private function __construct()
    {
    }

    /**
     * Unpacks a value from the provided bytes and returns it as an integer.
     *
     * @param string $format  Format accepted by {@see unpack}.
     * @param string $bytes   Bytes to unpack the value from.
     * @param string $context Human-readable description used in error messages.
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
     */
    public static function float(string $format, string $bytes, string $context): float
    {
        return (float) self::numeric($format, $bytes, $context);
    }

    /**
     * Unpacks an unsigned 64-bit integer into a {@see UInt64} instance.
     *
     * @param string $bytes        Raw bytes to unpack.
     * @param bool   $littleEndian Whether the bytes use little-endian order.
     * @param string $context      Human-readable description used in error messages.
     */
    public static function uint64(string $bytes, bool $littleEndian, string $context): UInt64
    {
        $format = $littleEndian ? 'V2' : 'N2';

        if (strlen($bytes) !== 8) {
            $parts = false;
        } else {
            try {
                $parts = unpack($format, $bytes);
            } catch (ValueError) {
                $parts = false;
            }
        }

        if ($parts === false || !isset($parts[1], $parts[2])) {
            throw new ParseError('Failed to unpack ' . $context . '.', 1027);
        }

        $first  = $parts[1];
        $second = $parts[2];

        if ((!is_int($first) && !is_float($first)) || (!is_int($second) && !is_float($second))) {
            throw new ParseError('Unpacked ' . $context . ' is not numeric.', 1028);
        }

        if ($littleEndian) {
            $lo = (int) $first;
            $hi = (int) $second;
        } else {
            $hi = (int) $first;
            $lo = (int) $second;
        }

        return UInt64::fromUInt32($hi, $lo);
    }

    /**
     * Unpacks a numeric value using {@see unpack} while validating the result.
     *
     * @param string $format  Format accepted by {@see unpack}.
     * @param string $bytes   Bytes to unpack the value from.
     * @param string $context Human-readable description used in error messages.
     */
    private static function numeric(string $format, string $bytes, string $context): int|float
    {
        try {
            $result = unpack($format, $bytes);
        } catch (ValueError) {
            $result = false;
        }

        if ($result === false || !isset($result[1])) {
            throw new ParseError('Failed to unpack ' . $context . '.', 1029);
        }

        $value = $result[1];

        if (!is_int($value) && !is_float($value)) {
            throw new ParseError('Unpacked ' . $context . ' is not numeric.', 1030);
        }

        return $value;
    }
}
