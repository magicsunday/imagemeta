<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Riff;

use MagicSunday\ImageMeta\Core\Util\Unpack;
use MagicSunday\ImageMeta\Model\Riff\NikonCameraTags;

use function round;
use function rtrim;
use function sprintf;
use function strlen;
use function substr;

/**
 * Parses Nikon Camera Tags (nctg) TLV payloads from RIFF/AVI containers.
 *
 * The nctg chunk uses a flat Tag-Length-Value format:
 *   [tag: u16 LE] [size: u16 LE] [value: size bytes]
 *
 * Types are implied by tag ID. Unknown tags are preserved in the entries array.
 *
 * Reference: ExifTool Nikon.pm %Image::ExifTool::Nikon::AVITags.
 */
final class NctgParser
{
    /**
     * Minimum TLV header size: 2-byte tag + 2-byte size.
     */
    private const int TLV_HEADER_SIZE = 4;

    // Known tag IDs — string type
    private const int TAG_MAKE = 0x0003;

    private const int TAG_MODEL = 0x0004;

    private const int TAG_SOFTWARE = 0x0005;

    private const int TAG_EQUIPMENT = 0x0006;

    private const int TAG_DATE_TIME_ORIGINAL = 0x0013;

    private const int TAG_CREATE_DATE = 0x0014;

    private const int TAG_FOCUS_MODE = 0x0018;

    private const int TAG_COLOR_MODE = 0x001D;

    private const int TAG_SHARPNESS = 0x001E;

    private const int TAG_WHITE_BALANCE = 0x001F;

    private const int TAG_NOISE_REDUCTION = 0x0020;

    // Known tag IDs — unsigned rational
    private const int TAG_EXPOSURE_TIME = 0x0008;

    private const int TAG_F_NUMBER = 0x0009;

    private const int TAG_MAX_APERTURE = 0x000B;

    private const int TAG_FOCAL_LENGTH = 0x000F;

    private const int TAG_X_RESOLUTION = 0x0010;

    private const int TAG_Y_RESOLUTION = 0x0011;

    private const int TAG_DURATION = 0x0016;

    private const int TAG_DIGITAL_ZOOM = 0x001B;

    // Known tag IDs — signed rational
    private const int TAG_EXPOSURE_COMP = 0x000A;

    // Known tag IDs — unsigned short
    private const int TAG_ORIENTATION = 0x0007;

    private const int TAG_METERING_MODE = 0x000C;

    private const int TAG_RESOLUTION_UNIT = 0x0012;

    /**
     * Set of tag IDs that carry string values.
     *
     * @var array<int, true>
     */
    private const array STRING_TAGS = [
        self::TAG_MAKE               => true,
        self::TAG_MODEL              => true,
        self::TAG_SOFTWARE           => true,
        self::TAG_EQUIPMENT          => true,
        self::TAG_DATE_TIME_ORIGINAL => true,
        self::TAG_CREATE_DATE        => true,
        self::TAG_FOCUS_MODE         => true,
        self::TAG_COLOR_MODE         => true,
        self::TAG_SHARPNESS          => true,
        self::TAG_WHITE_BALANCE      => true,
        self::TAG_NOISE_REDUCTION    => true,
    ];

    /**
     * Set of tag IDs that carry unsigned rational values (2x u32 LE).
     *
     * @var array<int, true>
     */
    private const array UNSIGNED_RATIONAL_TAGS = [
        self::TAG_EXPOSURE_TIME => true,
        self::TAG_F_NUMBER      => true,
        self::TAG_MAX_APERTURE  => true,
        self::TAG_FOCAL_LENGTH  => true,
        self::TAG_X_RESOLUTION  => true,
        self::TAG_Y_RESOLUTION  => true,
        self::TAG_DURATION      => true,
        self::TAG_DIGITAL_ZOOM  => true,
    ];

    /**
     * Set of tag IDs that carry signed rational values (signed i32 numerator + u32 denominator).
     *
     * @var array<int, true>
     */
    private const array SIGNED_RATIONAL_TAGS = [
        self::TAG_EXPOSURE_COMP => true,
    ];

    /**
     * Set of tag IDs that carry unsigned short (u16 LE) values.
     *
     * @var array<int, true>
     */
    private const array SHORT_TAGS = [
        self::TAG_ORIENTATION     => true,
        self::TAG_METERING_MODE   => true,
        self::TAG_RESOLUTION_UNIT => true,
    ];

    /**
     * Parses a raw nctg chunk payload into a NikonCameraTags value object.
     *
     * Follows Postel's Law: tolerates truncated trailing tags, unknown tag IDs,
     * and zero denominators in rationals. Returns null only when the payload is
     * too short to contain any complete tag.
     */
    public function parse(string $payload): ?NikonCameraTags
    {
        $length = strlen($payload);

        if ($length < self::TLV_HEADER_SIZE) {
            return null;
        }

        $entries      = [];
        $parsedAnyTag = false;

        // Typed fields
        $make             = null;
        $model            = null;
        $software         = null;
        $equipment        = null;
        $orientation      = null;
        $exposureTime     = null;
        $fNumber          = null;
        $exposureComp     = null;
        $maxAperture      = null;
        $meteringMode     = null;
        $focalLength      = null;
        $dateTimeOriginal = null;
        $createDate       = null;
        $duration         = null;
        $focusMode        = null;
        $digitalZoom      = null;
        $whiteBalance     = null;

        $offset = 0;

        while (($offset + self::TLV_HEADER_SIZE) <= $length) {
            $tag  = Unpack::int('v', substr($payload, $offset, 2), 'nctg tag');
            $size = Unpack::int('v', substr($payload, $offset + 2, 2), 'nctg size');

            $valueOffset = $offset + self::TLV_HEADER_SIZE;

            if (($valueOffset + $size) > $length) {
                break; // Postel's Law: truncated trailing entry
            }

            $valueBytes = substr($payload, $valueOffset, $size);

            if ($size > 0) {
                $parsedAnyTag = true;
            }

            $this->assignTag(
                $tag,
                $valueBytes,
                $size,
                $entries,
                $make,
                $model,
                $software,
                $equipment,
                $orientation,
                $exposureTime,
                $fNumber,
                $exposureComp,
                $maxAperture,
                $meteringMode,
                $focalLength,
                $dateTimeOriginal,
                $createDate,
                $duration,
                $focusMode,
                $digitalZoom,
                $whiteBalance,
            );

            $offset = $valueOffset + $size;
        }

        if (!$parsedAnyTag) {
            return null;
        }

        return new NikonCameraTags(
            $entries,
            $make,
            $model,
            $software,
            $equipment,
            $orientation,
            $exposureTime,
            $fNumber,
            $exposureComp,
            $maxAperture,
            $meteringMode,
            $focalLength,
            $dateTimeOriginal,
            $createDate,
            $duration,
            $focusMode,
            $digitalZoom,
            $whiteBalance,
        );
    }

    /**
     * Assigns a parsed tag value to the appropriate typed field and entries array.
     *
     * @param array<int, string> $entries
     */
    private function assignTag(
        int $tag,
        string $bytes,
        int $size,
        array &$entries,
        ?string &$make,
        ?string &$model,
        ?string &$software,
        ?string &$equipment,
        ?int &$orientation,
        ?float &$exposureTime,
        ?float &$fNumber,
        ?float &$exposureComp,
        ?float &$maxAperture,
        ?int &$meteringMode,
        ?float &$focalLength,
        ?string &$dateTimeOriginal,
        ?string &$createDate,
        ?float &$duration,
        ?string &$focusMode,
        ?float &$digitalZoom,
        ?string &$whiteBalance,
    ): void {
        if (isset(self::STRING_TAGS[$tag])) {
            $str = rtrim($bytes, "\x00");

            if ($str === '') {
                return;
            }

            $entries[$tag] = $str;

            match ($tag) {
                self::TAG_MAKE               => $make             = $str,
                self::TAG_MODEL              => $model            = $str,
                self::TAG_SOFTWARE           => $software         = $str,
                self::TAG_EQUIPMENT          => $equipment        = $str,
                self::TAG_DATE_TIME_ORIGINAL => $dateTimeOriginal = $str,
                self::TAG_CREATE_DATE        => $createDate       = $str,
                self::TAG_FOCUS_MODE         => $focusMode        = $str,
                self::TAG_WHITE_BALANCE      => $whiteBalance     = $str,
                default                      => null,
            };

            return;
        }

        if (isset(self::UNSIGNED_RATIONAL_TAGS[$tag])) {
            [$display, $value] = $this->parseUnsignedRational($bytes, $size);

            if ($display !== null) {
                $entries[$tag] = $display;
            }

            match ($tag) {
                self::TAG_EXPOSURE_TIME => $exposureTime = $value,
                self::TAG_F_NUMBER      => $fNumber      = $value,
                self::TAG_MAX_APERTURE  => $maxAperture  = $value,
                self::TAG_FOCAL_LENGTH  => $focalLength  = $value,
                self::TAG_DURATION      => $duration     = $value,
                self::TAG_DIGITAL_ZOOM  => $digitalZoom  = $value,
                default                 => null,
            };

            return;
        }

        if (isset(self::SIGNED_RATIONAL_TAGS[$tag])) {
            [$display, $value] = $this->parseSignedRational($bytes, $size);

            if ($display !== null) {
                $entries[$tag] = $display;
            }

            $exposureComp = $value;

            return;
        }

        if (isset(self::SHORT_TAGS[$tag])) {
            [$display, $value] = $this->parseShort($bytes, $size);

            if ($display !== null) {
                $entries[$tag] = $display;
            }

            match ($tag) {
                self::TAG_ORIENTATION   => $orientation  = $value,
                self::TAG_METERING_MODE => $meteringMode = $value,
                default                 => null,
            };

            return;
        }

        // Unknown tag — infer display from size
        [$display] = $this->parseUnknownTag($bytes, $size);

        if ($display !== null) {
            $entries[$tag] = $display;
        }
    }

    /**
     * Parses an unsigned rational (2x u32 LE). Returns null typed value on zero denominator.
     *
     * @return array{string|null, float|null}
     */
    private function parseUnsignedRational(string $bytes, int $size): array
    {
        if ($size < 8) {
            return [null, null];
        }

        $num = Unpack::int('V', substr($bytes, 0, 4), 'nctg rational num');
        $den = Unpack::int('V', substr($bytes, 4, 4), 'nctg rational den');

        if ($den === 0) {
            return [null, null];
        }

        $value   = $num / $den;
        $display = $this->formatRational($num, $den);

        return [$display, $value];
    }

    /**
     * Parses a signed rational (signed i32 numerator LE + u32 denominator LE).
     *
     * @return array{string|null, float|null}
     */
    private function parseSignedRational(string $bytes, int $size): array
    {
        if ($size < 8) {
            return [null, null];
        }

        $rawNum = Unpack::int('V', substr($bytes, 0, 4), 'nctg srational num');
        $den    = Unpack::int('V', substr($bytes, 4, 4), 'nctg srational den');

        if ($den === 0) {
            return [null, null];
        }

        // Convert unsigned to signed 32-bit
        $num = $rawNum >= 0x80000000 ? $rawNum - 0x100000000 : $rawNum;

        $value   = $num / $den;
        $display = $this->formatRational($num, $den);

        return [$display, $value];
    }

    /**
     * Parses an unsigned short (u16 LE).
     *
     * @return array{string|null, int|null}
     */
    private function parseShort(string $bytes, int $size): array
    {
        if ($size < 2) {
            return [null, null];
        }

        $value = Unpack::int('v', substr($bytes, 0, 2), 'nctg short');

        return [(string) $value, $value];
    }

    /**
     * Best-effort display for unknown tags.
     *
     * @return array{string|null, null}
     */
    private function parseUnknownTag(string $bytes, int $size): array
    {
        if ($size === 0) {
            return [null, null];
        }

        if ($size === 2) {
            $value = Unpack::int('v', substr($bytes, 0, 2), 'nctg unknown u16');

            return [(string) $value, null];
        }

        if ($size === 4) {
            $value = Unpack::int('V', substr($bytes, 0, 4), 'nctg unknown u32');

            return [(string) $value, null];
        }

        if ($size === 8) {
            $num = Unpack::int('V', substr($bytes, 0, 4), 'nctg unknown rational num');
            $den = Unpack::int('V', substr($bytes, 4, 4), 'nctg unknown rational den');

            if ($den !== 0) {
                return [$this->formatRational($num, $den), null];
            }

            return [(string) $num, null];
        }

        // Variable-length: try as string
        $str = rtrim($bytes, "\x00");

        return [$str !== '' ? $str : '(binary)', null];
    }

    /**
     * Formats a rational as a clean decimal or fraction string.
     */
    private function formatRational(int $num, int $den): string
    {
        if ($den === 1) {
            return (string) $num;
        }

        $value = $num / $den;

        // If the result is a clean decimal (e.g. 2.8), show as float
        return sprintf('%g', round($value, 6));
    }
}
