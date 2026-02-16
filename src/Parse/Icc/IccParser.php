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

use function array_key_exists;
use function bin2hex;
use function checkdate;
use function function_exists;
use function iconv;
use function in_array;
use function is_array;
use function is_int;
use function mb_convert_encoding;
use function md5;
use function min;
use function ord;
use function round;
use function rtrim;
use function sprintf;
use function str_repeat;
use function str_starts_with;
use function strlen;
use function strtoupper;
use function substr;
use function substr_replace;
use function unpack;
use function usort;

/**
 * Decodes ICC profiles to expose header information and human readable tags.
 */
final class IccParser implements IccParserInterface
{
    private const int HEADER_LENGTH = 128;

    private const int TAG_RECORD_LENGTH = 12;

    private const string ICC_SIGNATURE = 'ICC_PROFILE\0';

    /**
     * ICC.1:2022 §7.2.9: Profile file signature field must contain 'acsp' (61637370h).
     */
    private const string PROFILE_SIGNATURE = 'acsp';

    /**
     * ICC.1:2022 Table 18: Allowed profile/device class signatures.
     *
     * @var list<string>
     */
    private const array ALLOWED_PROFILE_CLASSES = [
        'scnr', // Input device profile
        'mntr', // Display device profile
        'prtr', // Output device profile
        'link', // DeviceLink profile
        'spac', // ColorSpace profile
        'abst', // Abstract profile
        'nmcl', // NamedColor profile
    ];

    /**
     * ICC.1:2022 Table 19: Allowed data colour space signatures.
     *
     * @var list<string>
     */
    private const array ALLOWED_COLOR_SPACES = [
        'XYZ ', 'Lab ', 'Luv ', 'YCbr', 'Yxy ', 'RGB ', 'GRAY',
        'HSV ', 'HLS ', 'CMYK', 'CMY ', '2CLR', '3CLR', '4CLR',
        '5CLR', '6CLR', '7CLR', '8CLR', '9CLR', 'ACLR', 'BCLR',
        'CCLR', 'DCLR', 'ECLR', 'FCLR',
    ];

    /**
     * ICC.1:2022 §7.2.7: Allowed PCS signatures.
     *
     * @var list<string>
     */
    private const array ALLOWED_PCS = [
        'XYZ ',
        'Lab ',
    ];

    /**
     * ICC.1:2022 Table 20: Allowed primary platform signatures.
     *
     * @var list<string>
     */
    private const array ALLOWED_PLATFORMS = [
        'APPL', // Apple Computer, Inc.
        'MSFT', // Microsoft Corporation
        'SGI ', // Silicon Graphics, Inc.
        'SUNW', // Sun Microsystems, Inc.
    ];

    /**
     * Decodes the ICC profile payload by extracting header fields and well known tags.
     *
     * ICC.1:2022 §7 defines the profile header structure and §9 defines common tags.
     *
     * @param string|null        $profileData Raw ICC profile data when a complete payload is available.
     * @param array<int, string> $segments    ICC segments collected from APP2 markers ordered by appearance.
     *
     * @return array{
     *     description: string|null,
     *     copyright: string|null,
     *     whitePoint: array{x: float, y: float, z: float}|null,
     *     version: string|null,
     *     pcs: string|null,
     *     renderingIntent: string|null,
     *     profileId: string|null,
     *     cmmType: string|null,
     *     profileClass: string|null,
     *     colorSpace: string|null,
     *     profileDateTime: string|null,
     *     profileDateTimeUtc: string|null,
     *     profileSignature: string|null,
     *     profileFlags: string|null,
     *     primaryPlatform: string|null,
     *     deviceManufacturer: string|null,
     *     deviceModel: string|null,
     *     deviceAttributes: string|null,
     *     profileCreator: string|null,
     *     illuminant: array{x: float, y: float, z: float}|null,
     * }|null
     */
    public function decode(?string $profileData, array $segments = []): ?array
    {
        $data = $profileData;
        if ($data === null || strlen($data) < self::HEADER_LENGTH) {
            $combined = $this->combineSegments($segments);
            if ($combined !== null) {
                $data = $combined;
            }
        }

        // No ICC data at all — return null (absence, not error)
        if ($data === null) {
            return null;
        }

        // ICC data present but too short — malformed
        if (strlen($data) < self::HEADER_LENGTH) {
            throw new ParseError(
                sprintf(
                    'ICC profile data too short: need at least %d header bytes, got %d',
                    self::HEADER_LENGTH,
                    strlen($data),
                ),
                1442,
            );
        }

        $profileSize = $this->uInt32Be(substr($data, IccTag::PROFILE_SIZE, 4));
        $length      = strlen($data);

        // ICC.1:2022 §7.2.2: Profile size must be at least the 128-byte header.
        if ($profileSize < self::HEADER_LENGTH) {
            throw new ParseError(
                sprintf('ICC declared profile size %d is less than the minimum header length %d', $profileSize, self::HEADER_LENGTH),
                1443,
            );
        }

        // ICC.1:2022 §7.1: Profile size and tag table entries must be 4-byte aligned.
        if (($profileSize % 4) !== 0) {
            throw new ParseError(
                sprintf('ICC declared profile size %d is not 4-byte aligned', $profileSize),
                1444,
            );
        }

        // ICC.1:2022 §7.2.2: Profile size must match the actual payload length.
        if ($profileSize !== $length) {
            throw new ParseError(
                sprintf('ICC declared profile size %d does not match actual payload length %d', $profileSize, $length),
                1445,
            );
        }

        // ICC.1:2022 §7.2.9: Validate 'acsp' signature at bytes 36-39
        $signature = substr($data, 36, 4);
        if ($signature !== self::PROFILE_SIGNATURE) {
            throw new ParseError(
                sprintf('ICC profile signature "%s" at offset 36 is not the required "acsp"', $signature),
                1446,
            );
        }

        // ICC.1:2022 §7.2.19: Reserved field (bytes 100-127) must be zero.
        $reserved = substr($data, IccTag::RESERVED, 28);
        if ($reserved !== str_repeat("\0", 28)) {
            throw new ParseError('ICC reserved field (bytes 100-127) is not zero', 1447);
        }

        // ICC.1:2022 §7.1: Tag data must follow the tag table with NULL padding.
        if (!$this->validateTagTable($data, $profileSize)) {
            throw new ParseError('ICC tag table layout or padding is invalid', 1448);
        }

        // ICC.1:2022 §7.2.4: Validate version field including reserved bytes
        $version = $this->extractVersion($data);
        if ($version === null) {
            throw new ParseError('ICC version field is invalid or has non-zero reserved bytes', 1449);
        }

        $profileClass = $this->extractSignature(substr($data, IccTag::PROFILE_CLASS, 4));
        $colorSpace   = $this->extractSignature(substr($data, IccTag::COLOR_SPACE, 4));
        $pcs          = $this->extractSignature(substr($data, IccTag::PCS, 4));

        // Validate constrained header signatures
        if ($profileClass !== null && !in_array($profileClass, self::ALLOWED_PROFILE_CLASSES, true)) {
            throw new ParseError(
                sprintf('ICC profile class signature "%s" is not in the allowed set', $profileClass),
                1134,
            );
        }

        if ($colorSpace !== null && !in_array($colorSpace, self::ALLOWED_COLOR_SPACES, true)) {
            throw new ParseError(
                sprintf('ICC data colour space signature "%s" is not in the allowed set', $colorSpace),
                1135,
            );
        }

        if ($pcs !== null && !in_array($pcs, self::ALLOWED_PCS, true)) {
            throw new ParseError(
                sprintf('ICC PCS signature "%s" is not XYZ or Lab', $pcs),
                1136,
            );
        }

        $renderingIntent    = $this->extractRenderingIntent($data);
        $profileId          = $this->extractProfileId($data);
        $majorVersion       = ord($data[8]);
        $description        = $this->extractTag($data, $profileSize, 'desc', $majorVersion);
        $copyright          = $this->extractTag($data, $profileSize, 'cprt', $majorVersion);
        $whitePoint         = $this->extractWhitePoint($data, $profileSize);
        $cmmType            = $this->extractSignature(substr($data, IccTag::CMM_TYPE, 4));
        $profileDateTime    = $this->extractProfileDateTime($data);
        $profileDateTimeUtc = $profileDateTime !== null ? ($profileDateTime . 'Z') : null;
        $profileSignature   = $this->extractSignature(substr($data, IccTag::PROFILE_SIGNATURE, 4));
        $profileFlags       = $this->extractHexField($data, IccTag::PROFILE_FLAGS, 4, true);
        $primaryPlatform    = $this->extractSignature(substr($data, IccTag::PRIMARY_PLATFORM, 4));
        $deviceManufacturer = $this->extractSignature(substr($data, IccTag::DEVICE_MANUFACTURER, 4));
        $deviceModel        = $this->extractSignature(substr($data, IccTag::DEVICE_MODEL, 4));
        $deviceAttributes   = $this->extractHexField($data, IccTag::DEVICE_ATTRIBUTES, 8, true);
        $profileCreator     = $this->extractSignature(substr($data, IccTag::PROFILE_CREATOR, 4));

        // Validate primary platform against ICC.1:2022 Table 20
        if ($primaryPlatform !== null && !in_array($primaryPlatform, self::ALLOWED_PLATFORMS, true)) {
            throw new ParseError(
                sprintf('ICC primary platform signature "%s" is not in the allowed set', $primaryPlatform),
                1143,
            );
        }

        // Validate profile creator as printable ASCII signature
        if ($profileCreator !== null && !$this->isPrintableAsciiSignature($profileCreator)) {
            throw new ParseError(
                sprintf(
                    'ICC profile creator signature contains non-printable bytes: %s',
                    strtoupper(bin2hex($profileCreator)),
                ),
                1144,
            );
        }

        // Validate CMM type as printable ASCII signature
        if ($cmmType !== null && !$this->isPrintableAsciiSignature($cmmType)) {
            throw new ParseError(
                sprintf(
                    'ICC CMM type signature contains non-printable bytes: %s',
                    strtoupper(bin2hex($cmmType)),
                ),
                1145,
            );
        }

        // Validate device manufacturer as printable ASCII signature
        if ($deviceManufacturer !== null && !$this->isPrintableAsciiSignature($deviceManufacturer)) {
            throw new ParseError(
                sprintf(
                    'ICC device manufacturer signature contains non-printable bytes: %s',
                    strtoupper(bin2hex($deviceManufacturer)),
                ),
                1146,
            );
        }

        // Validate device model as printable ASCII signature
        if ($deviceModel !== null && !$this->isPrintableAsciiSignature($deviceModel)) {
            throw new ParseError(
                sprintf(
                    'ICC device model signature contains non-printable bytes: %s',
                    strtoupper(bin2hex($deviceModel)),
                ),
                1147,
            );
        }

        // Validate profileFlags per ICC.1:2022 §7.2.11 / Table 21
        $this->validateProfileFlags($data);

        // Validate deviceAttributes per ICC.1:2022 §7.2.14 / Table 22
        $this->validateDeviceAttributes($data);
        $illuminant = $this->extractIlluminant($data);

        return [
            'description'        => $description,
            'copyright'          => $copyright,
            'whitePoint'         => $whitePoint,
            'version'            => $version,
            'pcs'                => $pcs,
            'renderingIntent'    => $renderingIntent,
            'profileId'          => $profileId,
            'cmmType'            => $cmmType,
            'profileClass'       => $profileClass,
            'colorSpace'         => $colorSpace,
            'profileDateTime'    => $profileDateTime,
            'profileDateTimeUtc' => $profileDateTimeUtc,
            'profileSignature'   => $profileSignature,
            'profileFlags'       => $profileFlags,
            'primaryPlatform'    => $primaryPlatform,
            'deviceManufacturer' => $deviceManufacturer,
            'deviceModel'        => $deviceModel,
            'deviceAttributes'   => $deviceAttributes,
            'profileCreator'     => $profileCreator,
            'illuminant'         => $illuminant,
        ];
    }

    /**
     * Attempts to reconstruct the ICC payload from APP2 ICC segments.
     *
     * @param array<int, string> $segments Ordered ICC segments as extracted from the JPEG stream.
     */
    private function combineSegments(array $segments): ?string
    {
        if ($segments === []) {
            return null;
        }

        $sequence      = [];
        $expectedCount = null;

        foreach ($segments as $payload) {
            if (!str_starts_with($payload, self::ICC_SIGNATURE)) {
                continue;
            }

            $minLength = strlen(self::ICC_SIGNATURE) + 2;
            if (strlen($payload) <= $minLength) {
                continue;
            }

            $sequenceNumber = ord($payload[strlen(self::ICC_SIGNATURE)]);
            $sequenceCount  = ord($payload[strlen(self::ICC_SIGNATURE) + 1]);

            if ($sequenceCount === 0) {
                throw new ParseError('ICC chunk assembly: sequence count is zero', 1126);
            }

            if ($expectedCount === null) {
                $expectedCount = $sequenceCount;
            } elseif ($expectedCount !== $sequenceCount) {
                throw new ParseError(
                    sprintf(
                        'ICC chunk assembly: inconsistent sequence count (%d vs %d)',
                        $expectedCount,
                        $sequenceCount,
                    ),
                    1127,
                );
            }

            // Reject out-of-range sequence numbers
            if ($sequenceNumber === 0 || $sequenceNumber > $sequenceCount) {
                throw new ParseError(
                    sprintf(
                        'ICC chunk assembly: sequence number %d is out of range 1..%d',
                        $sequenceNumber,
                        $sequenceCount,
                    ),
                    1128,
                );
            }

            // Reject duplicate sequence numbers
            if (array_key_exists($sequenceNumber, $sequence)) {
                throw new ParseError(
                    sprintf('ICC chunk assembly: duplicate sequence number %d', $sequenceNumber),
                    1129,
                );
            }

            $sequence[$sequenceNumber] = substr($payload, $minLength);
        }

        if ($expectedCount === null) {
            return null;
        }

        $iccData = '';
        for ($i = 1; $i <= $expectedCount; ++$i) {
            // Missing chunk in assembled sequence is an error, not absence
            if (!array_key_exists($i, $sequence)) {
                throw new ParseError(
                    sprintf(
                        'ICC chunk assembly: missing sequence number %d of %d',
                        $i,
                        $expectedCount,
                    ),
                    1441,
                );
            }

            $iccData .= $sequence[$i];
        }

        return $iccData;
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
    private function extractVersion(string $data): ?string
    {
        // ICC.1:2022 §7.2.4: bytes 10-11 (reserved) must be zero
        if (ord($data[10]) !== 0 || ord($data[11]) !== 0) {
            return null;
        }

        $major         = ord($data[8]);
        $minorBugfix   = ord($data[9]);
        $minor         = $minorBugfix >> 4;
        $bugfixVersion = $minorBugfix & BitMask::LOW_NIBBLE;

        if ($major === 0 && $minor === 0 && $bugfixVersion === 0) {
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
     * signatures are normalised to null for fields that allow "not used".
     *
     * @param string $signature Raw 4-byte signature string.
     *
     * @return string|null Canonical signature or null when empty/zero.
     */
    private function extractSignature(string $signature): ?string
    {
        if ($signature === '' || strlen($signature) < 4) {
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
    private function isPrintableAsciiSignature(string $signature): bool
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
    private function extractRenderingIntent(string $data): string
    {
        $raw = $this->uInt32Be(substr($data, IccTag::RENDERING_INTENT, 4));

        // Upper 16 bits must be zero
        $upper = ($raw >> 16) & 0xFFFF;
        if ($upper !== 0) {
            throw new ParseError(
                sprintf('ICC rendering intent upper 16 bits are non-zero: 0x%04X', $upper),
                1130,
            );
        }

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
    private function extractProfileId(string $data): ?string
    {
        $profileId = substr($data, IccTag::PROFILE_ID, 16);
        if ($profileId === str_repeat("\0", 16)) {
            return null;
        }

        // Compute expected MD5 per §7.2.18
        $zeroed = $data;
        // Zero profile flags (bytes 44..47)
        $zeroed = substr_replace($zeroed, "\0\0\0\0", 44, 4);
        // Zero rendering intent (bytes 64..67)
        $zeroed = substr_replace($zeroed, "\0\0\0\0", 64, 4);
        // Zero profile ID (bytes 84..99)
        $zeroed = substr_replace($zeroed, str_repeat("\0", 16), 84, 16);

        $computed = md5($zeroed, true);

        if ($computed !== $profileId) {
            throw new ParseError(
                sprintf(
                    'ICC Profile ID mismatch: stored %s, computed %s',
                    strtoupper(bin2hex($profileId)),
                    strtoupper(bin2hex($computed)),
                ),
                1132,
            );
        }

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
    private function extractProfileDateTime(string $data): ?string
    {
        if (strlen($data) < (IccTag::PROFILE_DATE_TIME + 12)) {
            return null;
        }

        $base   = IccTag::PROFILE_DATE_TIME;
        $year   = $this->uInt16Be(substr($data, $base, 2));
        $month  = $this->uInt16Be(substr($data, $base + 2, 2));
        $day    = $this->uInt16Be(substr($data, $base + 4, 2));
        $hour   = $this->uInt16Be(substr($data, $base + 6, 2));
        $minute = $this->uInt16Be(substr($data, $base + 8, 2));
        $second = $this->uInt16Be(substr($data, $base + 10, 2));

        if ($year === 0) {
            return null;
        }

        // ICC.1:2022 §4.2 and §7.2.6: validate calendar/time field ranges.
        // Invalid header fields are treated as absent metadata and therefore return null.
        if (
            !checkdate($month, $day, $year)
            || ($hour > 23)
            || ($minute > 59)
            || ($second > 59)
        ) {
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
    private function extractIlluminant(string $data): ?array
    {
        if (strlen($data) < (IccTag::CONNECTION_SPACE_ILLUMINANT + 12)) {
            return null;
        }

        $base = IccTag::CONNECTION_SPACE_ILLUMINANT;
        $x    = $this->s15Fixed16($data, $base);
        $y    = $this->s15Fixed16($data, $base + 4);
        $z    = $this->s15Fixed16($data, $base + 8);

        // Validate D50 requirement at 4-decimal rounding
        if (
            round($x, 4) !== 0.9642
            || round($y, 4) !== 1.0
            || round($z, 4) !== 0.8249
        ) {
            throw new ParseError(
                sprintf(
                    'ICC PCS illuminant is not D50: X=%.4f, Y=%.4f, Z=%.4f',
                    $x,
                    $y,
                    $z,
                ),
                1133,
            );
        }

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
     *
     * @param string $data Raw ICC profile payload.
     */
    private function validateProfileFlags(string $data): void
    {
        $flagsRaw = $this->uInt32Be(substr($data, IccTag::PROFILE_FLAGS, 4));

        // Bits 3..15 must be zero per ICC.1:2022 Table 21
        $reservedMask = 0xFFF8;
        if (($flagsRaw & $reservedMask) !== 0) {
            throw new ParseError(
                sprintf(
                    'ICC profileFlags reserved bits 3..15 are non-zero: 0x%08X',
                    $flagsRaw,
                ),
                1148,
            );
        }
    }

    /**
     * Validates device attributes per ICC.1:2022 §7.2.14 / Table 22.
     *
     * Bits 0-3 are defined (reflective/transparency, glossy/matte, positive/negative,
     * colour/B&W). Bits 4-31 must be zero. Upper 32 bits are vendor-specific and not
     * validated.
     *
     * @param string $data Raw ICC profile payload.
     */
    private function validateDeviceAttributes(string $data): void
    {
        // Read the lower 32 bits (bytes 60..63 in big-endian layout)
        $lower32 = $this->uInt32Be(substr($data, IccTag::DEVICE_ATTRIBUTES + 4, 4));

        // Bits 4..31 of the lower 32-bit word must be zero per ICC.1:2022 Table 22
        $reservedMask = 0xFFFFFFF0;
        if (($lower32 & $reservedMask) !== 0) {
            throw new ParseError(
                sprintf(
                    'ICC deviceAttributes reserved bits 4..31 are non-zero: 0x%08X',
                    $lower32,
                ),
                1149,
            );
        }
    }

    /**
     * Extracts a header field and formats it as an uppercase hex string.
     *
     * @param string $data   Raw ICC profile payload.
     * @param int    $offset Byte offset within the header.
     * @param int    $length Length in bytes.
     *
     * @return string|null Hex-encoded value or null when empty.
     */
    private function extractHexField(string $data, int $offset, int $length, bool $allowZero): ?string
    {
        if (strlen($data) < ($offset + $length)) {
            return null;
        }

        $bytes = substr($data, $offset, $length);
        if (!$allowZero && $bytes === str_repeat("\0", $length)) {
            return null;
        }

        return strtoupper(bin2hex($bytes));
    }

    /**
     * Extracts a text tag (desc, cprt) from the tag table.
     *
     * ICC.1:2022 §9.2.22 (copyrightTag) and §9.2.43 (profileDescriptionTag):
     * the permitted type for both tags is multiLocalizedUnicodeType (mluc).
     * Legacy profiles (major version < 4) may use descType or textType as
     * fallback per ICC.1:2001 §6.5.17 and §6.5.22.
     *
     * @param string $data         Raw ICC profile payload.
     * @param int    $profileSize  Declared profile size limiting the accessible range.
     * @param string $tagSignature Tag signature to search for ('desc' or 'cprt').
     * @param int    $majorVersion Profile major version for tag-type conformance gating.
     *
     * @return string|null Tag text or null when not available.
     */
    private function extractTag(string $data, int $profileSize, string $tagSignature, int $majorVersion): ?string
    {
        $tagData = $this->findTagData($data, $profileSize, $tagSignature);
        if ($tagData === null) {
            return null;
        }

        $type = substr($tagData, 0, 4);

        if ($type === 'mluc') {
            return $this->parseMlucTag($tagData);
        }

        // ICC v4+: only multiLocalizedUnicodeType is conforming for cprt/desc
        if ($majorVersion >= 4) {
            return null;
        }

        // Legacy fallbacks for ICC v2/v3 profiles
        if ($type === 'desc') {
            return $this->parseDescTag($tagData);
        }

        // ICC.1:2001 §6.5.18 textType
        if ($type === 'text') {
            return $this->parseTextTag($tagData);
        }

        return null;
    }

    /**
     * Extracts the media white point (wtpt) from the tag table.
     *
     * ICC.1:2022 §9.2.34 (mediaWhitePointTag) uses XYZType (§10.31).
     *
     * @param string $data        Raw ICC profile payload.
     * @param int    $profileSize Declared profile size limiting the accessible range.
     *
     * @return array{x: float, y: float, z: float}|null XYZ tristimulus values or null when not available.
     */
    private function extractWhitePoint(string $data, int $profileSize): ?array
    {
        $tagData = $this->findTagData($data, $profileSize, 'wtpt');
        if ($tagData === null || strlen($tagData) < 20) {
            return null;
        }

        $type = substr($tagData, 0, 4);
        if ($type !== 'XYZ ') {
            return null;
        }

        // ICC.1:2022 §10.31 reserved bytes 4..7 must be zero
        $reserved = substr($tagData, 4, 4);
        if ($reserved !== "\0\0\0\0") {
            throw new ParseError('ICC wtpt XYZType reserved bytes 4..7 are non-zero', 1141);
        }

        // Wtpt must contain exactly one XYZNumber (20 bytes total)
        if (strlen($tagData) !== 20) {
            throw new ParseError(
                sprintf('ICC wtpt XYZType payload must be exactly 20 bytes, got %d', strlen($tagData)),
                1142,
            );
        }

        // ICC.1:2022 §10.31: XYZType contains XYZNumber at offset 8
        // XYZNumber is 3 x s15Fixed16Number (each 4 bytes)
        return [
            'x' => $this->s15Fixed16($tagData, 8),
            'y' => $this->s15Fixed16($tagData, 12),
            'z' => $this->s15Fixed16($tagData, 16),
        ];
    }

    /**
     * Finds tag data by signature in the tag table.
     *
     * @param string $data         Raw ICC profile payload.
     * @param int    $profileSize  Declared profile size limiting the accessible range.
     * @param string $tagSignature 4-byte tag signature to search for.
     *
     * @return string|null Raw tag data or null when not found.
     */
    private function findTagData(string $data, int $profileSize, string $tagSignature): ?string
    {
        if ($profileSize < self::HEADER_LENGTH + 4) {
            return null;
        }

        $length         = min(strlen($data), $profileSize);
        $tagCountOffset = self::HEADER_LENGTH;
        if ($tagCountOffset + 4 > $length) {
            return null;
        }

        $tagCount = $this->uInt32Be(substr($data, $tagCountOffset, 4));
        $cursor   = $tagCountOffset + 4;
        $tableEnd = $tagCountOffset + 4 + ($tagCount * self::TAG_RECORD_LENGTH);
        if ($tableEnd > $length) {
            return null;
        }

        for ($i = 0; $i < $tagCount; ++$i) {
            if ($cursor + self::TAG_RECORD_LENGTH > $length) {
                break;
            }

            $signature = substr($data, $cursor, 4);
            $offset    = $this->uInt32Be(substr($data, $cursor + 4, 4));
            $size      = $this->uInt32Be(substr($data, $cursor + 8, 4));
            $cursor += self::TAG_RECORD_LENGTH;

            if ($signature !== $tagSignature) {
                continue;
            }

            if (($offset % 4) !== 0) {
                continue;
            }

            if (($size % 4) !== 0) {
                continue;
            }

            if ($offset < $tableEnd) {
                continue;
            }

            if ($size === 0) {
                continue;
            }

            if (($offset + $size) > $length) {
                continue;
            }

            return substr($data, $offset, $size);
        }

        return null;
    }

    /**
     * Validates the tag table layout and padding rules.
     *
     * ICC.1:2022 §7.1: Tag data begins immediately after the tag table and
     * padding between tag data blocks (and after the last block) is NULL.
     */
    private function validateTagTable(string $data, int $profileSize): bool
    {
        if ($profileSize < self::HEADER_LENGTH + 4) {
            return false;
        }

        $length         = min(strlen($data), $profileSize);
        $tagCountOffset = self::HEADER_LENGTH;
        if (($tagCountOffset + 4) > $length) {
            return false;
        }

        $tagCount = $this->uInt32Be(substr($data, $tagCountOffset, 4));
        $tableEnd = $tagCountOffset + 4 + ($tagCount * self::TAG_RECORD_LENGTH);
        if ($tableEnd > $length) {
            return false;
        }

        $entries   = [];
        $seenSigs  = [];
        $offsetMap = [];
        $cursor    = $tagCountOffset + 4;

        for ($i = 0; $i < $tagCount; ++$i) {
            if (($cursor + self::TAG_RECORD_LENGTH) > $length) {
                return false;
            }

            $signature = substr($data, $cursor, 4);
            $offset    = $this->uInt32Be(substr($data, $cursor + 4, 4));
            $size      = $this->uInt32Be(substr($data, $cursor + 8, 4));
            $cursor += self::TAG_RECORD_LENGTH;

            // ICC.1:2022 §7.3: Tag signatures must be unique.
            if (isset($seenSigs[$signature])) {
                return false;
            }

            $seenSigs[$signature] = true;

            if ($size === 0) {
                continue;
            }

            if ((($offset % 4) !== 0) || (($size % 4) !== 0)) {
                return false;
            }

            if ($offset < $tableEnd) {
                return false;
            }

            if (($offset + $size) > $length) {
                return false;
            }

            // ICC.1:2022 §7.3: Shared offsets must have identical sizes.
            if (isset($offsetMap[$offset])) {
                if ($offsetMap[$offset] !== $size) {
                    return false;
                }
            } else {
                $offsetMap[$offset] = $size;
            }

            $entries[] = [
                'offset' => $offset,
                'size'   => $size,
            ];
        }

        // Deduplicate entries with shared offsets (already validated for size equality).
        $uniqueEntries = [];
        foreach ($offsetMap as $offset => $size) {
            $uniqueEntries[] = [
                'offset' => $offset,
                'size'   => $size,
            ];
        }

        if ($uniqueEntries === []) {
            return $this->paddingIsNull($data, $tableEnd, $length - $tableEnd);
        }

        usort(
            $uniqueEntries,
            static fn (array $left, array $right): int => $left['offset'] <=> $right['offset'],
        );

        if ($uniqueEntries[0]['offset'] !== $tableEnd) {
            return false;
        }

        $cursor = $tableEnd;

        foreach ($uniqueEntries as $entry) {
            // ICC.1:2022 §7.3: Tag data elements form a contiguous sequence.
            if ($entry['offset'] !== $cursor) {
                return false;
            }

            $cursor = $entry['offset'] + $entry['size'];
        }

        // ICC.1:2022 §7.3: The contiguous sequence must cover the full profile payload.
        return $cursor === $length;
    }

    /**
     * Confirms that the specified range is fully NULL padded.
     */
    private function paddingIsNull(string $data, int $offset, int $length): bool
    {
        if ($length <= 0) {
            return true;
        }

        return substr($data, $offset, $length) === str_repeat("\0", $length);
    }

    /**
     * Parses an ICC 'text' tag (textType) to retrieve its ASCII text.
     *
     * ICC.1:2022 §10.1: all tag types begin with signature (4) + reserved bytes (4, must be zero).
     * ICC.1:2022 §10.24: textType payload stores 7-bit ASCII text after that header.
     * Text must be 7-bit ASCII (all bytes <= 0x7F) and terminated with a NUL byte.
     *
     * @param string $data Raw tag payload beginning with the type signature.
     *
     * @return string|null Extracted text or null when invalid.
     */
    private function parseTextTag(string $data): ?string
    {
        if (strlen($data) <= 8) {
            return null;
        }

        // ICC.1:2022 §10.1 + §10.24 reserved bytes 4..7 must be zero.
        $reserved = substr($data, 4, 4);
        if ($reserved !== "\0\0\0\0") {
            return null;
        }

        $text = substr($data, 8);

        // ICC.1:2022 §10.24: textType must end with a NUL byte
        if ($text === '' || $text[-1] !== "\0") {
            return null;
        }

        // ICC.1:2022 §10.24: textType must contain only 7-bit ASCII (bytes <= 0x7F)
        // Validate all non-NUL bytes are 7-bit ASCII
        for ($i = 0, $len = strlen($text) - 1; $i < $len; ++$i) {
            if (ord($text[$i]) > 0x7F) {
                return null;
            }
        }

        return rtrim($text, "\0");
    }

    /**
     * Parses an ICC 'desc' tag to retrieve its ASCII description.
     *
     * ICC.1:2022 §10.1: all tag types begin with signature (4) + reserved bytes (4, must be zero).
     * ICC.1:2001 §6.5.17 describes the legacy descType payload layout:
     * - bytes 0-3: 'desc' signature
     * - bytes 4-7: reserved (0)
     * - bytes 8-11: ASCII description length (including NUL) as uint32 BE
     * - bytes 12..12+len-1: ASCII description string (NUL-terminated)
     *
     * @param string $data Raw tag payload beginning with the type signature.
     *
     * @return string|null Extracted description or null when invalid.
     */
    private function parseDescTag(string $data): ?string
    {
        if (strlen($data) < 12) {
            return null;
        }

        // ICC.1:2022 §10.1 reserved bytes 4..7 must be zero.
        $reserved = substr($data, 4, 4);
        if ($reserved !== "\0\0\0\0") {
            return null;
        }

        $asciiLength = $this->uInt32Be(substr($data, 8, 4));
        if ($asciiLength === 0) {
            return null;
        }

        $available = strlen($data) - 12;
        if ($asciiLength > $available) {
            return null;
        }

        $text = substr($data, 12, $asciiLength);

        // ICC spec: desc ASCII string must be NUL-terminated
        if ($text === '' || $text[-1] !== "\0") {
            return null;
        }

        // ICC spec: desc ASCII string must contain only 7-bit ASCII (bytes <= 0x7F)
        // Validate all non-NUL bytes are 7-bit ASCII
        for ($i = 0, $len = strlen($text) - 1; $i < $len; ++$i) {
            if (ord($text[$i]) > 0x7F) {
                return null;
            }
        }

        return rtrim($text, "\0");
    }

    /**
     * Parses an ICC 'mluc' tag with deterministic language-aware record selection.
     *
     * ICC.1:2022 §10.13: multiLocalizedUnicodeType stores locale-qualified
     * strings. Selection policy:
     * 1. Prefer 'enUS' record when present.
     * 2. Fall back to any 'en' language record.
     * 3. Otherwise use the first non-empty record.
     *
     * @param string $data Raw tag payload beginning with the type signature.
     *
     * @return string|null Extracted description string or null when no valid record exists.
     */
    private function parseMlucTag(string $data): ?string
    {
        $length = strlen($data);
        if ($length < 16) {
            return null;
        }

        // ICC.1:2022 §10.1 + Table 54 reserved bytes 4..7 must be zero.
        $reserved = substr($data, 4, 4);
        if ($reserved !== "\0\0\0\0") {
            throw new ParseError('ICC mluc reserved bytes 4..7 are non-zero', 1137);
        }

        $recordCount = $this->uInt32Be(substr($data, 8, 4));
        $recordSize  = $this->uInt32Be(substr($data, 12, 4));

        if ($recordCount === 0) {
            return null;
        }

        // RecordSize must be exactly 12
        if ($recordSize !== 12) {
            throw new ParseError(
                sprintf('ICC mluc recordSize must be 12, got %d', $recordSize),
                1138,
            );
        }

        // Record table must fit within payload
        $tableEnd = 16 + ($recordCount * $recordSize);
        if ($tableEnd > $length) {
            throw new ParseError('ICC mluc record table exceeds payload bounds', 1139);
        }

        // Decode all records with their locale tags for deterministic selection
        $firstNonEmpty = null;
        $enAny         = null;
        $enUs          = null;

        $cursor = 16;
        for ($i = 0; $i < $recordCount; ++$i) {
            $lang         = substr($data, $cursor, 2);
            $country      = substr($data, $cursor + 2, 2);
            $stringLength = $this->uInt32Be(substr($data, $cursor + 4, 4));
            $stringOffset = $this->uInt32Be(substr($data, $cursor + 8, 4));
            $cursor += $recordSize;

            if ($stringLength === 0) {
                continue;
            }

            // Each record's string must be fully bounded within payload
            if ($stringOffset + $stringLength > $length) {
                throw new ParseError(
                    sprintf(
                        'ICC mluc record %d string range [%d..%d) exceeds payload length %d',
                        $i,
                        $stringOffset,
                        $stringOffset + $stringLength,
                        $length,
                    ),
                    1140,
                );
            }

            $raw = substr($data, $stringOffset, $stringLength);
            $utf = $this->decodeUtf16Be($raw);
            if ($utf === null) {
                continue;
            }

            if ($utf === '') {
                continue;
            }

            $firstNonEmpty ??= $utf;

            if ($lang === 'en') {
                $enAny ??= $utf;

                if ($country === 'US' || $country === "\0\0") {
                    $enUs ??= $utf;
                }
            }
        }

        return $enUs ?? $enAny ?? $firstNonEmpty;
    }

    /**
     * Converts a UTF-16BE encoded string to UTF-8 when possible.
     *
     * @param string $data Raw UTF-16BE encoded bytes.
     *
     * @return string|null Converted UTF-8 string or null when conversion fails.
     */
    private function decodeUtf16Be(string $data): ?string
    {
        if ($data === '') {
            return null;
        }

        // ICC.1:2022 §10.13: UTF-16BE must consist of complete code units.
        if ((strlen($data) % 2) !== 0) {
            throw new ParseError('Odd-length UTF-16BE payload in ICC mluc record', 1123);
        }

        if (function_exists('mb_convert_encoding')) {
            return mb_convert_encoding($data, 'UTF-8', 'UTF-16BE');
        }

        if (function_exists('iconv')) {
            // Strict conversion without //IGNORE to reject malformed sequences.
            $converted = iconv('UTF-16BE', 'UTF-8', $data);

            return $converted === false ? null : $converted;
        }

        // No Imagick fallback; return null when no pure-PHP conversion is available
        return null;
    }

    /**
     * Converts exactly four bytes into an unsigned big-endian integer.
     *
     * @param string $bytes Raw bytes to interpret as a big-endian integer.
     *
     * @return int Parsed unsigned integer value.
     */
    private function uInt32Be(string $bytes): int
    {
        if (strlen($bytes) !== 4) {
            throw new ParseError(
                sprintf('ICC uInt32 field truncated: expected 4 bytes, got %d', strlen($bytes)),
                1124,
            );
        }

        $unpacked = unpack('Nvalue', $bytes);
        if (!is_array($unpacked) || !array_key_exists('value', $unpacked)) {
            throw new ParseError('Unexpected integer value while decoding ICC profile.', 1124);
        }

        $value = $unpacked['value'];
        if (!is_int($value)) {
            throw new ParseError('Unexpected integer value while decoding ICC profile.', 1124);
        }

        return $value;
    }

    /**
     * Converts exactly two bytes into an unsigned big-endian integer.
     *
     * @param string $bytes Raw bytes to interpret as a big-endian integer.
     *
     * @return int Parsed unsigned integer value.
     */
    private function uInt16Be(string $bytes): int
    {
        if (strlen($bytes) !== 2) {
            throw new ParseError(
                sprintf('ICC uInt16 field truncated: expected 2 bytes, got %d', strlen($bytes)),
                1125,
            );
        }

        $unpacked = unpack('nvalue', $bytes);
        if (!is_array($unpacked) || !array_key_exists('value', $unpacked)) {
            throw new ParseError('Unexpected integer value while decoding ICC profile.', 1125);
        }

        $value = $unpacked['value'];
        if (!is_int($value)) {
            throw new ParseError('Unexpected integer value while decoding ICC profile.', 1125);
        }

        return $value;
    }

    /**
     * Parses an s15Fixed16Number from tag data at the given offset.
     *
     * ICC.1:2022 §4.6: s15Fixed16Number is a signed 32-bit fixed-point number
     * with 16 fractional bits. The value is calculated as: raw_value / 65536.0
     *
     * @param string $data   Raw tag data.
     * @param int    $offset Byte offset within the data.
     *
     * @return float Parsed fixed-point value as a float.
     */
    private function s15Fixed16(string $data, int $offset): float
    {
        $bytes = substr($data, $offset, 4);
        if (strlen($bytes) < 4) {
            return 0.0;
        }

        // Unpack as unsigned 32-bit big-endian
        $unpacked = @unpack('Nvalue', $bytes);
        if (!is_array($unpacked) || !array_key_exists('value', $unpacked)) {
            return 0.0;
        }

        $unsigned = $unpacked['value'];
        if (!is_int($unsigned)) {
            return 0.0;
        }

        // Convert to signed if necessary (two's complement)
        $signed = $unsigned >= 0x80000000
            ? $unsigned - 0x100000000
            : $unsigned;

        // Convert fixed-point to float (16 fractional bits)
        return $signed / 65536.0;
    }
}
