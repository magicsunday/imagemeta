<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Jpeg;

use MagicSunday\ImageMeta\Core\BitMask;
use MagicSunday\ImageMeta\Core\Endian;
use MagicSunday\ImageMeta\Core\MemoryBuffer;
use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Model\Mpf\MpfAttributes;
use MagicSunday\ImageMeta\Model\Mpf\MpfDocument;
use MagicSunday\ImageMeta\Model\Mpf\MpfEntry;
use MagicSunday\ImageMeta\Parse\Tiff\TiffConst;

use function array_any;
use function array_is_list;
use function array_key_exists;
use function count;
use function is_array;
use function is_int;
use function is_string;
use function pack;
use function strlen;
use function substr;

/**
 * Parses MPF payloads carried in JPEG APP2 segments.
 *
 * EXIF 3.0 §4.6 describes the Multi-Picture Format container and its JPEG
 * encapsulation and TIFF header layout.
 *
 * @phpstan-type MpfRational array{numerator:int, denominator:int}
 * @phpstan-type MpfValue int|string|list<int>|MpfRational|list<MpfRational>
 * @phpstan-type MpfDirectory array<int, MpfValue>
 */
final class MpfParser
{
    private const int TIFF_MAGIC = TiffConst::MAGIC_CLASSIC;

    private const int TYPE_BYTE = 1;

    private const int TYPE_ASCII = 2;

    private const int TYPE_SHORT = 3;

    private const int TYPE_LONG = 4;

    private const int TYPE_RATIONAL = 5;

    private const int TYPE_UNDEFINED = 7;

    private const int TYPE_SLONG = 9;

    private const int TYPE_SRATIONAL = 10;

    private const int TAG_MPF_VERSION = 0xB000;

    private const int TAG_NUMBER_OF_IMAGES = 0xB001;

    private const int TAG_MP_ENTRY = 0xB002;

    private const int TAG_IMAGE_UID_LIST = 0xB003;

    private const int TAG_TOTAL_FRAMES = 0xB004;

    private const int TAG_INDIVIDUAL_IMAGE_NUMBER = 0xB005;

    private const int TAG_PANORAMA_ANGLE = 0xB006;

    private const int TAG_PANORAMA_AXIS = 0xB007;

    /**
     * Decodes the MPF payload into a structured document model.
     *
     * The MP Index IFD is a TIFF IFD located at the offset stored after the
     * TIFF magic in the MPF header (EXIF 3.0 §4.6.2) and uses the standard
     * TIFF byte-order indicators (EXIF 3.0 §4.6.1).
     */
    public function parse(string $payload): MpfDocument
    {
        $buffer = new MemoryBuffer($payload);
        if ($buffer->size() < 8) {
            throw new ParseError('MPF payload shorter than TIFF header');
        }

        $byteOrder = $buffer->read(2);
        // EXIF 3.0 §4.6.1 restricts MPF to the standard TIFF byte-order signatures "II" or "MM".
        $endian = match ($byteOrder) {
            Endian::Little->value => Endian::Little,
            Endian::Big->value    => Endian::Big,
            default               => throw new ParseError('MPF payload contains invalid byte order'),
        };

        $magic = $this->readU16($buffer, $endian);
        if ($magic !== self::TIFF_MAGIC) {
            throw new ParseError('MPF payload missing TIFF magic');
        }

        $firstIfdOffset = $this->readU32($buffer, $endian);
        // The MP Index IFD offset is stored as a 32-bit value relative to the TIFF header (EXIF 3.0 §4.6.2).
        if ($firstIfdOffset < 8 || $firstIfdOffset >= $buffer->size()) {
            throw new ParseError('MP Index IFD offset outside payload bounds');
        }

        [$indexEntries, $nextIfdOffset] = $this->readIfd($buffer, $endian, $firstIfdOffset);

        $version    = $this->stringValue($indexEntries[self::TAG_MPF_VERSION] ?? null);
        $imageCount = $this->intValue($indexEntries[self::TAG_NUMBER_OF_IMAGES] ?? null);

        $entriesData = $indexEntries[self::TAG_MP_ENTRY] ?? null;
        if (!is_string($entriesData)) {
            throw new ParseError('MP Index IFD missing MPEntry data');
        }

        $entries = $this->parseEntries($entriesData, $endian);
        if ($imageCount === null) {
            $imageCount = count($entries);
        }

        if ($imageCount !== count($entries)) {
            throw new ParseError('MP Entry list length does not match reported image count');
        }

        $attributes = null;
        if ($nextIfdOffset !== 0) {
            // When present, the MP Attribute IFD follows the MP Index IFD and shares the same offset semantics (EXIF 3.0 §4.6.4).
            if ($nextIfdOffset >= $buffer->size()) {
                throw new ParseError('MP Attribute IFD offset outside payload bounds');
            }

            [$attributeEntries] = $this->readIfd($buffer, $endian, $nextIfdOffset);
            $attributes         = $this->buildAttributes($attributeEntries);
        }

        return new MpfDocument(
            version: $version,
            imageCount: $imageCount,
            entries: $entries,
            attributes: $attributes,
        );
    }

    /**
     * Parses an MPF-specific TIFF IFD from the buffer.
     *
     * Both the MP Index IFD and MP Attribute IFD re-use the classic TIFF IFD layout (EXIF 3.0 §4.6.2/§4.6.4).
     *
     * @return array{0: MpfDirectory, 1: int}
     */
    private function readIfd(MemoryBuffer $buffer, Endian $endian, int $offset): array
    {
        $buffer->seek($offset);

        $entryCount = $this->readU16($buffer, $endian);
        if ($entryCount < 0 || $entryCount > 512) {
            throw new ParseError('MPF IFD entry count outside supported range');
        }

        $entries = [];
        for ($i = 0; $i < $entryCount; ++$i) {
            $tag            = $this->readU16($buffer, $endian);
            $type           = $this->readU16($buffer, $endian);
            $componentCount = $this->readU32($buffer, $endian);

            if ($componentCount < 0 || $componentCount > 1_048_576) {
                throw new ParseError('MPF entry reports unreasonable component count');
            }

            $valueOrOffset = $this->readU32($buffer, $endian);
            $data          = $this->resolveValueData($buffer, $endian, $type, $componentCount, $valueOrOffset);

            $entries[$tag] = $this->decodeValue($type, $componentCount, $data, $endian);
        }

        $nextOffset = $this->readU32($buffer, $endian);

        return [$entries, $nextOffset];
    }

    /**
     * Resolves the data bytes referenced by an IFD entry.
     */
    private function resolveValueData(
        MemoryBuffer $buffer,
        Endian $endian,
        int $type,
        int $componentCount,
        int $valueOrOffset,
    ): string {
        $typeSize = $this->typeSize($type);
        if ($typeSize === null) {
            throw new ParseError('Unsupported MPF field type ' . $type);
        }

        $byteCount = $componentCount * $typeSize;
        if ($byteCount === 0) {
            return '';
        }

        if ($byteCount <= 4) {
            // Inline MPF values use the same in-place storage rule as TIFF IFD
            // entries (EXIF 3.0 §4.6.2).
            $bytes = $this->packInt($valueOrOffset, $endian);

            return substr($bytes, 0, $byteCount);
        }

        // EXIF 3.0 §4.6.2 stores larger MPF values out of line at offsets relative to the MPF TIFF header.
        if (($valueOrOffset < 8) || (($valueOrOffset + $byteCount) > $buffer->size())) {
            throw new ParseError('MPF value offset outside payload bounds');
        }

        $current = $buffer->tell();
        $buffer->seek($valueOrOffset);
        $data = $buffer->read($byteCount);
        $buffer->seek($current);

        return $data;
    }

    /**
     * Decodes the raw value bytes using the specified TIFF field type.
     *
     * @return int|string|array{numerator:int, denominator:int}|array<int, int>|array<int, array{numerator:int, denominator:int}>
     *
     * @phpstan-return MpfValue
     */
    private function decodeValue(
        int $type,
        int $componentCount,
        string $data,
        Endian $endian,
    ): int|string|array {
        if ($componentCount === 0) {
            return [];
        }

        $values = [];
        $buffer = new MemoryBuffer($data);

        switch ($type) {
            case self::TYPE_BYTE:
                for ($i = 0; $i < $componentCount; ++$i) {
                    $values[] = $buffer->readU8();
                }

                break;

            case self::TYPE_ASCII:
            case self::TYPE_UNDEFINED:
                return $data;

            case self::TYPE_SHORT:
                for ($i = 0; $i < $componentCount; ++$i) {
                    $values[] = $endian === Endian::Little ? $buffer->readU16LE() : $buffer->readU16BE();
                }

                break;

            case self::TYPE_LONG:
                for ($i = 0; $i < $componentCount; ++$i) {
                    $values[] = $endian === Endian::Little ? $buffer->readU32LE() : $buffer->readU32BE();
                }

                break;

            case self::TYPE_SLONG:
                for ($i = 0; $i < $componentCount; ++$i) {
                    $unsigned = $endian === Endian::Little ? $buffer->readU32LE() : $buffer->readU32BE();
                    $values[] = $this->toSigned32($unsigned);
                }

                break;

            case self::TYPE_RATIONAL:
            case self::TYPE_SRATIONAL:
                for ($i = 0; $i < $componentCount; ++$i) {
                    $numerator   = $endian === Endian::Little ? $buffer->readU32LE() : $buffer->readU32BE();
                    $denominator = $endian === Endian::Little ? $buffer->readU32LE() : $buffer->readU32BE();
                    if ($type === self::TYPE_SRATIONAL) {
                        $numerator   = $this->toSigned32($numerator);
                        $denominator = $this->toSigned32($denominator);
                    }

                    $values[] = [
                        'numerator'   => $numerator,
                        'denominator' => $denominator,
                    ];
                }

                break;

            default:
                throw new ParseError('Unsupported MPF field type ' . $type);
        }

        if ($componentCount === 1) {
            return $values[0];
        }

        return $values;
    }

    /**
     * Converts a 32-bit unsigned value into a signed representation.
     */
    private function toSigned32(int $value): int
    {
        if (($value & BitMask::SIGN_BIT_32) !== 0) {
            return -((~$value & BitMask::UINT32_MAX) + 1);
        }

        return $value & BitMask::INT31_MAX;
    }

    /**
     * Parses the MP entry list from the raw MPEntry data.
     *
     * Each MP Entry consumes 16 bytes and carries image attributes as
     * specified by EXIF 3.0 §4.6.3.
     *
     * @return list<MpfEntry>
     */
    private function parseEntries(string $data, Endian $endian): array
    {
        $entrySize = 16;
        $length    = strlen($data);
        if (($length === 0) || (($length % $entrySize) !== 0)) {
            throw new ParseError('MPEntry data length is not a multiple of 16 bytes');
        }

        $buffer = new MemoryBuffer($data);

        $entries = [];
        $count   = (int) ($length / $entrySize);
        for ($i = 0; $i < $count; ++$i) {
            $attributes = $endian === Endian::Little ? $buffer->readU32LE() : $buffer->readU32BE();
            $size       = $endian === Endian::Little ? $buffer->readU32LE() : $buffer->readU32BE();
            $offset     = $endian === Endian::Little ? $buffer->readU32LE() : $buffer->readU32BE();
            $dep1       = $endian === Endian::Little ? $buffer->readU16LE() : $buffer->readU16BE();
            $dep2       = $endian === Endian::Little ? $buffer->readU16LE() : $buffer->readU16BE();

            // The MP Entry tuple mirrors the Attribute, Size, Offset, and Dependent image fields mandated by EXIF 3.0 §4.6.3.
            $entries[] = new MpfEntry($attributes, $size, $offset, $dep1, $dep2);
        }

        return $entries;
    }

    /**
     * Builds the MP attribute structure from the decoded entries.
     *
     * EXIF 3.0 §4.6.4 defines the optional MP Attribute IFD.
     *
     * @param MpfDirectory $entries
     */
    private function buildAttributes(array $entries): MpfAttributes
    {
        $imageUidList          = $this->stringValue($entries[self::TAG_IMAGE_UID_LIST] ?? null);
        $totalFrames           = $this->intValue($entries[self::TAG_TOTAL_FRAMES] ?? null);
        $individualImageNumber = $this->intValue($entries[self::TAG_INDIVIDUAL_IMAGE_NUMBER] ?? null);

        $panoramaAngle = $this->rationalListValue($entries[self::TAG_PANORAMA_ANGLE] ?? null);
        $panoramaAxis  = $this->rationalListValue($entries[self::TAG_PANORAMA_AXIS] ?? null);

        $known = [
            self::TAG_IMAGE_UID_LIST          => true,
            self::TAG_TOTAL_FRAMES            => true,
            self::TAG_INDIVIDUAL_IMAGE_NUMBER => true,
            self::TAG_PANORAMA_ANGLE          => true,
            self::TAG_PANORAMA_AXIS           => true,
        ];

        $additional = $this->filterAdditionalTags($entries, $known);

        return new MpfAttributes(
            imageUidList: $imageUidList,
            totalFrames: $totalFrames,
            individualImageNumber: $individualImageNumber,
            panoramaAngle: $panoramaAngle,
            panoramaAxis: $panoramaAxis,
            additionalTags: $additional,
        );
    }

    /**
     * Converts arbitrary decoded value into an integer when possible.
     *
     * @param MpfValue|null $value
     */
    private function intValue(int|string|array|null $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return null;
    }

    /**
     * Converts the decoded value into a trimmed string when appropriate.
     *
     * @param MpfValue|null $value
     */
    private function stringValue(int|string|array|null $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        return rtrim($value, "\0");
    }

    /**
     * Filters additional MPF tags from directory entries.
     *
     * @param MpfDirectory     $entries Known MPF directory entries.
     * @param array<int, true> $known   Map of known tag IDs.
     *
     * @return MpfDirectory Filtered directory with additional tags.
     */
    private function filterAdditionalTags(array $entries, array $known): array
    {
        return array_filter(
            $entries,
            static fn ($tag): bool => !isset($known[$tag]),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * Extracts a list of rational values from MPF value.
     *
     * @param MpfValue|null $value MPF value to extract from.
     *
     * @return list<MpfRational>|null List of rational values or null if invalid.
     */
    private function rationalListValue(int|string|array|null $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        if ($this->isRational($value)) {
            return [$value];
        }

        if ($this->isRationalList($value)) {
            return $value;
        }

        return null;
    }

    /**
     * Type guard checking if value is an MPF rational structure.
     *
     * @param array<int|string, MpfValue> $value Value to check.
     *
     * @return bool True if value is MPF rational.
     *
     * @phpstan-assert-if-true MpfRational $value
     */
    private function isRational(array $value): bool
    {
        if (!array_key_exists('numerator', $value) || !array_key_exists('denominator', $value)) {
            return false;
        }

        return is_int($value['numerator']) && is_int($value['denominator']);
    }

    /**
     * Type guard checking if value is a list of MPF rational structures.
     *
     * @param array<int|string, MpfValue> $value Value to check.
     *
     * @return bool True if value is list of MPF rationals.
     *
     * @phpstan-assert-if-true list<MpfRational> $value
     */
    private function isRationalList(array $value): bool
    {
        if (!array_is_list($value)) {
            return false;
        }

        return !array_any(
            $value,
            fn (int|string|array $item): bool => !is_array($item) || !$this->isRational($item)
        );
    }

    /**
     * Reads a 16-bit unsigned integer using the specified byte order.
     *
     * @param MemoryBuffer $buffer Source buffer.
     * @param Endian       $endian Byte order to use.
     *
     * @return int Unsigned 16-bit integer.
     */
    private function readU16(MemoryBuffer $buffer, Endian $endian): int
    {
        return $endian === Endian::Little ? $buffer->readU16LE() : $buffer->readU16BE();
    }

    /**
     * Reads a 32-bit unsigned integer using the specified byte order.
     *
     * @param MemoryBuffer $buffer Source buffer.
     * @param Endian       $endian Byte order to use.
     *
     * @return int Unsigned 32-bit integer.
     */
    private function readU32(MemoryBuffer $buffer, Endian $endian): int
    {
        return $endian === Endian::Little ? $buffer->readU32LE() : $buffer->readU32BE();
    }

    /**
     * Returns the byte size for a given MPF field type.
     *
     * @param int $type MPF field type identifier.
     *
     * @return int|null Byte size or null for unknown types.
     */
    private function typeSize(int $type): ?int
    {
        return match ($type) {
            self::TYPE_BYTE, self::TYPE_ASCII, self::TYPE_UNDEFINED => 1,
            self::TYPE_SHORT => 2,
            self::TYPE_LONG, self::TYPE_SLONG => 4,
            self::TYPE_RATIONAL, self::TYPE_SRATIONAL => 8,
            default => null,
        };
    }

    /**
     * Packs an unsigned 32-bit integer with the requested byte order.
     *
     * @param int    $value  Value to pack.
     * @param Endian $endian Byte order to use.
     *
     * @return string Packed 4-byte string.
     */
    private function packInt(int $value, Endian $endian): string
    {
        return pack($endian === Endian::Little ? 'V' : 'N', $value);
    }
}
