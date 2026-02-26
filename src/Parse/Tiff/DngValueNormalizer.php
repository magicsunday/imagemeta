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
use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\PayloadGuard;
use MagicSunday\ImageMeta\Core\Util\UInt64;
use MagicSunday\ImageMeta\Exif\Model\ExifNumericList;
use MagicSunday\ImageMeta\Exif\Model\ExifRational;
use MagicSunday\ImageMeta\Exif\Model\ExifRationalList;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Model\Dng\DngTag;
use MagicSunday\ImageMeta\Model\Tiff\TiffTag;

use function in_array;
use function ord;
use function rtrim;
use function sprintf;
use function strlen;
use function substr;

/**
 * DNG-specific value normalisation for string tags, counted image data, and CFA patterns.
 *
 * DNG 1.7.1.0 defines the string tag encoding rules and CFA pattern layout
 * normalized by this class. EXIF 3.0 §4.6.6.7.34 specifies the CFA pattern structure.
 */
final readonly class DngValueNormalizer
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
     * @param TiffBinaryReader    $binaryReader    Binary I/O for unpacking and byte order.
     * @param TiffOffsetValidator $offsetValidator Offset bounds checking.
     * @param TiffValueDecoder    $decoder         Core TIFF value decoding.
     */
    public function __construct(
        private TiffBinaryReader $binaryReader,
        private TiffOffsetValidator $offsetValidator,
        private TiffValueDecoder $decoder,
    ) {
    }

    /**
     * Normalizes DNG string tag values that may use BYTE type.
     *
     * DNG 1.7.1.0: LocalizedCameraModel, ProfileGroupName, and certain UTF-8 string
     * tags may be stored as BYTE instead of ASCII.
     *
     * @param int                                                                   $tag      TIFF tag identifier.
     * @param int                                                                   $type     TIFF field type code.
     * @param string                                                                $rawBytes Raw value bytes.
     * @param int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64 $value    Previously decoded value.
     */
    public function normalizeDngStringValue(
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
                return $value;
            }

            if ($type === TiffConst::TYPE_BYTE) {
                return rtrim($rawBytes, "\0");
            }
        }

        // DNG 1.7.1.0: String tags that must be ASCII or BYTE, NUL-terminated UTF-8.
        if (in_array($tag, self::DNG_UTF8_STRING_TAGS, true)) {
            if ($type !== TiffConst::TYPE_ASCII && $type !== TiffConst::TYPE_BYTE) {
                return $value;
            }

            if ($type === TiffConst::TYPE_BYTE) {
                return rtrim($rawBytes, "\0");
            }
        }

        return $value;
    }

    /**
     * Normalizes numeric list fields that describe strip or tile data.
     *
     * @param int    $tag      TIFF tag identifier.
     * @param int    $type     TIFF field type code.
     * @param int    $count    Number of values represented.
     * @param string $rawBytes Raw value bytes read for the entry.
     */
    public function normalizeCountedImageDataField(
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
     * Decodes the CFA pattern (UNDEFINED) payload into numeric components.
     *
     * EXIF 3.0 §4.6.6.7.34 defines the CFA pattern as two SHORT repeat units followed by m×n
     * bytes describing the colour filter layout.
     *
     * @param string $bytes Raw CFA pattern payload.
     */
    public function decodeCfaPatternPayload(string $bytes): ExifNumericList
    {
        PayloadGuard::ensureMinimumLength($bytes, 4, 'CFAPattern payload', 1505);

        $horizontalRepeatPixelUnit = $this->binaryReader->unpackU16(substr($bytes, 0, 2));
        $verticalRepeatPixelUnit   = $this->binaryReader->unpackU16(substr($bytes, 2, 2));

        $payloadLen            = strlen($bytes);
        $expectedPatternValues = $horizontalRepeatPixelUnit * $verticalRepeatPixelUnit;
        $expectedSize          = 4 + $expectedPatternValues;

        if ($expectedSize !== $payloadLen && ($horizontalRepeatPixelUnit > 0 && $verticalRepeatPixelUnit > 0)) {
            if ($this->binaryReader->byteOrder() === Endian::Little) {
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
                2058,
            );
        }

        if ($payloadLen > $expectedSize) {
            throw new ParseError(
                sprintf('CFAPattern payload size %d does not match expected %d (4 + %d x %d)', $payloadLen, $expectedSize, $horizontalRepeatPixelUnit, $verticalRepeatPixelUnit),
                2059,
            );
        }

        // EXIF 3.0 §4.6.6.7.34 defines payload size as 4 + m*n bytes, but
        // some cameras emit corrupted repeat dimensions with shorter payloads.
        // Reader-side parsing keeps available pattern bytes instead of aborting.
        if ($payloadLen < $expectedSize) {
            $expectedPatternValues = $payloadLen - 4;
        }

        $components = [$horizontalRepeatPixelUnit, $verticalRepeatPixelUnit];
        for ($index = 0; $index < $expectedPatternValues; ++$index) {
            $components[] = ord($bytes[4 + $index]);
        }

        return new ExifNumericList($components);
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
            TiffValidationSupport::countedImageDataTagName($tag),
            $tag,
            $type,
        ), 2066);
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
        $componentSize = $this->decoder->bytesPerComponent($type);
        assert($componentSize !== null);

        $expectedLength  = $this->decoder->safeValueByteCount($componentSize, $count);
        $availableLength = strlen($rawBytes);

        if ($availableLength < $expectedLength) {
            throw new ParseError('Truncated numeric components for TIFF entry.', 1326);
        }

        $components = [];

        for ($i = 0; $i < $count; ++$i) {
            $chunk = substr($rawBytes, $i * $componentSize, $componentSize);

            $value = match ($type) {
                TiffConst::TYPE_SHORT => $this->binaryReader->unpackU16($chunk),
                TiffConst::TYPE_LONG  => $this->binaryReader->unpackU32($chunk),
                TiffConst::TYPE_LONG8 => $this->binaryReader->unpackU64($chunk),
                default               => throw new ParseError('Unsupported numeric type for strip/tile field: ' . $type, 1327),
            };

            if ($value instanceof UInt64) {
                $value = ($tag === ExifTag::STRIP_OFFSETS || $tag === TiffTag::TILE_OFFSETS)
                    ? $this->offsetValidator->ensureOffset($value, sprintf('IFD tag 0x%04X', $tag))
                    : $value->toInt(sprintf('IFD tag 0x%04X', $tag));
            }

            $components[] = $value;
        }

        return $components;
    }
}
