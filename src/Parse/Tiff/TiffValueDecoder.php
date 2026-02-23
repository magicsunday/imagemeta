<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Tiff;

use MagicSunday\ImageMeta\Core\BitMask;
use MagicSunday\ImageMeta\Core\BoundsError;
use MagicSunday\ImageMeta\Core\Endian;
use MagicSunday\ImageMeta\Core\MemoryBuffer;
use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\Util\UInt64;
use MagicSunday\ImageMeta\Core\Util\Unpack;
use MagicSunday\ImageMeta\Exif\Model\ExifNumericList;
use MagicSunday\ImageMeta\Exif\Model\ExifRational;
use MagicSunday\ImageMeta\Exif\Model\ExifRationalList;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Model\Dng\DngTag;
use MagicSunday\ImageMeta\Model\Tiff\TiffTag;

use function in_array;
use function intdiv;
use function is_string;
use function ltrim;
use function mb_check_encoding;
use function ord;
use function rtrim;
use function sprintf;
use function strlen;
use function strspn;
use function substr;

/**
 * Low-level binary I/O, TIFF value decoding, and offset validation.
 *
 * TIFF 6.0 §2.2 defines the field type encodings mapped to PHP scalars here.
 * EXIF 3.0 §4.5.2 mirrors these definitions with additional EXIF context.
 */
final readonly class TiffValueDecoder
{
    /**
     * Tag identifiers that store counted image data such as strips or tiles.
     *
     * @var list<int>
     */
    private const array COUNTED_IMAGE_DATA_TAGS = [
        ExifTag::STRIP_OFFSETS,
        ExifTag::STRIP_BYTE_COUNTS,
        TiffTag::TILE_OFFSETS,
        TiffTag::TILE_BYTE_COUNTS,
    ];

    /**
     * Unsigned integer TIFF field types accepted for strip/tile offset and byte-count tags.
     *
     * @var list<int>
     */
    private const array COUNTED_IMAGE_DATA_INTEGER_TYPES = [
        TiffConst::TYPE_SHORT,
        TiffConst::TYPE_LONG,
        TiffConst::TYPE_LONG8,
    ];

    /**
     * Tags whose values encode offsets within the TIFF blob.
     *
     * @var list<int>
     */
    private const array POINTER_TAGS = [
        ExifTag::EXIF_IFD_POINTER,
        ExifTag::GPS_IFD_POINTER,
        ExifTag::INTEROPERABILITY_IFD_POINTER,
        ExifTag::JPEG_INTERCHANGE_FORMAT,
    ];

    /**
     * EXIF 3.0 text tags that allow UTF-8 in addition to ASCII.
     *
     * @var list<int>
     */
    private const array EXIF_30_UTF8_TAGS = [
        ExifTag::SOFTWARE,
        ExifTag::ARTIST,
        ExifTag::CAMERA_OWNER_NAME,
        ExifTag::PHOTOGRAPHER,
        ExifTag::IMAGE_EDITOR,
        ExifTag::CAMERA_FIRMWARE,
        ExifTag::RAW_DEVELOPING_SOFTWARE,
        ExifTag::IMAGE_EDITING_SOFTWARE,
        ExifTag::METADATA_EDITING_SOFTWARE,
        DngTag::CAMERA_CALIBRATION_SIGNATURE,
        DngTag::PROFILE_CALIBRATION_SIGNATURE,
        DngTag::AS_SHOT_PROFILE_NAME,
        DngTag::PROFILE_COPYRIGHT,
        DngTag::ORIGINAL_RAW_FILE_NAME,
        DngTag::PREVIEW_APPLICATION_NAME,
        DngTag::PREVIEW_APPLICATION_VERSION,
        DngTag::PREVIEW_SETTINGS_NAME,
    ];

    /**
     * DNG tags that use ASCII or BYTE type with NUL-terminated UTF-8 semantics.
     *
     * @var list<int>
     */
    private const array DNG_UTF8_STRING_TAGS = [
        DngTag::CAMERA_CALIBRATION_SIGNATURE,
        DngTag::PROFILE_CALIBRATION_SIGNATURE,
        DngTag::AS_SHOT_PROFILE_NAME,
        DngTag::PROFILE_COPYRIGHT,
        DngTag::ORIGINAL_RAW_FILE_NAME,
        DngTag::PREVIEW_APPLICATION_NAME,
        DngTag::PREVIEW_APPLICATION_VERSION,
        DngTag::PREVIEW_SETTINGS_NAME,
    ];

    /**
     * @param MemoryBuffer         $buffer            Seekable binary buffer.
     * @param Endian               $bo                Byte order (Little/Big endian).
     * @param TiffByteOrderHandler $byteOrderHandler  Endian-aware primitive I/O.
     * @param ExifTagDecoder       $tagDecoder        ASCII/text decoder.
     * @param bool                 $bigTiff           Whether this is a BigTIFF structure.
     * @param int                  $bigTiffOffsetSize BigTIFF offset field width.
     * @param UInt64               $blobSize          Total blob size for bounds checks.
     */
    public function __construct(
        private MemoryBuffer $buffer,
        private Endian $bo,
        private TiffByteOrderHandler $byteOrderHandler,
        private ExifTagDecoder $tagDecoder,
        private bool $bigTiff,
        private int $bigTiffOffsetSize,
        private UInt64 $blobSize,
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
     * @param int $type  TIFF field type code.
     * @param int $count Number of values represented.
     *
     * @return array{0:int|UInt64|string,1:string|null}
     */
    public function readValueOrOffset(int $type, int $count): array
    {
        $componentSize   = $this->bytesPerComponent($type);
        $inlineThreshold = $this->bigTiff ? $this->bigTiffOffsetSize : 4;
        $valueBytes      = $this->safeValueByteCount($componentSize, $count);

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
     * Extracts the raw bytes addressed by a directory entry.
     *
     * @param int               $type          TIFF field type code.
     * @param int               $count         Number of values represented.
     * @param int|UInt64|string $valueOrOffset Inline value bytes or an offset into the blob.
     * @param string|null       $inlineBytes   Raw bytes captured from the value/offset field.
     *
     * @return array{0: string, 1: int|null}
     */
    public function valueBytes(int $type, int $count, int|UInt64|string $valueOrOffset, ?string $inlineBytes = null): array
    {
        $unitSize        = $this->bytesPerComponent($type);
        $dataSize        = $this->safeValueByteCount($unitSize, $count);
        $inlineThreshold = $this->bigTiff ? 8 : 4;

        if ($inlineBytes !== null) {
            if (strlen($inlineBytes) < $dataSize) {
                throw new ParseError(
                    sprintf(
                        'Inline value for TIFF type %d truncated (expected %d bytes, got %d)',
                        $type,
                        $dataSize,
                        strlen($inlineBytes),
                    ),
                    1336,
                );
            }

            return [substr($inlineBytes, 0, $dataSize), null];
        }

        if ($dataSize <= $inlineThreshold) {
            if (is_string($valueOrOffset)) {
                if (strlen($valueOrOffset) < $dataSize) {
                    throw new ParseError(
                        sprintf(
                            'Inline value for TIFF type %d truncated (expected %d bytes, got %d)',
                            $type,
                            $dataSize,
                            strlen($valueOrOffset),
                        ),
                        1337,
                    );
                }

                return [substr($valueOrOffset, 0, $dataSize), null];
            }

            $raw = $this->uXToBytes($valueOrOffset, $inlineThreshold);

            return [substr($raw, 0, $dataSize), null];
        }

        $offset  = $this->ensureOffset($valueOrOffset, sprintf('Value offset for TIFF type %d', $type), $dataSize);
        $current = $this->buffer->tell();
        $this->buffer->seek($offset);
        $bytes = $this->buffer->read($dataSize);
        $this->buffer->seek($current);

        return [$bytes, $offset];
    }

    /**
     * Converts raw bytes into PHP scalar values based on the TIFF type.
     *
     * @param int    $tag   Tag identifier for encoding-specific rules.
     * @param int    $type  TIFF field type code.
     * @param int    $count Number of values represented.
     * @param string $bytes Raw value bytes read from the blob.
     *
     * @return int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64
     */
    public function decodeBytes(int $tag, int $type, int $count, string $bytes): int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64
    {
        $componentSize = $this->bytesPerComponent($type);
        $bytesLength   = strlen($bytes);
        $expectedBytes = $this->safeValueByteCount($componentSize, $count);

        if ($bytesLength < $expectedBytes) {
            throw new ParseError(
                sprintf(
                    'Truncated value for TIFF type %d (expected %d bytes, got %d)',
                    $type,
                    $expectedBytes,
                    $bytesLength,
                ),
                1328,
            );
        }

        // ASCII
        if ($type === TiffConst::TYPE_ASCII) {
            return $this->tagDecoder->decodeAscii($tag, $count, $bytes, self::EXIF_30_UTF8_TAGS);
        }

        if ($type === TiffConst::TYPE_UNDEFINED) {
            return $bytes;
        }

        // RATIONAL / SRATIONAL
        if ($type === TiffConst::TYPE_RATIONAL || $type === TiffConst::TYPE_SRATIONAL) {
            $rationalValues = [];
            for ($i = 0; $i < $count; ++$i) {
                $num              = $this->read32FromBytes($bytes, $i * 8, $type === TiffConst::TYPE_SRATIONAL);
                $den              = $this->read32FromBytes($bytes, $i * 8 + 4, $type === TiffConst::TYPE_SRATIONAL);
                $rationalValues[] = new ExifRational($num, $den);
            }

            return $count === 1
                ? $rationalValues[0]
                : new ExifRationalList($rationalValues);
        }

        $vals   = [];
        $cursor = 0;
        for ($i = 0; $i < $count; ++$i) {
            $vals[] = match ($type) {
                TiffConst::TYPE_BYTE   => ord($bytes[$cursor]),
                TiffConst::TYPE_SBYTE  => $this->toSigned(ord($bytes[$cursor]), 8),
                TiffConst::TYPE_SHORT  => $this->unpackU16(substr($bytes, $cursor, 2)),
                TiffConst::TYPE_SSHORT => $this->unpackS16(substr($bytes, $cursor, 2)),
                TiffConst::TYPE_LONG, TiffConst::TYPE_IFD => $this->unpackU32(substr($bytes, $cursor, 4)),
                TiffConst::TYPE_SLONG => $this->unpackS32(substr($bytes, $cursor, 4)),
                TiffConst::TYPE_LONG8, TiffConst::TYPE_IFD8 => $this->unpackU64(substr($bytes, $cursor, 8)),
                TiffConst::TYPE_SLONG8 => $this->unpackS64(substr($bytes, $cursor, 8)),
                TiffConst::TYPE_FLOAT  => $this->unpackFloat(substr($bytes, $cursor, 4)),
                TiffConst::TYPE_DOUBLE => $this->unpackDouble(substr($bytes, $cursor, 8)),
                default                => throw new ParseError('Unsupported type in decodeBytes: ' . $type, 1331),
            };
            $cursor += $componentSize;
        }

        return $count === 1 ? $vals[0] : new ExifNumericList($vals);
    }

    /**
     * Decodes the CFA pattern (UNDEFINED) payload into numeric components.
     *
     * EXIF 3.0 §4.6.6.7.34 defines the CFA pattern as two SHORT repeat units followed by m×n
     * bytes describing the colour filter layout.
     *
     * @param string $bytes Raw CFA pattern payload.
     */
    public function decodeCfaPatternPayload(string $bytes): ExifNumericList
    {
        if (strlen($bytes) < 4) {
            throw new ParseError(
                sprintf('CFAPattern payload too short (%d bytes, minimum 4)', strlen($bytes)),
                1505,
            );
        }

        $horizontalRepeatPixelUnit = $this->unpackU16(substr($bytes, 0, 2));
        $verticalRepeatPixelUnit   = $this->unpackU16(substr($bytes, 2, 2));

        $payloadLen            = strlen($bytes);
        $expectedPatternValues = $horizontalRepeatPixelUnit * $verticalRepeatPixelUnit;
        $expectedSize          = 4 + $expectedPatternValues;

        if ($expectedSize !== $payloadLen && ($horizontalRepeatPixelUnit > 0 && $verticalRepeatPixelUnit > 0)) {
            if ($this->bo === Endian::Little) {
                $swappedH = (ord($bytes[0]) << 8) | ord($bytes[1]);
                $swappedV = (ord($bytes[2]) << 8) | ord($bytes[3]);
            } else {
                $swappedH = ord($bytes[0]) | (ord($bytes[1]) << 8);
                $swappedV = ord($bytes[2]) | (ord($bytes[3]) << 8);
            }

            if ($swappedH > 0 && $swappedV > 0 && (4 + $swappedH * $swappedV) === $payloadLen) {
                $horizontalRepeatPixelUnit = $swappedH;
                $verticalRepeatPixelUnit   = $swappedV;
                $expectedPatternValues     = $swappedH * $swappedV;
                $expectedSize              = $payloadLen;
            }
        }

        if ($horizontalRepeatPixelUnit === 0 || $verticalRepeatPixelUnit === 0) {
            throw new ParseError(
                sprintf('CFAPattern repeat units must be non-zero, got %d x %d', $horizontalRepeatPixelUnit, $verticalRepeatPixelUnit),
                1506,
            );
        }

        if ($payloadLen !== $expectedSize) {
            throw new ParseError(
                sprintf('CFAPattern payload size %d does not match expected %d (4 + %d x %d)', $payloadLen, $expectedSize, $horizontalRepeatPixelUnit, $verticalRepeatPixelUnit),
                1507,
            );
        }

        $components = [$horizontalRepeatPixelUnit, $verticalRepeatPixelUnit];
        for ($index = 0; $index < $expectedPatternValues; ++$index) {
            $code = ord($bytes[4 + $index]);

            if ($code > 7) {
                throw new ParseError(
                    sprintf('CFAPattern matrix byte %d has undefined CFA code %d (valid: 0..7 per EXIF 3.0 Table 13)', $index, $code),
                    1508,
                );
            }

            $components[] = $code;
        }

        return new ExifNumericList($components);
    }

    /**
     * Normalises numeric list fields that describe strip or tile data.
     *
     * @param int    $tag      TIFF tag identifier.
     * @param int    $type     TIFF field type code.
     * @param int    $count    Number of values represented.
     * @param string $rawBytes Raw value bytes read for the entry.
     *
     * @return int|ExifNumericList
     */
    public function normaliseCountedImageDataField(
        int $tag,
        int $type,
        int $count,
        string $rawBytes,
    ): int|ExifNumericList {
        $this->validateCountedImageDataType($tag, $type);

        if ($count <= 0) {
            return new ExifNumericList([]);
        }

        $components = $this->decodeCountedComponents($tag, $type, $rawBytes, $count);

        if ($count === 1) {
            return $components[0] ?? 0;
        }

        return new ExifNumericList($components);
    }

    /**
     * Normalises DNG string tag values that may use BYTE type.
     *
     * DNG 1.7.1.0: LocalizedCameraModel, ProfileGroupName, and certain UTF-8 string
     * tags may be stored as BYTE instead of ASCII.
     *
     * @param int                                                                   $tag      TIFF tag identifier.
     * @param int                                                                   $type     TIFF field type code.
     * @param string                                                                $rawBytes Raw value bytes.
     * @param int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64 $value    Previously decoded value.
     *
     * @return int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64
     */
    public function normaliseDngStringValue(
        int $tag,
        int $type,
        string $rawBytes,
        int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64 $value,
    ): int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64 {
        // DNG 1.7.1.0: LocalizedCameraModel may be stored as BYTE instead of
        // ASCII. When type is BYTE, treat the raw bytes as a NUL-terminated
        // UTF-8 string rather than a numeric list.
        if ($tag === DngTag::LOCALIZED_CAMERA_MODEL && $type === TiffConst::TYPE_BYTE) {
            return rtrim($rawBytes, "\0");
        }

        // DNG 1.7.0.0: ProfileGroupName must be ASCII or BYTE with NUL terminator.
        if ($tag === DngTag::PROFILE_GROUP_NAME) {
            if ($type !== TiffConst::TYPE_ASCII && $type !== TiffConst::TYPE_BYTE) {
                throw new ParseError(
                    sprintf('ProfileGroupName must use ASCII or BYTE type, got %d.', $type),
                    1509,
                );
            }

            if ($type === TiffConst::TYPE_BYTE) {
                if ($rawBytes === '' || $rawBytes[strlen($rawBytes) - 1] !== "\0") {
                    throw new ParseError(
                        'ProfileGroupName BYTE payload must be NUL-terminated per DNG 1.7.0.0.',
                        1510,
                    );
                }

                return rtrim($rawBytes, "\0");
            }
        }

        // DNG 1.7.1.0: String tags that must be ASCII or BYTE, NUL-terminated UTF-8.
        if (in_array($tag, self::DNG_UTF8_STRING_TAGS, true)) {
            if ($type !== TiffConst::TYPE_ASCII && $type !== TiffConst::TYPE_BYTE) {
                throw new ParseError(
                    sprintf('DNG string tag 0x%04X must use ASCII or BYTE type, got %d.', $tag, $type),
                    1571,
                );
            }

            if ($type === TiffConst::TYPE_BYTE) {
                if ($rawBytes === '' || $rawBytes[strlen($rawBytes) - 1] !== "\0") {
                    throw new ParseError(
                        sprintf('DNG string tag 0x%04X BYTE payload must be NUL-terminated.', $tag),
                        1572,
                    );
                }

                $text = rtrim($rawBytes, "\0");

                if (!mb_check_encoding($text, 'UTF-8')) {
                    throw new ParseError(
                        sprintf('DNG string tag 0x%04X contains malformed UTF-8.', $tag),
                        1573,
                    );
                }

                return $text;
            }
        }

        return $value;
    }

    /**
     * Converts decoded UInt64 values into integers when possible, preserving oversize pointer offsets.
     *
     * @param int                                                                   $tag   Tag identifier.
     * @param int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64 $value Decoded value.
     *
     * @return int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64
     */
    public function convertUInt64Values(
        int $tag,
        int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64 $value,
    ): int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64 {
        if ($value instanceof UInt64) {
            return $this->normaliseScalarUInt64($tag, $value);
        }

        if ($value instanceof ExifNumericList) {
            $converted       = [];
            $needsConversion = false;
            foreach ($value->values as $component) {
                if ($component instanceof UInt64) {
                    $converted[]     = $this->normaliseScalarUInt64($tag, $component);
                    $needsConversion = true;

                    continue;
                }

                $converted[] = $component;
            }

            if ($needsConversion) {
                return new ExifNumericList($converted);
            }
        }

        return $value;
    }

    /**
     * Indicates whether the tag encodes a counted image data field (strip/tile).
     *
     * @param int $tag Tag identifier.
     */
    public function isCountedImageDataTag(int $tag): bool
    {
        return in_array($tag, self::COUNTED_IMAGE_DATA_TAGS, true);
    }

    /**
     * Indicates whether the tag points to another IFD location.
     *
     * @param int $tag Tag identifier.
     */
    public function isPointerTag(int $tag): bool
    {
        return in_array($tag, self::POINTER_TAGS, true);
    }

    /**
     * Returns the number of bytes used per component for a TIFF field type.
     *
     * @param int $type TIFF field type code.
     */
    public function bytesPerComponent(int $type): int
    {
        return match ($type) {
            TiffConst::TYPE_BYTE, TiffConst::TYPE_ASCII, TiffConst::TYPE_SBYTE, TiffConst::TYPE_UNDEFINED => 1,
            TiffConst::TYPE_SHORT, TiffConst::TYPE_SSHORT => 2,
            TiffConst::TYPE_LONG, TiffConst::TYPE_IFD, TiffConst::TYPE_SLONG, TiffConst::TYPE_FLOAT => 4,
            TiffConst::TYPE_RATIONAL, TiffConst::TYPE_SRATIONAL, TiffConst::TYPE_DOUBLE,
            TiffConst::TYPE_LONG8, TiffConst::TYPE_SLONG8, TiffConst::TYPE_IFD8 => 8,
            default => throw new ParseError('Unsupported TIFF type: ' . $type, 1338),
        };
    }

    /**
     * Returns count × componentSize with an overflow guard.
     *
     * @param int $componentSize Bytes per component.
     * @param int $count         Number of components.
     */
    public function safeValueByteCount(int $componentSize, int $count): int
    {
        if ($count > intdiv(PHP_INT_MAX, $componentSize)) {
            throw new ParseError(
                sprintf(
                    'TIFF entry count %d × component size %d overflows integer range',
                    $count,
                    $componentSize,
                ),
                1339,
            );
        }

        return $componentSize * $count;
    }

    /**
     * Ensures that an offset lies within the TIFF blob and returns it as an integer.
     *
     * @param int|UInt64|string $offset  Candidate offset value.
     * @param string            $context Description for error messages.
     * @param int               $length  Optional data length for bounds check.
     */
    public function ensureOffset(int|UInt64|string $offset, string $context, int $length = 0): int
    {
        if (is_string($offset)) {
            return $this->ensureDecimalOffset($offset, $context, $length);
        }

        $offset64 = $offset instanceof UInt64 ? $offset : UInt64::fromInt($offset);

        $this->assertOffsetRange($offset64, $length, $context);

        return $offset64->toInt($context);
    }

    /**
     * Normalises an optional offset that may be zero.
     *
     * @param UInt64 $offset  Candidate offset.
     * @param string $context Description for error messages.
     */
    public function normaliseOptionalOffset(UInt64 $offset, string $context): int
    {
        if ($offset->isZero()) {
            return 0;
        }

        return $this->ensureOffset($offset, $context);
    }

    /**
     * Normalises a BigTIFF optional offset according to the configured field width.
     *
     * @param int|UInt64|string $offset  Candidate offset.
     * @param string            $context Description for error messages.
     */
    public function normaliseBigTiffOptionalOffset(int|UInt64|string $offset, string $context): int
    {
        if ($offset instanceof UInt64) {
            return $this->normaliseOptionalOffset($offset, $context);
        }

        if (is_int($offset)) {
            if ($offset <= 0) {
                return 0;
            }

            return $this->ensureOffset($offset, $context);
        }

        if ($this->decimalStringIsZero($offset)) {
            return 0;
        }

        return $this->ensureOffset($offset, $context);
    }

    /**
     * Converts an integer into a byte string respecting the configured endianness.
     *
     * @param int|UInt64 $v     Integer value to convert.
     * @param int        $bytes Number of bytes to output.
     */
    private function uXToBytes(int|UInt64 $v, int $bytes): string
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
    private function read32FromBytes(string $bytes, int $offset, bool $signed): int
    {
        $chunk = substr($bytes, $offset, 4);

        return $signed ? $this->unpackS32($chunk) : $this->unpackU32($chunk);
    }

    /**
     * Unpacks an unsigned 16-bit integer from a byte string.
     *
     * @param string $b Source bytes.
     */
    private function unpackU16(string $b): int
    {
        $format = $this->bo === Endian::Little ? 'v' : 'n';

        return Unpack::int($format, $b, '16-bit value from TIFF bytes');
    }

    /**
     * Unpacks a signed 16-bit integer from a byte string.
     *
     * @param string $b Source bytes.
     */
    private function unpackS16(string $b): int
    {
        $u = $this->unpackU16($b);

        return $u >= BitMask::SIGN_BIT_16 ? $u - BitMask::UINT16_BASE : $u;
    }

    /**
     * Unpacks an unsigned 32-bit integer from a byte string.
     *
     * @param string $b Source bytes.
     */
    private function unpackU32(string $b): int
    {
        $format = $this->bo === Endian::Little ? 'V' : 'N';

        return Unpack::int($format, $b, '32-bit value from TIFF bytes');
    }

    /**
     * Unpacks a signed 32-bit integer from a byte string.
     *
     * @param string $b Source bytes.
     */
    private function unpackS32(string $b): int
    {
        $u = $this->unpackU32($b);

        return (($u & BitMask::SIGN_BIT_32) !== 0) ? -((~$u & BitMask::UINT32_MAX) + 1) : $u;
    }

    /**
     * Unpacks an IEEE-754 single-precision float from a byte string.
     *
     * @param string $b Source bytes.
     */
    private function unpackFloat(string $b): float
    {
        $format = $this->bo === Endian::Little ? 'g' : 'G';

        return Unpack::float($format, $b, '32-bit float from TIFF bytes');
    }

    /**
     * Unpacks an IEEE-754 double-precision float from a byte string.
     *
     * @param string $b Source bytes.
     */
    private function unpackDouble(string $b): float
    {
        $format = $this->bo === Endian::Little ? 'e' : 'E';

        return Unpack::float($format, $b, '64-bit float from TIFF bytes');
    }

    /**
     * Unpacks an unsigned 64-bit integer from a byte string.
     *
     * @param string $b Source bytes.
     */
    private function unpackU64(string $b): UInt64
    {
        return Unpack::uint64($b, $this->bo === Endian::Little, '64-bit value from TIFF bytes');
    }

    /**
     * Unpacks a signed 64-bit integer from a byte string.
     *
     * @param string $b Source bytes.
     */
    private function unpackS64(string $b): int
    {
        $unsigned = $this->unpackU64($b);
        $hi       = $unsigned->high();
        $lo       = $unsigned->low();

        if (($hi & BitMask::SIGN_BIT_32) === 0) {
            return $unsigned->toInt('Signed 64-bit integer');
        }

        $hiComplement = (~$hi) & BitMask::UINT32_MAX;
        $loComplement = (~$lo) & BitMask::UINT32_MAX;

        $magnitude = Unpack::combineUint32($hiComplement, $loComplement)
            ->addSmall(1)
            ->toInt('Signed 64-bit integer magnitude');

        return -$magnitude;
    }

    /**
     * Converts an unsigned integer to its signed representation for the given width.
     *
     * @param int $u    Unsigned integer value.
     * @param int $bits Bit width of the target signed representation.
     */
    private function toSigned(int $u, int $bits): int
    {
        $sign = 1 << ($bits - 1);

        return (($u & $sign) !== 0) ? $u - (1 << $bits) : $u;
    }

    /**
     * Normalises a UInt64 scalar into an integer when possible, preserving oversized pointer values.
     *
     * @param int    $tag   Tag identifier.
     * @param UInt64 $value UInt64 value to normalise.
     */
    private function normaliseScalarUInt64(int $tag, UInt64 $value): int|UInt64
    {
        if ($this->isPointerTag($tag)) {
            if ($value->fitsSignedInt()) {
                return $value->toInt(sprintf('IFD pointer tag 0x%04X', $tag));
            }

            return $value;
        }

        return $value->toInt(sprintf('IFD tag 0x%04X value', $tag));
    }

    /**
     * Validates that strip/tile offset and byte-count tags use integer TIFF field types.
     *
     * @param int $tag  Tag identifier.
     * @param int $type TIFF field type code.
     */
    private function validateCountedImageDataType(int $tag, int $type): void
    {
        if (in_array($type, self::COUNTED_IMAGE_DATA_INTEGER_TYPES, true)) {
            return;
        }

        throw new ParseError(sprintf(
            '%s (tag 0x%04X) must use integer TIFF field types; got type %d.',
            $this->countedImageDataTagName($tag),
            $tag,
            $type,
        ), 1600);
    }

    /**
     * Returns the canonical tag label for strip/tile counted image-data fields.
     *
     * @param int $tag Tag identifier.
     */
    private function countedImageDataTagName(int $tag): string
    {
        return match ($tag) {
            ExifTag::STRIP_OFFSETS     => 'StripOffsets',
            ExifTag::STRIP_BYTE_COUNTS => 'StripByteCounts',
            TiffTag::TILE_OFFSETS      => 'TileOffsets',
            TiffTag::TILE_BYTE_COUNTS  => 'TileByteCounts',
            default                    => sprintf('IFD tag 0x%04X', $tag),
        };
    }

    /**
     * Decodes numeric components for counted strip/tile entries into integers.
     *
     * @param int    $tag      TIFF tag identifier.
     * @param int    $type     TIFF field type code.
     * @param string $rawBytes Raw bytes representing the values.
     * @param int    $count    Number of values represented.
     *
     * @return list<int>
     */
    private function decodeCountedComponents(int $tag, int $type, string $rawBytes, int $count): array
    {
        $componentSize   = $this->bytesPerComponent($type);
        $expectedLength  = $this->safeValueByteCount($componentSize, $count);
        $availableLength = strlen($rawBytes);

        if ($availableLength < $expectedLength) {
            throw new ParseError('Truncated numeric components for TIFF entry.', 1326);
        }

        $components = [];

        for ($i = 0; $i < $count; ++$i) {
            $chunk = substr($rawBytes, $i * $componentSize, $componentSize);

            $value = match ($type) {
                TiffConst::TYPE_SHORT => $this->unpackU16($chunk),
                TiffConst::TYPE_LONG  => $this->unpackU32($chunk),
                TiffConst::TYPE_LONG8 => $this->unpackU64($chunk),
                default               => throw new ParseError('Unsupported numeric type for strip/tile field: ' . $type, 1327),
            };

            if ($value instanceof UInt64) {
                $value = ($tag === ExifTag::STRIP_OFFSETS || $tag === TiffTag::TILE_OFFSETS)
                    ? $this->ensureOffset($value, sprintf('IFD tag 0x%04X', $tag))
                    : $value->toInt(sprintf('IFD tag 0x%04X', $tag));
            }

            $components[] = $value;
        }

        return $components;
    }

    /**
     * Verifies that an offset and optional length are contained within the TIFF blob.
     *
     * @param UInt64 $offset  Candidate offset.
     * @param int    $length  Data length for bounds check.
     * @param string $context Description for error messages.
     */
    private function assertOffsetRange(UInt64 $offset, int $length, string $context): void
    {
        if ($offset->compare($this->blobSize) > 0) {
            throw new BoundsError(sprintf('%s exceeds TIFF data length.', $context), 1333);
        }

        $size = $this->buffer->size();

        if ($length > $size) {
            throw new BoundsError(sprintf('%s length %d exceeds TIFF data length.', $context, $length), 1334);
        }

        $offsetInt = $offset->toInt($context);

        if (($length > 0) && ($offsetInt > ($size - $length))) {
            throw new BoundsError(sprintf('%s exceeds TIFF data length.', $context), 1335);
        }
    }

    /**
     * Ensures that a decimal offset lies within the TIFF blob and returns it as an integer.
     *
     * @param string $offset  Decimal string offset.
     * @param string $context Description for error messages.
     * @param int    $length  Data length for bounds check.
     */
    private function ensureDecimalOffset(string $offset, string $context, int $length): int
    {
        $normalised = $this->normaliseDecimalString($offset);
        $size       = $this->buffer->size();

        if ($this->compareDecimalStringToInt($normalised, $size) > 0) {
            throw new BoundsError(sprintf('%s exceeds TIFF data length.', $context), 1344);
        }

        if ($length > $size) {
            throw new BoundsError(sprintf('%s length %d exceeds TIFF data length.', $context, $length), 1345);
        }

        if ($length > 0) {
            $limit = $size - $length;
            if ($this->compareDecimalStringToInt($normalised, $limit) > 0) {
                throw new BoundsError(sprintf('%s exceeds TIFF data length.', $context), 1346);
            }
        }

        return (int) $normalised;
    }

    /**
     * Normalises a decimal string by validating its characters and removing leading zeros.
     *
     * @param string $value Decimal string to normalise.
     */
    private function normaliseDecimalString(string $value): string
    {
        if ($value === '') {
            throw new ParseError('Decimal offset must not be empty.', 1348);
        }

        if (strspn($value, '0123456789') !== strlen($value)) {
            throw new ParseError('Decimal offset contains invalid characters.', 1349);
        }

        $trimmed = ltrim($value, '0');

        return $trimmed === '' ? '0' : $trimmed;
    }

    /**
     * Compares a decimal string against a non-negative integer.
     *
     * @param string $decimal Normalised decimal string.
     * @param int    $int     Non-negative integer.
     */
    private function compareDecimalStringToInt(string $decimal, int $int): int
    {
        if ($int < 0) {
            return 1;
        }

        $intString = $int === 0 ? '0' : ltrim((string) $int, '0');
        $decLen    = strlen($decimal);
        $intLen    = strlen($intString);

        if ($decLen !== $intLen) {
            return $decLen <=> $intLen;
        }

        return $decimal <=> $intString;
    }

    /**
     * Determines whether a decimal string represents zero.
     *
     * @param string $value Decimal string to check.
     */
    public function decimalStringIsZero(string $value): bool
    {
        $length = strlen($value);

        for ($i = 0; $i < $length; ++$i) {
            if ($value[$i] !== '0') {
                return false;
            }
        }

        return true;
    }
}
