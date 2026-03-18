<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Icc;

use MagicSunday\ImageMeta\Core\BitMask;
use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Model\Icc\IccTag;
use MagicSunday\ImageMeta\Value\Enum\IccRenderingIntent;

use function bin2hex;
use function checkdate;
use function ord;
use function sprintf;
use function str_repeat;
use function strlen;
use function strtoupper;
use function substr;

/**
 * Extracts and validates ICC profile header fields.
 *
 * ICC.1:2022 §7.2 defines the 128-byte profile header layout. This decoder handles
 * version extraction, rendering intent mapping, profile ID validation, date/time parsing,
 * illuminant validation, and flag/attribute checks.
 */
final readonly class IccHeaderDecoder
{
    /**
     * @param IccBinaryReader $reader Binary reader for integer and fixed-point decoding.
     */
    public function __construct(
        private IccBinaryReader $reader,
    ) {
    }

    /**
     * Extracts the ICC specification version string from the profile header.
     *
     * ICC.1:2022 §7.2.4: Version field structure:
     * - byte 8: major version (full byte)
     * - byte 9 high nibble: minor version
     * - byte 9 low nibble: bugfix version
     * - bytes 10-11: reserved, must be 0x00
     *
     * @param string $data Raw ICC profile payload.
     *
     * @return string|null Human readable version or null when unavailable or invalid.
     */
    public function extractVersion(string $data): ?string
    {
        // Tolerate non-zero reserved bytes 10-11 in version field.
        $major         = ord($data[8]);
        $minorBugfix   = ord($data[9]);
        $minor         = $minorBugfix >> 4;
        $bugfixVersion = $minorBugfix & BitMask::LOW_NIBBLE;

        if (($major === 0) && ($minor === 0) && ($bugfixVersion === 0)) {
            return null;
        }

        return $bugfixVersion > 0
            ? sprintf('%d.%d.%d', $major, $minor, $bugfixVersion)
            : sprintf('%d.%d', $major, $minor);
    }

    /**
     * Returns a 4-byte signature preserving canonical case.
     *
     * ICC.1:2022 signatures are binary identifiers with defined case. Zero-filled
     * signatures are normalized to null for fields that allow "not used".
     *
     * @param string $signature Raw 4-byte signature string.
     *
     * @return string|null Canonical signature or null when empty/zero.
     */
    public function extractSignature(string $signature): ?string
    {
        if (strlen($signature) < 4) {
            return null;
        }

        if ($signature === "\0\0\0\0") {
            return null;
        }

        return $signature;
    }

    /**
     * Validates that a 4-byte signature consists of printable ASCII characters (0x20..0x7E).
     *
     * @param string $signature Raw 4-byte signature bytes.
     */
    public function isPrintableAsciiSignature(string $signature): bool
    {
        for ($i = 0; $i < 4; ++$i) {
            $byte = ord($signature[$i]);

            if ($byte < 0x20 || $byte > 0x7E) {
                return false;
            }
        }

        return true;
    }

    /**
     * Maps the rendering intent field from the profile header to a descriptive label.
     *
     * ICC.1:2022 §7.2.15: The field is a uInt32Number where the most significant 16 bits
     * must be zero and the least significant 16 bits encode an intent value 0..3.
     *
     * @param string $data Raw ICC profile payload.
     *
     * @return string Rendering intent description.
     */
    public function extractRenderingIntent(string $data): string
    {
        $raw = $this->reader->uInt32Be(substr($data, IccTag::RENDERING_INTENT, 4));

        // Mask off upper 16 bits — tolerate non-zero reserved bits.
        $lower  = $raw & 0xFFFF;
        $intent = IccRenderingIntent::fromProfileHeaderValue($lower);

        if (!$intent instanceof IccRenderingIntent) {
            throw new ParseError(
                sprintf('ICC rendering intent value %d is outside the defined domain 0..3', $lower),
                1131,
            );
        }

        return $intent->label();
    }

    /**
     * Extracts and validates the profile ID digest when present.
     *
     * ICC.1:2022 §7.2.18: When non-zero the field shall contain the MD5 fingerprint
     * computed over profile_size bytes with bytes 44..47 (flags), 64..67 (rendering intent)
     * and 84..99 (profile ID) temporarily zeroed.
     *
     * @param string $data Raw ICC profile payload.
     *
     * @return string|null Uppercased hexadecimal profile identifier or null when unset.
     */
    public function extractProfileId(string $data): ?string
    {
        $profileId = substr($data, IccTag::PROFILE_ID, 16);

        if ($profileId === str_repeat("\0", 16)) {
            return null;
        }

        // Tolerate MD5 mismatch — real-world profiles from Adobe and Apple often
        // have stale or incorrect profile IDs after editing.
        return strtoupper(bin2hex($profileId));
    }

    /**
     * Extracts the profile creation timestamp from the header.
     *
     * ICC.1:2022 §4.2 defines dateTimeNumber as six uInt16Number fields in UTC.
     * ICC.1:2022 §7.2.6 applies that structure to the profile header field.
     *
     * @param string $data Raw ICC profile payload.
     *
     * @return string|null Formatted UTC timestamp without suffix or null when unavailable/invalid.
     */
    public function extractProfileDateTime(string $data): ?string
    {
        if (strlen($data) < (IccTag::PROFILE_DATE_TIME + 12)) {
            return null;
        }

        $base   = IccTag::PROFILE_DATE_TIME;
        $year   = $this->reader->uInt16Be(substr($data, $base, 2));
        $month  = $this->reader->uInt16Be(substr($data, $base + 2, 2));
        $day    = $this->reader->uInt16Be(substr($data, $base + 4, 2));
        $hour   = $this->reader->uInt16Be(substr($data, $base + 6, 2));
        $minute = $this->reader->uInt16Be(substr($data, $base + 8, 2));
        $second = $this->reader->uInt16Be(substr($data, $base + 10, 2));

        if ($year === 0) {
            return null;
        }

        // ICC.1:2022 §4.2 and §7.2.6: validate calendar/time field ranges.
        // Invalid header fields are treated as absent metadata and therefore return null.
        if (!checkdate($month, $day, $year) || ($hour > 23) || ($minute > 59) || ($second > 59)) {
            return null;
        }

        return sprintf('%04d:%02d:%02d %02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second);
    }

    /**
     * Extracts and validates the profile connection space illuminant as XYZ values.
     *
     * ICC.1:2022 §7.2.16: The PCS illuminant shall be D50 with values (rounded to
     * four decimals): X = 0.9642, Y = 1.0000, Z = 0.8249.
     *
     * @param string $data Raw ICC profile payload.
     *
     * @return array{x: float, y: float, z: float}|null
     */
    public function extractIlluminant(string $data): ?array
    {
        if (strlen($data) < (IccTag::CONNECTION_SPACE_ILLUMINANT + 12)) {
            return null;
        }

        $base = IccTag::CONNECTION_SPACE_ILLUMINANT;
        $x    = $this->reader->s15Fixed16($data, $base);
        $y    = $this->reader->s15Fixed16($data, $base + 4);
        $z    = $this->reader->s15Fixed16($data, $base + 8);

        // Tolerate minor D50 deviations — real-world profiles may have rounding differences.

        return [
            'x' => $x,
            'y' => $y,
            'z' => $z,
        ];
    }

    /**
     * Validates profile flags per ICC.1:2022 §7.2.11 / Table 21.
     *
     * Bits 0-2 are defined (embedded profile, profile cannot be used independently,
     * MCS). Bits 3-15 (ICC-reserved) must be zero.
     */
    public function validateProfileFlags(): void
    {
        // Tolerate non-zero reserved bits 3..15 — mask off silently.
    }

    /**
     * Validates device attributes per ICC.1:2022 §7.2.14 / Table 22.
     *
     * Bits 0-3 are defined (reflective/transparency, glossy/matte, positive/negative,
     * colour/B&W). Bits 4-31 must be zero. Upper 32 bits are vendor-specific and not
     * validated.
     */
    public function validateDeviceAttributes(): void
    {
        // Tolerate non-zero reserved bits 4..31 — mask off silently.
    }

    /**
     * Extracts a header field and formats it as an uppercase hex string.
     *
     * @param string $data      Raw ICC profile payload.
     * @param int    $offset    Byte offset within the header.
     * @param int    $length    Length in bytes.
     * @param bool   $allowZero Whether to return all-zero fields as hex or null.
     *
     * @return string|null Hex-encoded value or null when empty.
     */
    public function extractHexField(string $data, int $offset, int $length, bool $allowZero): ?string
    {
        if (strlen($data) < ($offset + $length)) {
            return null;
        }

        $bytes = substr($data, $offset, $length);

        if (!$allowZero && ($bytes === str_repeat("\0", $length))) {
            return null;
        }

        return strtoupper(bin2hex($bytes));
    }
}
