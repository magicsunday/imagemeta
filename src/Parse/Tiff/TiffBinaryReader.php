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
use MagicSunday\ImageMeta\Core\Util\UInt64;
use MagicSunday\ImageMeta\Core\Util\Unpack;

use function substr;

/**
 * Low-level binary I/O for TIFF structures with endian-aware reading and unpacking.
 *
 * TIFF 6.0 §2 defines the byte-order conventions and field widths this reader implements.
 */
final readonly class TiffBinaryReader
{
    /**
     * @param BinaryReadAccessInterface $buffer            Seekable binary source.
     * @param Endian                    $bo                Byte order (Little/Big endian).
     * @param TiffByteOrderHandler      $byteOrderHandler  Endian-aware primitive I/O.
     * @param bool                      $bigTiff           Whether this is a BigTIFF structure.
     * @param int                       $bigTiffOffsetSize BigTIFF offset field width.
     */
    public function __construct(
        private BinaryReadAccessInterface $buffer,
        private Endian $bo,
        private TiffByteOrderHandler $byteOrderHandler,
        private bool $bigTiff,
        private int $bigTiffOffsetSize,
    ) {
    }

    /**
     * Reads an unsigned 16-bit integer using the file byte order.
     */
    public function readU16(): int
    {
        return $this->byteOrderHandler->readUint16($this->buffer, $this->bo);
    }

    /**
     * Reads an unsigned 32-bit integer using the file byte order.
     */
    public function readU32(): int
    {
        return $this->byteOrderHandler->readUint32($this->buffer, $this->bo);
    }

    /**
     * Reads an unsigned 64-bit integer using the file byte order.
     */
    public function readU64(): UInt64
    {
        return $this->byteOrderHandler->readUint64($this->buffer, $this->bo);
    }

    /**
     * Reads the 4- or 8-byte value/offset field for a directory entry.
     *
     * @param int $valueBytes Total byte size of the entry value.
     *
     * @return array{0: int|UInt64|string, 1: string|null}
     */
    public function readValueOrOffset(int $valueBytes): array
    {
        $inlineThreshold = $this->bigTiff ? $this->bigTiffOffsetSize : 4;

        if ($valueBytes <= $inlineThreshold) {
            $rawField    = $this->buffer->read($inlineThreshold);
            $inlineBytes = $valueBytes === $inlineThreshold
                ? $rawField
                : substr($rawField, 0, $valueBytes);

            return [$inlineBytes, $inlineBytes];
        }

        if ($this->bigTiff) {
            return [$this->readU64(), null];
        }

        return [$this->readU32(), null];
    }

    /**
     * Reads bytes at a given offset without changing the permanent buffer position.
     *
     * @param int $offset Byte offset to read from.
     * @param int $length Number of bytes to read.
     */
    public function readAt(int $offset, int $length): string
    {
        $current = $this->buffer->tell();
        $this->buffer->seek($offset);
        $bytes = $this->buffer->read($length);
        $this->buffer->seek($current);

        return $bytes;
    }

    /**
     * Returns the configured byte order.
     */
    public function byteOrder(): Endian
    {
        return $this->bo;
    }

    /**
     * Unpacks an unsigned 16-bit integer from a byte string.
     *
     * @param string $b Source bytes.
     */
    public function unpackU16(string $b): int
    {
        $format = $this->bo === Endian::Little ? 'v' : 'n';

        return Unpack::int($format, $b, '16-bit value from TIFF bytes');
    }

    /**
     * Unpacks a signed 16-bit integer from a byte string.
     *
     * @param string $b Source bytes.
     */
    public function unpackS16(string $b): int
    {
        $u = $this->unpackU16($b);

        return $u >= BitMask::SIGN_BIT_16 ? $u - BitMask::UINT16_BASE : $u;
    }

    /**
     * Unpacks an unsigned 32-bit integer from a byte string.
     *
     * @param string $b Source bytes.
     */
    public function unpackU32(string $b): int
    {
        $format = $this->bo === Endian::Little ? 'V' : 'N';

        return Unpack::int($format, $b, '32-bit value from TIFF bytes');
    }

    /**
     * Unpacks a signed 32-bit integer from a byte string.
     *
     * @param string $b Source bytes.
     */
    public function unpackS32(string $b): int
    {
        $u = $this->unpackU32($b);

        return (($u & BitMask::SIGN_BIT_32) !== 0) ? -((~$u & BitMask::UINT32_MAX) + 1) : $u;
    }

    /**
     * Unpacks an IEEE-754 single-precision float from a byte string.
     *
     * @param string $b Source bytes.
     */
    public function unpackFloat(string $b): float
    {
        $format = $this->bo === Endian::Little ? 'g' : 'G';

        return Unpack::float($format, $b, '32-bit float from TIFF bytes');
    }

    /**
     * Unpacks an IEEE-754 double-precision float from a byte string.
     *
     * @param string $b Source bytes.
     */
    public function unpackDouble(string $b): float
    {
        $format = $this->bo === Endian::Little ? 'e' : 'E';

        return Unpack::float($format, $b, '64-bit float from TIFF bytes');
    }

    /**
     * Unpacks an unsigned 64-bit integer from a byte string.
     *
     * @param string $b Source bytes.
     */
    public function unpackU64(string $b): UInt64
    {
        return Unpack::uint64($b, $this->bo === Endian::Little, '64-bit value from TIFF bytes');
    }

    /**
     * Unpacks a signed 64-bit integer from a byte string.
     *
     * @param string $b Source bytes.
     */
    public function unpackS64(string $b): int
    {
        $unsigned = $this->unpackU64($b);
        $hi       = $unsigned->high();
        $lo       = $unsigned->low();

        if (($hi & BitMask::SIGN_BIT_32) === 0) {
            return $unsigned->toInt('Signed 64-bit integer');
        }

        $hiComplement = (~$hi) & BitMask::UINT32_MAX;
        $loComplement = (~$lo) & BitMask::UINT32_MAX;

        $magnitude = UInt64::fromUInt32($hiComplement, $loComplement)
            ->addSmall(1)
            ->toInt('Signed 64-bit integer magnitude');

        return -$magnitude;
    }

    /**
     * Converts an integer into a byte string respecting the configured endianness.
     *
     * @param int|UInt64 $v     Integer value to convert.
     * @param int        $bytes Number of bytes to output.
     */
    public function uXToBytes(int|UInt64 $v, int $bytes): string
    {
        return $this->byteOrderHandler->uintToBytes($v, $bytes, $this->bo);
    }

    /**
     * Reads a 32-bit integer from a byte buffer using the configured endianness.
     *
     * @param string $bytes  Source buffer containing the integer.
     * @param int    $offset Byte offset within the buffer.
     * @param bool   $signed Whether to interpret the value as signed.
     */
    public function read32FromBytes(string $bytes, int $offset, bool $signed): int
    {
        $chunk = substr($bytes, $offset, 4);

        return $signed ? $this->unpackS32($chunk) : $this->unpackU32($chunk);
    }

    /**
     * Converts an unsigned integer to its signed representation for the given width.
     *
     * @param int $u    Unsigned integer value.
     * @param int $bits Bit width of the target signed representation.
     */
    public function toSigned(int $u, int $bits): int
    {
        $sign = 1 << ($bits - 1);

        return (($u & $sign) !== 0) ? $u - (1 << $bits) : $u;
    }
}
