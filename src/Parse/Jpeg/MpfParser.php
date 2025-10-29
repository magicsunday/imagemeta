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
use MagicSunday\ImageMeta\Parse\Tiff\TiffConst;
use MagicSunday\ImageMeta\Model\Mpf\MpfAttributes;
use MagicSunday\ImageMeta\Model\Mpf\MpfDocument;
use MagicSunday\ImageMeta\Model\Mpf\MpfEntry;

use function array_diff_key;
use function array_values;
use function count;
use function pack;
use function strlen;
use function substr;

/**
 * @phpstan-type MpfRational array{numerator:int, denominator:int}
 * @phpstan-type MpfDecodedValue int|string|list<int>|MpfRational|list<MpfRational>
 */
/**
 * Parses MPF payloads carried in JPEG APP2 segments.
 *
 * EXIF 3.0 §4.6 describes the Multi-Picture Format container, with the JPEG
 * encapsulation and TIFF header layout retained from EXIF 2.32 §4.6.
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
     * TIFF magic in the MPF header (EXIF 3.0 §4.6.2; EXIF 2.32 §4.6.2) and uses
     * the standard TIFF byte-order indicators (EXIF 3.0 §4.6.1; EXIF 2.32
     * §4.6.1).
     */
    public function parse(string $payload): MpfDocument
    {
        $buffer = new MemoryBuffer($payload);
        if ($buffer->size() < 8) {
            throw new ParseError('MPF payload shorter than TIFF header');
        }

        $byteOrder = $buffer->read(2);
        // EXIF 3.0 §4.6.1 (and EXIF 2.32 §4.6.1) restrict MPF to the standard
        // TIFF byte-order signatures "II" or "MM".
        $endian    = match ($byteOrder) {
            Endian::Little->value => Endian::Little,
            Endian::Big->value    => Endian::Big,
            default               => throw new ParseError('MPF payload contains invalid byte order'),
        };

        $magic = $this->readU16($buffer, $endian);
        if ($magic !== self::TIFF_MAGIC) {
            throw new ParseError('MPF payload missing TIFF magic');
        }

        $firstIfdOffset = $this->readU32($buffer, $endian);
        // The MP Index IFD offset is stored as a 32-bit value relative to the
        // TIFF header (EXIF 3.0 §4.6.2; EXIF 2.32 §4.6.2).
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
            // When present, the MP Attribute IFD follows the MP Index IFD and
            // shares the same offset semantics (EXIF 3.0 §4.6.4; EXIF 2.32
            // §4.6.4).
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
     * Both the MP Index IFD and MP Attribute IFD re-use the classic TIFF IFD
     * layout (EXIF 3.0 §4.6.2/§4.6.4; EXIF 2.32 §4.6.2/§4.6.4).
     *
     * @return array{0: array<int, int|string|array>, 1: int}
     * @phpstan-return array{0: array<int, MpfDecodedValue>, 1: int}
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
            // entries (EXIF 3.0 §4.6.2; EXIF 2.32 §4.6.2).
            $bytes = $this->packInt($valueOrOffset, $endian);

            return substr($bytes, 0, $byteCount);
        }

        // EXIF 3.0 §4.6.2 (and EXIF 2.32 §4.6.2) store larger MPF values out
        // of line at offsets relative to the MPF TIFF header.
        if ($valueOrOffset < 8 || $valueOrOffset + $byteCount > $buffer->size()) {
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
     * @phpstan-return MpfDecodedValue
     */
    private function decodeValue(int $type, int $componentCount, string $data, Endian $endian): int|string|array
    {
        $values = [];
        $buf    = new MemoryBuffer($data);

        $readerU16 = $endian === Endian::Little ? 'readU16LE' : 'readU16BE';
        $readerU32 = $endian === Endian::Little ? 'readU32LE' : 'readU32BE';
        $readerS32 = $endian === Endian::Little ? 'readU32LE' : 'readU32BE';

        switch ($type) {
            case self::TYPE_BYTE:
                for ($i = 0; $i < $componentCount; ++$i) {
                    $values[] = $buf->readU8();
                }
                break;

            case self::TYPE_ASCII:
            case self::TYPE_UNDEFINED:
                return $data;

            case self::TYPE_SHORT:
                for ($i = 0; $i < $componentCount; ++$i) {
                    $values[] = $buf->$readerU16();
                }
                break;

            case self::TYPE_LONG:
                for ($i = 0; $i < $componentCount; ++$i) {
                    $values[] = $buf->$readerU32();
                }
                break;

            case self::TYPE_SLONG:
                for ($i = 0; $i < $componentCount; ++$i) {
                    $unsigned = $buf->$readerS32();
                    $values[] = $this->toSigned32($unsigned);
                }
                break;

            case self::TYPE_RATIONAL:
            case self::TYPE_SRATIONAL:
                for ($i = 0; $i < $componentCount; ++$i) {
                    $numerator   = $buf->$readerU32();
                    $denominator = $buf->$readerU32();
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
     * specified by EXIF 3.0 §4.6.3 (with unchanged semantics from EXIF 2.32
     * §4.6.3).
     *
     * @return list<MpfEntry>
     */
    private function parseEntries(string $data, Endian $endian): array
    {
        $entrySize = 16;
        $length    = strlen($data);
        if ($length === 0 || $length % $entrySize !== 0) {
            throw new ParseError('MPEntry data length is not a multiple of 16 bytes');
        }

        $buffer    = new MemoryBuffer($data);
        $readerU16 = $endian === Endian::Little ? 'readU16LE' : 'readU16BE';
        $readerU32 = $endian === Endian::Little ? 'readU32LE' : 'readU32BE';

        $entries = [];
        $count   = (int) ($length / $entrySize);
        for ($i = 0; $i < $count; ++$i) {
            $attributes = $buffer->$readerU32();
            $size       = $buffer->$readerU32();
            $offset     = $buffer->$readerU32();
            $dep1       = $buffer->$readerU16();
            $dep2       = $buffer->$readerU16();

            // The MP Entry tuple mirrors the Attribute, Size, Offset, and
            // Dependent image fields mandated by EXIF 3.0 §4.6.3 / EXIF 2.32
            // §4.6.3.
            $entries[] = new MpfEntry($attributes, $size, $offset, $dep1, $dep2);
        }

        return $entries;
    }

    /**
     * Builds the MP attribute structure from the decoded entries.
     *
     * EXIF 3.0 §4.6.4 defines the optional MP Attribute IFD, retaining the
     * same tag semantics documented in EXIF 2.32 §4.6.4.
     */
    private function buildAttributes(array $entries): MpfAttributes
    {
        $imageUidList          = $this->stringValue($entries[self::TAG_IMAGE_UID_LIST] ?? null);
        $totalFrames           = $this->intValue($entries[self::TAG_TOTAL_FRAMES] ?? null);
        $individualImageNumber = $this->intValue($entries[self::TAG_INDIVIDUAL_IMAGE_NUMBER] ?? null);

        $panoramaAngle = $entries[self::TAG_PANORAMA_ANGLE] ?? null;
        if ($panoramaAngle !== null && !is_array($panoramaAngle)) {
            $panoramaAngle = null;
        }

        $panoramaAxis = $entries[self::TAG_PANORAMA_AXIS] ?? null;
        if ($panoramaAxis !== null && !is_array($panoramaAxis)) {
            $panoramaAxis = null;
        }

        $known = [
            self::TAG_IMAGE_UID_LIST          => true,
            self::TAG_TOTAL_FRAMES            => true,
            self::TAG_INDIVIDUAL_IMAGE_NUMBER => true,
            self::TAG_PANORAMA_ANGLE          => true,
            self::TAG_PANORAMA_AXIS           => true,
        ];

        $additional = array_diff_key($entries, $known);

        return new MpfAttributes(
            imageUidList: $imageUidList,
            totalFrames: $totalFrames,
            individualImageNumber: $individualImageNumber,
            panoramaAngle: is_array($panoramaAngle) ? array_values($panoramaAngle) : null,
            panoramaAxis: is_array($panoramaAxis) ? array_values($panoramaAxis) : null,
            additionalTags: $additional,
        );
    }

    /**
     * Converts arbitrary decoded value into an integer when possible.
     *
     * @param int|string|array|null $value
     * @phpstan-param MpfDecodedValue|null $value
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
     * @param int|string|array|null $value
     * @phpstan-param MpfDecodedValue|null $value
     */
    private function stringValue(int|string|array|null $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        return rtrim($value, "\0");
    }

    private function readU16(MemoryBuffer $buffer, Endian $endian): int
    {
        return $endian === Endian::Little ? $buffer->readU16LE() : $buffer->readU16BE();
    }

    private function readU32(MemoryBuffer $buffer, Endian $endian): int
    {
        return $endian === Endian::Little ? $buffer->readU32LE() : $buffer->readU32BE();
    }

    private function typeSize(int $type): ?int
    {
        return match ($type) {
            self::TYPE_BYTE, self::TYPE_ASCII, self::TYPE_UNDEFINED => 1,
            self::TYPE_SHORT                                         => 2,
            self::TYPE_LONG, self::TYPE_SLONG                       => 4,
            self::TYPE_RATIONAL, self::TYPE_SRATIONAL               => 8,
            default                                                  => null,
        };
    }

    private function packInt(int $value, Endian $endian): string
    {
        return pack($endian === Endian::Little ? 'V' : 'N', $value);
    }
}
