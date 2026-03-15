<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\MakerNotes\Apple;

use MagicSunday\ImageMeta\Core\Util\Unpack;

use function is_array;
use function rtrim;
use function strlen;
use function substr;

/**
 * Parses Apple JPEG MakerNote payloads that use the "Apple iOS\0" TIFF IFD format.
 *
 * JPEG images from Apple devices embed maker notes as a standard TIFF IFD prefixed with
 * a 10-byte signature, 2-byte version, and a full TIFF header (byte order + magic + IFD offset).
 * This parser extracts known tags and returns a string-keyed dictionary compatible with
 * {@see AppleMakerNotesBuilder::build()}.
 *
 * @phpstan-type NativePlistScalar bool|float|int|string|null
 * @phpstan-type NativePlistValue NativePlistScalar|array<int|string, NativePlistScalar|array<int|string, NativePlistScalar|array<int|string, NativePlistScalar|array<int|string, NativePlistScalar|array<int|string, NativePlistScalar|array<int|string, NativePlistScalar|array<int|string, NativePlistScalar>>>>>>>
 * @phpstan-type NativePlistDictionary array<string, NativePlistValue>
 * @phpstan-type IfdTagValue int|float|string|NativePlistDictionary|list<float>|list<int>
 */
final readonly class AppleJpegIfdParser
{
    private const string SIGNATURE = "Apple iOS\x00";

    private const int SIGNATURE_LENGTH = 10;

    private const int VERSION_LENGTH = 2;

    private const int TIFF_HEADER_OFFSET = self::SIGNATURE_LENGTH + self::VERSION_LENGTH;

    private const int IFD_ENTRY_SIZE = 12;

    /** Signature(10) + version(2) + byte order(2) + entry count(2) */
    private const int MIN_PAYLOAD_LENGTH = 16;

    private const int TIFF_TYPE_ASCII = 2;

    private const int TIFF_TYPE_UNDEFINED = 7;

    private const int TIFF_TYPE_SLONG = 9;

    private const int TIFF_TYPE_SRATIONAL = 10;

    private const int TIFF_TYPE_LONG8 = 16;

    /**
     * Maps Apple JPEG MakerNote IFD tag numbers to builder-compatible dictionary keys.
     *
     * @var array<int, string>
     */
    public const array TAG_MAP = [
        0x0001 => 'MakerNoteVersion',
        0x0002 => 'AEMatrix',
        0x0003 => 'RunTime',
        0x0004 => 'AEStable',
        0x0005 => 'AETarget',
        0x0006 => 'AEAverage',
        0x0007 => 'AFStable',
        0x0008 => 'AccelerationVector',
        0x000A => 'HDRImageType',
        0x000B => 'BurstUUID',
        0x000C => 'FocusDistanceRange',
        0x000D => 'OISMode',
        0x0011 => 'ContentIdentifier',
        0x0014 => 'ImageCaptureType',
        0x0017 => 'LivePhotoVideoIndex',
        0x0019 => 'ImageProcessingFlags',
        0x001A => 'QualityHint',
        0x001D => 'LuminanceNoiseAmplitude',
        0x001F => 'PhotosAppFeatureFlags',
        0x0020 => 'ImageCaptureRequestID',
        0x0021 => 'HDRHeadroom',
        0x0023 => 'AFPerformance',
        0x0025 => 'SceneFlags',
        0x0026 => 'SignalToNoiseRatioType',
        0x0027 => 'SNR',
        0x002B => 'PhotoIdentifier',
        0x002D => 'ColorTemperature',
        0x002E => 'CameraType',
        0x002F => 'FocusPosition',
        0x0030 => 'HDRGain',
        0x003F => 'GreenGhostMitigationStatus',
        0x0040 => 'SemanticStylePreset',
        0x0041 => 'SemanticStyleRenderingVer',
        0x004E => 'SemanticStyleData',
    ];

    /** Tags whose SLONG value should be stored as a string for builder compatibility. */
    private const array STRING_CAST_TAGS = [0x0040];

    private KeyedArchiveResolver $archiveResolver;

    public function __construct()
    {
        $this->archiveResolver = new KeyedArchiveResolver();
    }

    /**
     * Parses an Apple JPEG MakerNote TIFF IFD payload into a builder-compatible dictionary.
     *
     * @param string $raw Raw maker note payload bytes.
     *
     * @return array<string, IfdTagValue>|null Dictionary for AppleMakerNotesBuilder or null when unrecognized.
     */
    public function parse(string $raw): ?array
    {
        $length = strlen($raw);

        if ($length < self::MIN_PAYLOAD_LENGTH) {
            return null;
        }

        if (substr($raw, 0, self::SIGNATURE_LENGTH) !== self::SIGNATURE) {
            return null;
        }

        $byteOrder = substr($raw, self::TIFF_HEADER_OFFSET, 2);

        if ($byteOrder === 'MM') {
            $u16Fmt = 'n';
            $u32Fmt = 'N';
        } elseif ($byteOrder === 'II') {
            $u16Fmt = 'v';
            $u32Fmt = 'V';
        } else {
            return null;
        }

        // IFD entry count follows the byte order marker at offset 14; out-of-line
        // value offsets are relative to the start of the raw payload (offset 0).
        $offsetBase  = 0;
        $ifdAbsolute = self::TIFF_HEADER_OFFSET + 2;

        return $this->readIfd($raw, $offsetBase, $ifdAbsolute, $u16Fmt, $u32Fmt);
    }

    /**
     * Reads a single TIFF IFD and returns a dictionary of known tag values.
     *
     * @return array<string, IfdTagValue>|null
     */
    private function readIfd(string $raw, int $tiffBase, int $ifdStart, string $u16Fmt, string $u32Fmt): ?array
    {
        $length     = strlen($raw);
        $entryCount = Unpack::int($u16Fmt, substr($raw, $ifdStart, 2), 'Apple IFD entry count');

        $entriesStart = $ifdStart + 2;
        $entriesEnd   = $entriesStart + ($entryCount * self::IFD_ENTRY_SIZE);

        if ($entriesEnd > $length) {
            return null;
        }

        /** @var array<string, IfdTagValue> $dictionary */
        $dictionary = [];

        for ($i = 0; $i < $entryCount; ++$i) {
            $entryOffset = $entriesStart + ($i * self::IFD_ENTRY_SIZE);
            $entry       = $this->convertEntry($raw, $tiffBase, $entryOffset, $u16Fmt, $u32Fmt);

            if ($entry === null) {
                continue;
            }

            [$key, $value]    = $entry;
            $dictionary[$key] = $value;
        }

        return $dictionary !== [] ? $dictionary : null;
    }

    /**
     * Reads one 12-byte IFD entry and returns a [key, value] pair for known tags.
     *
     * @return array{0: string, 1: IfdTagValue}|null
     */
    private function convertEntry(string $raw, int $tiffBase, int $offset, string $u16Fmt, string $u32Fmt): ?array
    {
        $tag  = Unpack::int($u16Fmt, substr($raw, $offset, 2), 'Apple IFD tag');
        $type = Unpack::int($u16Fmt, substr($raw, $offset + 2, 2), 'Apple IFD type');
        $cnt  = Unpack::int($u32Fmt, substr($raw, $offset + 4, 4), 'Apple IFD count');

        $key        = self::TAG_MAP[$tag] ?? sprintf('Apple 0x%04x', $tag);
        $valueField = substr($raw, $offset + 8, 4);

        $value = $this->decodeTagValue($raw, $tiffBase, $tag, $type, $cnt, $valueField, $u32Fmt);

        if ($value === null) {
            return null;
        }

        return [$key, $value];
    }

    /**
     * Decodes the value for a single IFD entry based on its TIFF type.
     *
     * @return IfdTagValue|null
     */
    private function decodeTagValue(
        string $raw,
        int $tiffBase,
        int $tag,
        int $type,
        int $count,
        string $valueField,
        string $u32Fmt,
    ): int|float|string|array|null {
        return match ($type) {
            self::TIFF_TYPE_SLONG     => $this->decodeSLongTag($raw, $tiffBase, $tag, $count, $valueField, $u32Fmt),
            self::TIFF_TYPE_LONG8     => $this->decodeLong8Tag($raw, $tiffBase, $count, $valueField, $u32Fmt),
            self::TIFF_TYPE_SRATIONAL => $this->decodeSRationalTag($raw, $tiffBase, $count, $valueField, $u32Fmt),
            self::TIFF_TYPE_ASCII     => $this->decodeAsciiTag($raw, $tiffBase, $count, $valueField, $u32Fmt),
            self::TIFF_TYPE_UNDEFINED => $this->decodeUndefinedTag($raw, $tiffBase, $count, $valueField, $u32Fmt),
            default                   => null,
        };
    }

    /**
     * Decodes an SLONG tag (type 9, 4 bytes per component).
     *
     * For count=1 the inline value field is used directly.
     * For count>1 the value field is an offset to out-of-line data.
     *
     * @return int|string|list<int>|null
     */
    private function decodeSLongTag(
        string $raw,
        int $tiffBase,
        int $tag,
        int $count,
        string $valueField,
        string $u32Fmt,
    ): int|string|array|null {
        if ($count === 1) {
            $signed = $this->toSigned32(Unpack::int($u32Fmt, $valueField, 'Apple IFD SLONG'));

            if (in_array($tag, self::STRING_CAST_TAGS, true)) {
                return (string) $signed;
            }

            return $signed;
        }

        $data = $this->resolveData($raw, $tiffBase, $count * 4, $valueField, $u32Fmt);

        if ($data === null) {
            return null;
        }

        $values = [];

        for ($i = 0; $i < $count; ++$i) {
            $values[] = $this->toSigned32(Unpack::int($u32Fmt, substr($data, $i * 4, 4), 'Apple IFD SLONG[]'));
        }

        return $values;
    }

    /**
     * Decodes a LONG8 tag (type 16, 8 bytes per component, count=1 expected).
     *
     * The 4-byte value field contains an offset to the 8-byte value.
     */
    private function decodeLong8Tag(string $raw, int $tiffBase, int $count, string $valueField, string $u32Fmt): ?int
    {
        if ($count !== 1) {
            return null;
        }

        $data = $this->resolveData($raw, $tiffBase, 8, $valueField, $u32Fmt);

        if ($data === null) {
            return null;
        }

        $hi = Unpack::int($u32Fmt, substr($data, 0, 4), 'Apple IFD LONG8 hi');
        $lo = Unpack::int($u32Fmt, substr($data, 4, 4), 'Apple IFD LONG8 lo');

        return ($hi << 32) | $lo;
    }

    /**
     * Decodes an SRATIONAL tag (type 10, 8 bytes per component).
     *
     * @return float|list<float>|null
     */
    private function decodeSRationalTag(
        string $raw,
        int $tiffBase,
        int $count,
        string $valueField,
        string $u32Fmt,
    ): float|array|null {
        $dataBytes = $count * 8;
        $data      = $this->resolveData($raw, $tiffBase, $dataBytes, $valueField, $u32Fmt);

        if ($data === null) {
            return null;
        }

        if ($count === 1) {
            return $this->decodeSingleSRational($data, $u32Fmt);
        }

        return $this->decodeSRationalList($data, $count, $u32Fmt);
    }

    /**
     * Decodes an ASCII tag (type 2, 1 byte per component).
     */
    private function decodeAsciiTag(
        string $raw,
        int $tiffBase,
        int $count,
        string $valueField,
        string $u32Fmt,
    ): ?string {
        if ($count <= 4) {
            $data = substr($valueField, 0, $count);
        } else {
            $data = $this->resolveData($raw, $tiffBase, $count, $valueField, $u32Fmt);

            if ($data === null) {
                return null;
            }
        }

        $trimmed = rtrim($data, "\x00");

        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * Decodes an UNDEFINED tag (type 7).
     *
     * For the RunTime tag (0x0003), attempts binary plist decoding.
     *
     * @return NativePlistDictionary|string|null
     */
    private function decodeUndefinedTag(
        string $raw,
        int $tiffBase,
        int $count,
        string $valueField,
        string $u32Fmt,
    ): string|array|null {
        if ($count <= 4) {
            $data = substr($valueField, 0, $count);
        } else {
            $data = $this->resolveData($raw, $tiffBase, $count, $valueField, $u32Fmt);

            if ($data === null) {
                return null;
            }
        }

        $decoded = $this->archiveResolver->decodeBinaryPropertyList($data);

        if (is_array($decoded) && KeyedArchiveResolver::isStringKeyedDictionary($decoded)) {
            $resolved = $this->archiveResolver->resolveKeyedArchiveDictionary($decoded);

            if (is_array($resolved)) {
                return $resolved;
            }
        }

        return $data;
    }

    /**
     * Resolves data bytes for an IFD entry, either inline or from an offset.
     */
    private function resolveData(string $raw, int $tiffBase, int $dataBytes, string $valueField, string $u32Fmt): ?string
    {
        if ($dataBytes <= 4) {
            return substr($valueField, 0, $dataBytes);
        }

        $offset   = Unpack::int($u32Fmt, $valueField, 'Apple IFD value offset');
        $absolute = $tiffBase + $offset;

        if (($absolute + $dataBytes) > strlen($raw)) {
            return null;
        }

        return substr($raw, $absolute, $dataBytes);
    }

    /**
     * Decodes a single SRATIONAL (two signed 32-bit integers: numerator/denominator).
     */
    private function decodeSingleSRational(string $data, string $u32Fmt): ?float
    {
        $num = $this->toSigned32(Unpack::int($u32Fmt, substr($data, 0, 4), 'Apple IFD SRATIONAL num'));
        $den = $this->toSigned32(Unpack::int($u32Fmt, substr($data, 4, 4), 'Apple IFD SRATIONAL den'));

        if ($den === 0) {
            return null;
        }

        return (float) $num / (float) $den;
    }

    /**
     * Decodes multiple SRATIONALs into a list of floats, skipping entries with zero denominator.
     *
     * @return list<float>|null
     */
    private function decodeSRationalList(string $data, int $count, string $u32Fmt): ?array
    {
        $result = [];

        for ($i = 0; $i < $count; ++$i) {
            $pos = $i * 8;
            $num = $this->toSigned32(Unpack::int($u32Fmt, substr($data, $pos, 4), 'Apple IFD SRATIONAL num'));
            $den = $this->toSigned32(Unpack::int($u32Fmt, substr($data, $pos + 4, 4), 'Apple IFD SRATIONAL den'));

            if ($den === 0) {
                continue;
            }

            $result[] = (float) $num / (float) $den;
        }

        return $result !== [] ? $result : null;
    }

    /**
     * Converts an unsigned 32-bit integer to its signed representation.
     */
    private function toSigned32(int $u): int
    {
        $sign = 1 << 31;

        return (($u & $sign) !== 0) ? $u - (1 << 32) : $u;
    }
}
