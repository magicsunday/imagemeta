<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Tiff;

use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\PayloadGuard;
use MagicSunday\ImageMeta\Core\Util\UInt64;
use MagicSunday\ImageMeta\Exif\Model\ExifNumericList;
use MagicSunday\ImageMeta\Exif\Model\ExifRational;
use MagicSunday\ImageMeta\Exif\Model\ExifRationalList;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Model\Dng\DngTag;

use function in_array;
use function intdiv;
use function is_string;
use function ord;
use function sprintf;
use function strlen;
use function substr;

/**
 * Core TIFF value decoding: type-to-byte mapping, raw byte extraction, and scalar conversion.
 *
 * TIFF 6.0 §2.2 defines the field type encodings mapped to PHP scalars here.
 * EXIF 3.0 §4.5.2 mirrors these definitions with additional EXIF context.
 */
final readonly class TiffValueDecoder
{
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
     * @param TiffBinaryReader    $binaryReader    Binary I/O for reading and unpacking.
     * @param TiffOffsetValidator $offsetValidator Offset bounds checking.
     * @param ExifTagDecoder      $tagDecoder      ASCII/text decoder.
     */
    public function __construct(
        private TiffBinaryReader $binaryReader,
        private TiffOffsetValidator $offsetValidator,
        private ExifTagDecoder $tagDecoder,
    ) {
    }

    /**
     * Extracts the raw bytes addressed by a directory entry.
     *
     * @param int               $type          TIFF field type code.
     * @param int               $count         Number of values represented.
     * @param int|UInt64|string $valueOrOffset Inline value bytes or an offset into the blob.
     * @param string|null       $inlineBytes   Raw bytes captured from the value/offset field.
     * @param int|null          $componentSize Precomputed bytes per component for this TIFF type.
     * @param int|null          $valueBytes    Precomputed total byte count for this value.
     *
     * @return array{0: string, 1: int|null}
     */
    public function valueBytes(
        int $type,
        int $count,
        int|UInt64|string $valueOrOffset,
        ?string $inlineBytes = null,
        ?int $componentSize = null,
        ?int $valueBytes = null,
    ): array {
        $unitSize = $componentSize ?? $this->bytesPerComponent($type);
        assert($unitSize !== null);

        $dataSize        = $valueBytes ?? $this->safeValueByteCount($unitSize, $count);
        $inlineThreshold = 4;

        if ($inlineBytes !== null) {
            PayloadGuard::ensureMinimumLength($inlineBytes, $dataSize, sprintf('Inline value for TIFF type %d', $type), 1336);

            return [substr($inlineBytes, 0, $dataSize), null];
        }

        if ($dataSize <= $inlineThreshold) {
            if (is_string($valueOrOffset)) {
                PayloadGuard::ensureMinimumLength($valueOrOffset, $dataSize, sprintf('Inline value for TIFF type %d', $type), 1337);

                return [substr($valueOrOffset, 0, $dataSize), null];
            }

            $raw = $this->binaryReader->uXToBytes($valueOrOffset, $inlineThreshold);

            return [substr($raw, 0, $dataSize), null];
        }

        $offset = $this->offsetValidator->ensureOffset($valueOrOffset, sprintf('Value offset for TIFF type %d', $type), $dataSize);
        $bytes  = $this->binaryReader->readAt($offset, $dataSize);

        return [$bytes, $offset];
    }

    /**
     * Converts raw bytes into PHP scalar values based on the TIFF type.
     *
     * @param int      $tag           Tag identifier for encoding-specific rules.
     * @param int      $type          TIFF field type code.
     * @param int      $count         Number of values represented.
     * @param string   $bytes         Raw value bytes read from the blob.
     * @param int|null $componentSize Precomputed bytes per component for this TIFF type.
     * @param int|null $expectedBytes Precomputed total byte count for this value.
     */
    public function decodeBytes(
        int $tag,
        int $type,
        int $count,
        string $bytes,
        ?int $componentSize = null,
        ?int $expectedBytes = null,
    ): int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64 {
        $componentSize ??= $this->bytesPerComponent($type);
        assert($componentSize !== null);

        $bytesLength    = strlen($bytes);
        $expectedLength = $expectedBytes ?? $this->safeValueByteCount($componentSize, $count);

        if ($bytesLength < $expectedLength) {
            throw new ParseError(
                sprintf(
                    'Truncated value for TIFF type %d (expected %d bytes, got %d)',
                    $type,
                    $expectedLength,
                    $bytesLength,
                ),
                1328,
            );
        }

        // ASCII
        if ($type === TiffFieldType::Ascii->value) {
            return $this->tagDecoder->decodeAscii($tag, $count, $bytes, self::EXIF_30_UTF8_TAGS);
        }

        if ($type === TiffFieldType::Undefined->value) {
            return $bytes;
        }

        // RATIONAL / SRATIONAL
        if ($type === TiffFieldType::Rational->value || $type === TiffFieldType::SRational->value) {
            $rationalValues = [];
            for ($i = 0; $i < $count; ++$i) {
                $num              = $this->binaryReader->read32FromBytes($bytes, $i * 8, $type === TiffFieldType::SRational->value);
                $den              = $this->binaryReader->read32FromBytes($bytes, $i * 8 + 4, $type === TiffFieldType::SRational->value);
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
                TiffFieldType::Byte->value   => ord($bytes[$cursor]),
                TiffFieldType::SByte->value  => $this->binaryReader->toSigned(ord($bytes[$cursor]), 8),
                TiffFieldType::Short->value  => $this->binaryReader->unpackU16(substr($bytes, $cursor, 2)),
                TiffFieldType::SShort->value => $this->binaryReader->unpackS16(substr($bytes, $cursor, 2)),
                TiffFieldType::Long->value, TiffFieldType::Ifd->value => $this->binaryReader->unpackU32(substr($bytes, $cursor, 4)),
                TiffFieldType::SLong->value => $this->binaryReader->unpackS32(substr($bytes, $cursor, 4)),
                TiffFieldType::Long8->value, TiffFieldType::Ifd8->value => $this->binaryReader->unpackU64(substr($bytes, $cursor, 8)),
                TiffFieldType::SLong8->value => $this->binaryReader->unpackS64(substr($bytes, $cursor, 8)),
                TiffFieldType::Float->value  => $this->binaryReader->unpackFloat(substr($bytes, $cursor, 4)),
                TiffFieldType::Double->value => $this->binaryReader->unpackDouble(substr($bytes, $cursor, 8)),
                default                      => throw new ParseError('Unsupported type in decodeBytes: ' . $type, 1886),
            };
            $cursor += $componentSize;
        }

        return $count === 1 ? $vals[0] : new ExifNumericList($vals);
    }

    /**
     * Converts decoded UInt64 values into integers when possible, preserving oversize pointer offsets.
     *
     * @param int                                                                   $tag   Tag identifier.
     * @param int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64 $value Decoded value.
     */
    public function convertUInt64Values(
        int $tag,
        int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64 $value,
    ): int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64 {
        if ($value instanceof UInt64) {
            return $this->normalizeScalarUInt64($tag, $value);
        }

        if ($value instanceof ExifNumericList) {
            $converted       = [];
            $needsConversion = false;
            foreach ($value->values as $component) {
                if ($component instanceof UInt64) {
                    $converted[]     = $this->normalizeScalarUInt64($tag, $component);
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
     * Returns the number of bytes used per component for a TIFF field type.
     *
     * @param int $type TIFF field type code.
     */
    public function bytesPerComponent(int $type): ?int
    {
        return TiffFieldType::tryFrom($type)?->bytesPerComponent();
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
                1978,
            );
        }

        return $componentSize * $count;
    }

    /**
     * Indicates whether the tag points to another IFD location.
     *
     * @param int $tag Tag identifier.
     */
    private function isPointerTag(int $tag): bool
    {
        return in_array($tag, self::POINTER_TAGS, true);
    }

    /**
     * Normalizes a UInt64 scalar into an integer when possible, preserving oversized pointer values.
     *
     * @param int    $tag   Tag identifier.
     * @param UInt64 $value UInt64 value to normalize.
     */
    private function normalizeScalarUInt64(int $tag, UInt64 $value): int|UInt64
    {
        if ($this->isPointerTag($tag)) {
            if ($value->fitsSignedInt()) {
                return $value->toInt(sprintf('IFD pointer tag 0x%04X', $tag));
            }

            return $value;
        }

        return $value->toInt(sprintf('IFD tag 0x%04X value', $tag));
    }
}
