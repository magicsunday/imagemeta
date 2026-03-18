<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Jpeg;

use Closure;
use MagicSunday\ImageMeta\Core\BitMask;
use MagicSunday\ImageMeta\Core\Endian;
use MagicSunday\ImageMeta\Core\MemoryBuffer;
use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Model\Mpf\MpfAttributes;
use MagicSunday\ImageMeta\Model\Mpf\MpfDocument;
use MagicSunday\ImageMeta\Model\Mpf\MpfEntry;
use MagicSunday\ImageMeta\Model\Tiff\TiffFieldType;
use MagicSunday\ImageMeta\Parse\ParserLimits;
use MagicSunday\ImageMeta\Parse\Tiff\TiffConst;
use MagicSunday\ImageMeta\Value\Enum\MpImageDataFormat;
use MagicSunday\ImageMeta\Value\Enum\MpImageType;

use function count;
use function is_int;
use function is_string;
use function pack;
use function sprintf;
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
    private const int TIFF_MAGIC                  = TiffConst::MAGIC_CLASSIC;

    // ── MP Index IFD tags (CIPA DC-007-2025, Table 3) ────────────────
    private const int TAG_MPF_VERSION             = 0xB000;

    private const int TAG_NUMBER_OF_IMAGES        = 0xB001;

    private const int TAG_MP_ENTRY                = 0xB002;

    private const int TAG_IMAGE_UID_LIST          = 0xB003;

    private const int TAG_TOTAL_FRAMES            = 0xB004;

    // ── MP Attribute IFD tags (CIPA DC-007-2025, Table 5) ──────────
    private const int TAG_INDIVIDUAL_IMAGE_NUMBER = 0xB101;

    private const int TAG_PAN_ORIENTATION         = 0xB201;

    private const int TAG_PAN_OVERLAP_H           = 0xB202;

    private const int TAG_PAN_OVERLAP_V           = 0xB203;

    private const int TAG_BASE_VIEWPOINT_NUM      = 0xB204;

    private const int TAG_CONVERGENCE_ANGLE       = 0xB205;

    private const int TAG_BASELINE_LENGTH         = 0xB206;

    private const int TAG_VERTICAL_DIVERGENCE     = 0xB207;

    private const int TAG_AXIS_DISTANCE_X         = 0xB208;

    private const int TAG_AXIS_DISTANCE_Y         = 0xB209;

    private const int TAG_AXIS_DISTANCE_Z         = 0xB20A;

    private const int TAG_YAW_ANGLE               = 0xB20B;

    private const int TAG_PITCH_ANGLE             = 0xB20C;

    private const int TAG_ROLL_ANGLE              = 0xB20D;

    /**
     * Decodes the MPF payload into a structured document model.
     *
     * The MP Index IFD is a TIFF IFD located at the offset stored after the
     * TIFF magic in the MPF header (EXIF 3.0 §4.6.2) and uses the standard
     * TIFF byte-order indicators (EXIF 3.0 §4.6.1).
     *
     * @throws ParseError When the MPF payload is malformed or contains invalid structures.
     */
    public function parse(string $payload): MpfDocument
    {
        $buffer                         = new MemoryBuffer($payload);

        if ($buffer->size() < 8) {
            throw new ParseError('MPF payload shorter than TIFF header', 1288);
        }

        $byteOrder                      = $buffer->read(2);
        // EXIF 3.0 §4.6.1 restricts MPF to the standard TIFF byte-order signatures "II" or "MM".
        $endian                         = match ($byteOrder) {
            Endian::Little->value => Endian::Little,
            Endian::Big->value    => Endian::Big,
            default               => throw new ParseError('MPF payload contains invalid byte order', 1289),
        };

        $magic                          = $this->readU16($buffer, $endian);

        if ($magic !== self::TIFF_MAGIC) {
            throw new ParseError('MPF payload missing TIFF magic', 1290);
        }

        $firstIfdOffset                 = $this->readU32($buffer, $endian);

        // The MP Index IFD offset is stored as a 32-bit value relative to the TIFF header (EXIF 3.0 §4.6.2).
        if ($firstIfdOffset < 8 || $firstIfdOffset >= $buffer->size()) {
            throw new ParseError('MP Index IFD offset outside payload bounds', 1291);
        }

        // MP Index IFD tag type/count constraints per CIPA DC-007-2025, Table 3
        $indexConstraints               = [
            self::TAG_MPF_VERSION => [
                'type'    => TiffFieldType::Ascii->value,
                'countFn' => static fn (int $c): bool => $c === 4,
            ],
            self::TAG_NUMBER_OF_IMAGES => [
                'type'    => TiffFieldType::Long->value,
                'countFn' => static fn (int $c): bool => $c === 1,
            ],
            self::TAG_MP_ENTRY => [
                'type'    => TiffFieldType::Undefined->value,
                'countFn' => static fn (int $c): bool => $c >= 16 && ($c % 16) === 0,
            ],
            self::TAG_IMAGE_UID_LIST => [
                'type'    => TiffFieldType::Undefined->value,
                'countFn' => static fn (int $c): bool => $c >= 33 && ($c % 33) === 0,
            ],
            self::TAG_TOTAL_FRAMES => [
                'type'    => TiffFieldType::Long->value,
                'countFn' => static fn (int $c): bool => $c === 1,
            ],
        ];

        [$indexEntries, $nextIfdOffset] = $this->readIfd($buffer, $endian, $firstIfdOffset, $indexConstraints);

        $version                        = $this->stringValue($indexEntries[self::TAG_MPF_VERSION] ?? null);
        $imageCount                     = $this->intValue($indexEntries[self::TAG_NUMBER_OF_IMAGES] ?? null);
        $imageUidList                   = $this->stringValue($indexEntries[self::TAG_IMAGE_UID_LIST] ?? null);
        $totalFrames                    = $this->intValue($indexEntries[self::TAG_TOTAL_FRAMES] ?? null);

        $entriesData                    = $indexEntries[self::TAG_MP_ENTRY] ?? null;

        if (!is_string($entriesData)) {
            throw new ParseError('MP Index IFD missing MPEntry data', 1292);
        }

        $entries                        = $this->parseEntries($entriesData, $endian);

        if ($imageCount === null) {
            $imageCount = count($entries);
        }

        if ($imageCount !== count($entries)) {
            throw new ParseError('MP Entry list length does not match reported image count', 1293);
        }

        $attributes                     = null;

        if ($nextIfdOffset !== 0) {
            // Non-zero next-IFD offsets must not point into the TIFF header (bytes 0..7).
            if ($nextIfdOffset < 8) {
                throw new ParseError('MP Index IFD next offset points into TIFF header', 1405);
            }

            // When present, the MP Attribute IFD follows the MP Index IFD and shares the same offset semantics (EXIF 3.0 §4.6.4).
            if ($nextIfdOffset >= $buffer->size()) {
                throw new ParseError('MP Attribute IFD offset outside payload bounds', 1294);
            }

            // MP Attribute IFD tag type/count constraints per CIPA DC-007-2025, Table 5
            $attributeConstraints                     = [
                self::TAG_INDIVIDUAL_IMAGE_NUMBER => [
                    'type'    => TiffFieldType::Long->value,
                    'countFn' => static fn (int $c): bool => $c === 1,
                ],
                self::TAG_PAN_ORIENTATION => [
                    'type'    => TiffFieldType::Long->value,
                    'countFn' => static fn (int $c): bool => $c === 1,
                ],
                self::TAG_PAN_OVERLAP_H => [
                    'type'    => TiffFieldType::Rational->value,
                    'countFn' => static fn (int $c): bool => $c === 1,
                ],
                self::TAG_PAN_OVERLAP_V => [
                    'type'    => TiffFieldType::Rational->value,
                    'countFn' => static fn (int $c): bool => $c === 1,
                ],
                self::TAG_BASE_VIEWPOINT_NUM => [
                    'type'    => TiffFieldType::Long->value,
                    'countFn' => static fn (int $c): bool => $c === 1,
                ],
                self::TAG_CONVERGENCE_ANGLE => [
                    'type'    => TiffFieldType::SRational->value,
                    'countFn' => static fn (int $c): bool => $c === 1,
                ],
                self::TAG_BASELINE_LENGTH => [
                    'type'    => TiffFieldType::Rational->value,
                    'countFn' => static fn (int $c): bool => $c === 1,
                ],
                self::TAG_VERTICAL_DIVERGENCE => [
                    'type'    => TiffFieldType::SRational->value,
                    'countFn' => static fn (int $c): bool => $c === 1,
                ],
                self::TAG_AXIS_DISTANCE_X => [
                    'type'    => TiffFieldType::SRational->value,
                    'countFn' => static fn (int $c): bool => $c === 1,
                ],
                self::TAG_AXIS_DISTANCE_Y => [
                    'type'    => TiffFieldType::SRational->value,
                    'countFn' => static fn (int $c): bool => $c === 1,
                ],
                self::TAG_AXIS_DISTANCE_Z => [
                    'type'    => TiffFieldType::SRational->value,
                    'countFn' => static fn (int $c): bool => $c === 1,
                ],
                self::TAG_YAW_ANGLE => [
                    'type'    => TiffFieldType::SRational->value,
                    'countFn' => static fn (int $c): bool => $c === 1,
                ],
                self::TAG_PITCH_ANGLE => [
                    'type'    => TiffFieldType::SRational->value,
                    'countFn' => static fn (int $c): bool => $c === 1,
                ],
                self::TAG_ROLL_ANGLE => [
                    'type'    => TiffFieldType::SRational->value,
                    'countFn' => static fn (int $c): bool => $c === 1,
                ],
            ];

            [$attributeEntries, $attributeNextOffset] = $this->readIfd($buffer, $endian, $nextIfdOffset, $attributeConstraints);

            // EXIF 3.0 MPF defines at most two IFDs (Index + Attribute); no further chaining is allowed.
            if ($attributeNextOffset !== 0) {
                throw new ParseError('MP Attribute IFD must not chain to a further IFD', 1406);
            }

            $attributes                               = $this->buildAttributes($attributeEntries);
        }

        return new MpfDocument(
            version: $version,
            imageCount: $imageCount,
            entries: $entries,
            attributes: $attributes,
            imageUidList: $imageUidList,
            totalFrames: $totalFrames,
        );
    }

    /**
     * Parses an MPF-specific TIFF IFD from the buffer.
     *
     * Both the MP Index IFD and MP Attribute IFD re-use the classic TIFF IFD layout (EXIF 3.0 §4.6.2/§4.6.4).
     *
     * @param array<int, array{type: int, countFn: Closure(int):bool}> $constraints Per-tag type/count constraints.
     *
     * @return array{0: MpfDirectory, 1: int}
     */
    private function readIfd(MemoryBuffer $buffer, Endian $endian, int $offset, array $constraints = []): array
    {
        $buffer->seek($offset);

        $entryCount  = $this->readU16($buffer, $endian);

        if ($entryCount < 0 || $entryCount > ParserLimits::MAX_MPF_IFD_ENTRIES) {
            throw new ParseError('MPF IFD entry count outside supported range', 1295);
        }

        $entries     = [];
        $previousTag = -1;

        for ($i = 0; $i < $entryCount; ++$i) {
            $tag            = $this->readU16($buffer, $endian);
            $type           = $this->readU16($buffer, $endian);
            $componentCount = $this->readU32($buffer, $endian);

            // TIFF 6.0 requires IFD entries sorted by tag ID in ascending order.
            if ($tag <= $previousTag) {
                if ($tag === $previousTag) {
                    throw new ParseError(sprintf('MPF IFD contains duplicate tag 0x%04X', $tag), 1403);
                }

                throw new ParseError(
                    sprintf('MPF IFD tags not in ascending order (0x%04X after 0x%04X)', $tag, $previousTag),
                    1404,
                );
            }

            $previousTag    = $tag;

            if ($componentCount < 0 || $componentCount > ParserLimits::MAX_MPF_COMPONENT_COUNT) {
                throw new ParseError('MPF entry reports unreasonable component count', 1296);
            }

            // Enforce per-tag type/count constraints
            if (isset($constraints[$tag])) {
                $constraint = $constraints[$tag];

                if ($type !== $constraint['type']) {
                    throw new ParseError(
                        sprintf(
                            'MPF tag 0x%04X has type %d, expected %d',
                            $tag,
                            $type,
                            $constraint['type'],
                        ),
                        1979,
                    );
                }

                if (!($constraint['countFn'])($componentCount)) {
                    throw new ParseError(
                        sprintf(
                            'MPF tag 0x%04X has invalid count %d',
                            $tag,
                            $componentCount,
                        ),
                        1981,
                    );
                }
            }

            $valueOrOffset  = $this->readU32($buffer, $endian);
            $data           = $this->resolveValueData($buffer, $endian, $type, $componentCount, $valueOrOffset);

            $entries[$tag]  = $this->decodeValue($type, $componentCount, $data, $endian);
        }

        $nextOffset  = $this->readU32($buffer, $endian);

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
        $typeSize  = $this->typeSize($type);

        if ($typeSize === null) {
            throw new ParseError('Unsupported MPF field type ' . $type, 1297);
        }

        $byteCount = $componentCount * $typeSize;

        if ($byteCount === 0) {
            return '';
        }

        if ($byteCount <= 4) {
            // Inline MPF values use the same in-place storage rule as TIFF IFD
            // entries (EXIF 3.0 §4.6.2). For big-endian, lower-order bytes are
            // at the end of the 4-byte field per TIFF 6.0 Value/Offset semantics.
            $bytes = $this->packInt($valueOrOffset, $endian);

            if (($endian === Endian::Big) && ($byteCount < 4)) {
                return substr($bytes, 4 - $byteCount);
            }

            return substr($bytes, 0, $byteCount);
        }

        // EXIF 3.0 §4.6.2 stores larger MPF values out of line at offsets relative to the MPF TIFF header.
        if (($valueOrOffset < 8) || (($valueOrOffset + $byteCount) > $buffer->size())) {
            throw new ParseError('MPF value offset outside payload bounds', 1298);
        }

        $current   = $buffer->tell();
        $buffer->seek($valueOrOffset);
        $data      = $buffer->read($byteCount);
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
            case TiffConst::TYPE_BYTE:
                for ($i = 0; $i < $componentCount; ++$i) {
                    $values[] = $buffer->readU8();
                }

                break;

            case TiffConst::TYPE_ASCII:
            case TiffConst::TYPE_UNDEFINED:
                return $data;

            case TiffConst::TYPE_SHORT:
                for ($i = 0; $i < $componentCount; ++$i) {
                    $values[] = $this->readU16($buffer, $endian);
                }

                break;

            case TiffConst::TYPE_LONG:
                for ($i = 0; $i < $componentCount; ++$i) {
                    $values[] = $this->readU32($buffer, $endian);
                }

                break;

            case TiffConst::TYPE_SLONG:
                for ($i = 0; $i < $componentCount; ++$i) {
                    $unsigned = $this->readU32($buffer, $endian);
                    $values[] = $this->toSigned32($unsigned);
                }

                break;

            case TiffConst::TYPE_RATIONAL:
            case TiffConst::TYPE_SRATIONAL:
                for ($i = 0; $i < $componentCount; ++$i) {
                    $numerator   = $this->readU32($buffer, $endian);
                    $denominator = $this->readU32($buffer, $endian);

                    if ($type === TiffConst::TYPE_SRATIONAL) {
                        $numerator   = $this->toSigned32($numerator);
                        $denominator = $this->toSigned32($denominator);
                    }

                    $values[]    = [
                        'numerator'   => $numerator,
                        'denominator' => $denominator,
                    ];
                }

                break;

            default:
                throw new ParseError('Unsupported MPF field type ' . $type, 1299);
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
            throw new ParseError('MPEntry data length is not a multiple of 16 bytes', 1300);
        }

        $buffer    = new MemoryBuffer($data);

        $entries   = [];
        $count     = (int) ($length / $entrySize);

        for ($i = 0; $i < $count; ++$i) {
            $attributes            = $this->readU32($buffer, $endian);
            $size                  = $this->readU32($buffer, $endian);
            $offset                = $this->readU32($buffer, $endian);
            $dep1                  = $this->readU16($buffer, $endian);
            $dep2                  = $this->readU16($buffer, $endian);

            // Validate Individual Image Attribute bitfield per MPF spec
            // Bits 27..28 must be zero (reserved); CIPA DC-007-2025, §5.2.3.3, Figure 8
            $reservedBits          = ($attributes >> 27) & 0x03;

            if ($reservedBits !== 0) {
                throw new ParseError(
                    sprintf('MPEntry %d has non-zero reserved bits 27..28: 0x%08X', $i, $attributes),
                    1982,
                );
            }

            // Bits 24..26 encode type info; value 7 is reserved and must not be used
            $typeInfo              = ($attributes >> 24) & 0x07;

            if ($typeInfo === 7) {
                throw new ParseError(
                    sprintf('MPEntry %d has reserved type info value 7: 0x%08X', $i, $attributes),
                    1410,
                );
            }

            // Decompose the Individual Image Attribute bitfield (CIPA DC-007-2025, §5.2.3.3, Figure 8)
            $isDependentParent     = ($attributes & (1 << 31)) !== 0;
            $isDependentChild      = ($attributes & (1 << 30)) !== 0;
            $isRepresentativeImage = ($attributes & (1 << 29)) !== 0;
            $subType               = ($attributes >> 16) & 0xFF;
            $typeCode              = ($typeInfo << 16) | $subType;
            $imageDataFormat       = $attributes & 0x07;

            $entries[]             = new MpfEntry(
                $attributes,
                $size,
                $offset,
                $dep1,
                $dep2,
                $isDependentParent,
                $isDependentChild,
                $isRepresentativeImage,
                MpImageType::tryFrom($typeCode),
                MpImageDataFormat::tryFrom($imageDataFormat),
            );
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
        $individualImageNumber = $this->intValue($entries[self::TAG_INDIVIDUAL_IMAGE_NUMBER] ?? null);

        $known                 = [
            self::TAG_INDIVIDUAL_IMAGE_NUMBER => true,
        ];

        $additional            = $this->filterAdditionalTags($entries, $known);

        return new MpfAttributes(
            individualImageNumber: $individualImageNumber,
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
            TiffConst::TYPE_BYTE, TiffConst::TYPE_ASCII, TiffConst::TYPE_UNDEFINED => 1,
            TiffConst::TYPE_SHORT => 2,
            TiffConst::TYPE_LONG, TiffConst::TYPE_SLONG => 4,
            TiffConst::TYPE_RATIONAL, TiffConst::TYPE_SRATIONAL => 8,
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
