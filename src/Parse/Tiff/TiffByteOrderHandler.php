<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Tiff;

use MagicSunday\ImageMeta\Core\BinaryReadAccessInterface;
use MagicSunday\ImageMeta\Core\BitMask;
use MagicSunday\ImageMeta\Core\Endian;
use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\Util\UInt64;
use MagicSunday\ImageMeta\Core\Util\Unpack;

use function intdiv;
use function pack;

/**
 * Centralises TIFF byte-order dependent primitive reads and integer byte conversion.
 *
 * TIFF 6.0 §2 defines the byte-order markers and header structure this handler interprets.
 */
final readonly class TiffByteOrderHandler
{
    /**
     * Reads an unsigned 16-bit integer using the provided endianness.
     */
    public function readUint16(BinaryReadAccessInterface $buffer, Endian $endianness): int
    {
        $format = $endianness === Endian::Little ? 'v' : 'n';

        return Unpack::int($format, $buffer->read(2), 'TIFF 16-bit value');
    }

    /**
     * Reads an unsigned 32-bit integer using the provided endianness.
     */
    public function readUint32(BinaryReadAccessInterface $buffer, Endian $endianness): int
    {
        $format = $endianness === Endian::Little ? 'V' : 'N';

        return Unpack::int($format, $buffer->read(4), 'TIFF 32-bit value');
    }

    /**
     * Reads an unsigned 64-bit integer using the provided endianness.
     */
    public function readUint64(BinaryReadAccessInterface $buffer, Endian $endianness): UInt64
    {
        return Unpack::uint64(
            $buffer->read(8),
            $endianness === Endian::Little,
            'TIFF 64-bit value',
        );
    }

    /**
     * Converts an integer value into endianness-aware binary bytes.
     *
     * @param int|UInt64 $value Integer value to convert.
     * @param int        $bytes Target byte length.
     */
    public function uintToBytes(int|UInt64 $value, int $bytes, Endian $endianness): string
    {
        if ($bytes === 4) {
            $intValue = $value instanceof UInt64 ? $value->toInt('Inline 32-bit value') : $value;

            return $endianness === Endian::Little ? pack('V', $intValue) : pack('N', $intValue);
        }

        if ($bytes === 8) {
            if ($value instanceof UInt64) {
                $hi = $value->high();
                $lo = $value->low();
            } else {
                $lo = $value & BitMask::UINT32_MAX;
                $hi = intdiv($value, BitMask::UINT32_BASE);
            }

            return $endianness === Endian::Little ? pack('V2', $lo, $hi) : pack('N2', $hi, $lo);
        }

        throw new ParseError('unsupported integer width for byte conversion', 1939);
    }
}
