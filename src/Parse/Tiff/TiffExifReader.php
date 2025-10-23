<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Tiff;

use MagicSunday\ImageMeta\Core\Endian;
use MagicSunday\ImageMeta\Core\MemoryBuffer;
use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Model\Exif\ExifDocument;
use MagicSunday\ImageMeta\Model\Exif\Ifd;
use MagicSunday\ImageMeta\Model\Exif\IfdEntry;

/**
 * Parses classic TIFF and BigTIFF structures embedded in EXIF payloads.
 */
final class TiffExifReader
{
    private MemoryBuffer $buf;
    private Endian $bo;
    private bool $bigTiff = false;

    /**
     * Parses an EXIF TIFF blob into a structured document model.
     *
     * @param string $tiffBlob Raw TIFF data including headers.
     *
     * @return ExifDocument
     */
    public function parseFromBlob(string $tiffBlob): ExifDocument
    {
        $this->buf = new MemoryBuffer($tiffBlob);
        $this->buf->seek(0);

        // byte order
        $boSig    = $this->buf->read(2);
        $this->bo = match ($boSig) {
            'II'    => Endian::Little,
            'MM'    => Endian::Big,
            default => throw new ParseError('Bad TIFF byte order'),
        };

        $magic = $this->readU16();
        if ($magic === 0x2B) {
            $this->bigTiff = true;
            $this->parseBigTiffHeader();
            $firstIfd = $this->readU64();
            $ifd0     = $this->readIfd($firstIfd);
        } elseif ($magic === 0x2A) {
            $this->bigTiff = false;
            $firstIfd      = $this->readU32();
            $ifd0          = $this->readIfd($firstIfd);
        } else {
            throw new ParseError('Unknown TIFF magic (expected 0x002A or 0x002B)');
        }

        // follow pointers
        $exifIfd    = null;
        $gpsIfd     = null;
        $interopIfd = null;
        $ifd1       = null;
        if ($e = $ifd0->get(0x8769)) { // ExifIFDPointer
            $exifIfd = $this->readIfd((int) $e->value);
            if ($e2 = $exifIfd->get(0xA005)) { // Interop IFD
                $interopIfd = $this->readIfd((int) $e2->value);
            }
        }
        if ($g = $ifd0->get(0x8825)) { // GPSInfoIFDPointer
            $gpsIfd = $this->readIfd((int) $g->value);
        }
        if ($ifd0->nextIfdOffset) {
            $ifd1 = $this->readIfd($ifd0->nextIfdOffset);
        }

        return new ExifDocument($ifd0, $exifIfd, $gpsIfd, $interopIfd, $ifd1);
    }

    /**
     * Validates the BigTIFF header following the magic identifier.
     */
    private function parseBigTiffHeader(): void
    {
        // BigTIFF header after magic: 2 bytes: offset size (should be 8), 2 bytes: zero/reserved, then 8‑byte first IFD offset
        $offSize = $this->readU16();
        $zero    = $this->readU16();
        if ($offSize !== 8) {
            throw new ParseError('Unsupported BigTIFF offset size (expected 8)');
        }
        // $zero is usually 0; keep reading first IFD via caller
    }

    /**
     * Parses an image file directory starting at the given byte offset.
     *
     * @param int $offset Zero-based byte offset to the IFD structure.
     *
     * @return Ifd
     */
    private function readIfd(int $offset): Ifd
    {
        if ($offset <= 0) {
            return new Ifd([]);
        }
        $this->buf->seek($offset);
        $entryCount = $this->bigTiff ? $this->readU64() : $this->readU16();
        $entries    = [];
        for ($i = 0; $i < $entryCount; ++$i) {
            $entries += $this->readDirEntry();
        }
        $next = $this->bigTiff ? $this->readU64() : $this->readU32();

        return new Ifd($entries, $next > 0 ? $next : null);
    }

    /**
     * Reads a single directory entry and returns it keyed by tag identifier.
     *
     * @return array<int, IfdEntry> tagId => entry
     */
    private function readDirEntry(): array
    {
        $tag      = $this->readU16();
        $type     = $this->readU16();
        $cnt      = $this->bigTiff ? $this->readU64() : $this->readU32();
        $valOrOff = $this->bigTiff ? $this->readU64() : $this->readU32();

        $value = $this->decodeValue($type, $cnt, $valOrOff);

        return [$tag => new IfdEntry($tag, $type, (int) $cnt, $value)];
    }

    /**
     * Decodes the value referenced by a directory entry.
     *
     * @param int $type          TIFF field type code.
     * @param int $count         Number of values represented.
     * @param int $valueOrOffset Inline value bytes or an offset into the blob.
     *
     * @return mixed
     */
    private function decodeValue(int $type, int $count, int $valueOrOffset): mixed
    {
        $unitSize = match ($type) {
            1, 2, 6, 7 => 1,           // BYTE, ASCII, SBYTE, UNDEFINED
            3, 8 => 2,           // SHORT, SSHORT
            4, 9 => 4,           // LONG, SLONG
            5, 10 => 8,           // RATIONAL, SRATIONAL (two LONGs)
            11      => 4,           // FLOAT
            12      => 8,           // DOUBLE
            default => throw new ParseError("Unsupported TIFF type: $type"),
        };
        $dataSize        = $unitSize * $count;
        $inlineThreshold = $this->bigTiff ? 8 : 4;

        if ($dataSize <= $inlineThreshold) {
            // valueOrOffset is inline value area
            $raw   = $this->uXToBytes($valueOrOffset, $inlineThreshold);
            $bytes = substr($raw, 0, $dataSize);

            return $this->decodeBytes($type, $count, $bytes);
        }

        // valueOrOffset is an offset into the TIFF blob
        $off = $valueOrOffset;
        $cur = $this->buf->tell();
        $this->buf->seek($off);
        $bytes = $this->buf->read($dataSize);
        $this->buf->seek($cur);

        return $this->decodeBytes($type, $count, $bytes);
    }

    /**
     * Converts raw bytes into PHP scalar values based on the TIFF type.
     *
     * @param int    $type  TIFF field type code.
     * @param int    $count Number of values represented.
     * @param string $bytes Raw value bytes read from the blob.
     *
     * @return mixed
     */
    private function decodeBytes(int $type, int $count, string $bytes): mixed
    {
        if ($type === 2) { // ASCII
            return rtrim($bytes, "\0");
        }
        if ($type === 5 || $type === 10) { // RATIONAL / SRATIONAL
            $out = [];
            for ($i = 0; $i < $count; ++$i) {
                $num   = $this->read32FromBytes($bytes, $i * 8 + 0, $type === 10);
                $den   = $this->read32FromBytes($bytes, $i * 8 + 4, $type === 10);
                $out[] = [$num, $den];
            }

            return $count === 1 ? $out[0] : $out;
        }

        $vals   = [];
        $cursor = 0;
        for ($i = 0; $i < $count; ++$i) {
            $vals[] = match ($type) {
                1       => ord($bytes[$cursor++]),                              // BYTE
                6       => self::toSigned(ord($bytes[$cursor++]), 8),          // SBYTE
                7       => ord($bytes[$cursor++]),                              // UNDEFINED → return as byte
                3       => $this->unpackU16(substr($bytes, $cursor, 2)),        // SHORT
                8       => $this->unpackS16(substr($bytes, $cursor, 2)),        // SSHORT
                4       => $this->unpackU32(substr($bytes, $cursor, 4)),        // LONG
                9       => $this->unpackS32(substr($bytes, $cursor, 4)),        // SLONG
                11      => $this->unpackFloat(substr($bytes, $cursor, 4)),     // FLOAT
                12      => $this->unpackDouble(substr($bytes, $cursor, 8)),    // DOUBLE
                default => throw new ParseError("Unsupported type in decodeBytes: $type"),
            };
            $cursor += match ($type) {
                1,6,7 => 1,
                3,8 => 2,
                4,9,11 => 4,
                12      => 8,
                default => 0,
            };
        }

        return $count === 1 ? $vals[0] : $vals;
    }

    /**
     * Reads an unsigned 16-bit integer using the file byte order.
     *
     * @return int
     */
    private function readU16(): int
    {
        return $this->bo === Endian::Little ? $this->buf->readU16LE() : $this->buf->readU16BE();
    }

    /**
     * Reads an unsigned 32-bit integer using the file byte order.
     *
     * @return int
     */
    private function readU32(): int
    {
        return $this->bo === Endian::Little ? $this->buf->readU32LE() : $this->buf->readU32BE();
    }

    /**
     * Reads an unsigned 64-bit integer using the file byte order.
     *
     * @return int
     */
    private function readU64(): int
    {
        return $this->bo === Endian::Little ? $this->buf->readU64LE() : $this->buf->readU64BE();
    }

    /**
     * Converts an integer into a byte string respecting the configured endianness.
     *
     * @param int $v     Integer value to convert.
     * @param int $bytes Number of bytes to output.
     *
     * @return string
     */
    private function uXToBytes(int $v, int $bytes): string
    {
        // Convert integer to a byte string of specific length using current endianness
        if ($bytes === 4) {
            return $this->bo === Endian::Little ? pack('V', $v) : pack('N', $v);
        }
        if ($bytes === 8) {
            $hi = ($v >> 32) & 0xFFFFFFFF;
            $lo = $v & 0xFFFFFFFF;

            return $this->bo === Endian::Little ? pack('V2', $lo, $hi) : pack('N2', $hi, $lo);
        }
        // fallback (shouldn't happen here)
        $bin = '';
        for ($i = 0; $i < $bytes; ++$i) {
            $bin = chr(($v >> ($this->bo === Endian::Little ? ($i * 8) : (($bytes - 1 - $i) * 8))) & 0xFF) . $bin;
        }

        return $bin;
    }

    /**
     * Reads a 32-bit integer from a byte buffer using the configured endianness.
     *
     * @param string $bytes  Source buffer containing the integer.
     * @param int    $offset Byte offset within the buffer.
     * @param bool   $signed Whether to interpret the value as signed.
     *
     * @return int
     */
    private function read32FromBytes(string $bytes, int $offset, bool $signed): int
    {
        $chunk = substr($bytes, $offset, 4);

        return $signed ? $this->unpackS32($chunk) : $this->unpackU32($chunk);
    }

    /**
     * Unpacks an unsigned 16-bit integer from a byte string.
     *
     * @param string $b Source bytes.
     *
     * @return int
     */
    private function unpackU16(string $b): int
    {
        return $this->bo === Endian::Little ? unpack('v', $b)[1] : unpack('n', $b)[1];
    }

    /**
     * Unpacks a signed 16-bit integer from a byte string.
     *
     * @param string $b Source bytes.
     *
     * @return int
     */
    private function unpackS16(string $b): int
    {
        $u = $this->unpackU16($b);

        return $u >= 0x8000 ? $u - 0x10000 : $u;
    }

    /**
     * Unpacks an unsigned 32-bit integer from a byte string.
     *
     * @param string $b Source bytes.
     *
     * @return int
     */
    private function unpackU32(string $b): int
    {
        return $this->bo === Endian::Little ? unpack('V', $b)[1] : unpack('N', $b)[1];
    }

    /**
     * Unpacks a signed 32-bit integer from a byte string.
     *
     * @param string $b Source bytes.
     *
     * @return int
     */
    private function unpackS32(string $b): int
    {
        $u = $this->unpackU32($b);

        return ($u & 0x80000000) ? -((~$u & 0xFFFFFFFF) + 1) : $u;
    }

    /**
     * Unpacks an IEEE-754 single-precision float from a byte string.
     *
     * @param string $b Source bytes.
     *
     * @return float
     */
    private function unpackFloat(string $b): float
    {
        return (float) ($this->bo === Endian::Little ? unpack('g', $b)[1] : unpack('G', $b)[1]);
    }

    /**
     * Unpacks an IEEE-754 double-precision float from a byte string.
     *
     * @param string $b Source bytes.
     *
     * @return float
     */
    private function unpackDouble(string $b): float
    {
        return (float) ($this->bo === Endian::Little ? unpack('e', $b)[1] : unpack('E', $b)[1]);
    }

    /**
     * Converts an unsigned integer to its signed representation for the given width.
     *
     * @param int $u    Unsigned integer value.
     * @param int $bits Bit width of the target signed representation.
     *
     * @return int
     */
    private static function toSigned(int $u, int $bits): int
    {
        $sign = 1 << ($bits - 1);

        return ($u & $sign) ? $u - (1 << $bits) : $u;
    }
}
