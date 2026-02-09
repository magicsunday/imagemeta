<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Icc;

use Imagick;
use ImagickPixel;
use MagicSunday\ImageMeta\Core\BitMask;
use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Model\Icc\IccTag;
use MagicSunday\ImageMeta\Value\Enum\IccRenderingIntent;
use Throwable;

use function array_key_exists;
use function bin2hex;
use function class_exists;
use function function_exists;
use function iconv;
use function is_array;
use function is_int;
use function mb_convert_encoding;
use function min;
use function ord;
use function rtrim;
use function sprintf;
use function str_repeat;
use function str_starts_with;
use function strlen;
use function strtoupper;
use function substr;
use function unpack;
use function usort;

/**
 * Decodes ICC profiles to expose header information and human readable tags.
 */
final class IccParser
{
    private const int HEADER_LENGTH = 128;

    private const int TAG_RECORD_LENGTH = 12;

    private const string ICC_SIGNATURE = 'ICC_PROFILE\0';

    /**
     * ICC.1:2022 §7.2.9: Profile file signature field must contain 'acsp' (61637370h).
     */
    private const string PROFILE_SIGNATURE = 'acsp';

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
            $data = $this->combineSegments($segments);
        }

        if ($data === null || strlen($data) < self::HEADER_LENGTH) {
            return null;
        }

        $profileSize = $this->uInt32Be(substr($data, IccTag::PROFILE_SIZE, 4));
        $length      = strlen($data);

        // ICC.1:2022 §7.2.2: Profile size must be at least the 128-byte header.
        if ($profileSize < self::HEADER_LENGTH) {
            return null;
        }

        // ICC.1:2022 §7.1: Profile size and tag table entries must be 4-byte aligned.
        if (($profileSize % 4) !== 0) {
            return null;
        }

        // ICC.1:2022 §7.2.2: Profile size must match the actual payload length.
        if ($profileSize !== $length) {
            return null;
        }

        // ICC.1:2022 §7.2.9: Validate 'acsp' signature at bytes 36-39
        $signature = substr($data, 36, 4);
        if ($signature !== self::PROFILE_SIGNATURE) {
            return null; // invalid ICC profile
        }

        // ICC.1:2022 §7.1: Tag data must follow the tag table with NULL padding.
        if (!$this->validateTagTable($data, $profileSize)) {
            return null;
        }

        $version            = $this->extractVersion($data);
        $pcs                = $this->extractSignature(substr($data, IccTag::PCS, 4));
        $renderingIntent    = $this->extractRenderingIntent($data);
        $profileId          = $this->extractProfileId($data);
        $description        = $this->extractTag($data, $profileSize, 'desc');
        $copyright          = $this->extractTag($data, $profileSize, 'cprt');
        $whitePoint         = $this->extractWhitePoint($data, $profileSize);
        $cmmType            = $this->extractSignature(substr($data, IccTag::CMM_TYPE, 4));
        $profileClass       = $this->extractSignature(substr($data, IccTag::PROFILE_CLASS, 4));
        $colorSpace         = $this->extractSignature(substr($data, IccTag::COLOR_SPACE, 4));
        $profileDateTime    = $this->extractProfileDateTime($data);
        $profileSignature   = $this->extractSignature(substr($data, IccTag::PROFILE_SIGNATURE, 4));
        $profileFlags       = $this->extractHexField($data, IccTag::PROFILE_FLAGS, 4, true);
        $primaryPlatform    = $this->extractSignature(substr($data, IccTag::PRIMARY_PLATFORM, 4));
        $deviceManufacturer = $this->extractSignature(substr($data, IccTag::DEVICE_MANUFACTURER, 4));
        $deviceModel        = $this->extractSignature(substr($data, IccTag::DEVICE_MODEL, 4));
        $deviceAttributes   = $this->extractHexField($data, IccTag::DEVICE_ATTRIBUTES, 8, true);
        $profileCreator     = $this->extractSignature(substr($data, IccTag::PROFILE_CREATOR, 4));
        $illuminant         = $this->extractIlluminant($data);

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
                return null;
            }

            if ($expectedCount === null) {
                $expectedCount = $sequenceCount;
            } elseif ($expectedCount !== $sequenceCount) {
                return null; // conflicting counts
            }

            $sequence[$sequenceNumber] = substr($payload, $minLength);
        }

        if ($expectedCount === null) {
            return null;
        }

        $iccData = '';
        for ($i = 1; $i <= $expectedCount; ++$i) {
            if (!array_key_exists($i, $sequence)) {
                return null;
            }

            $iccData .= $sequence[$i];
        }

        return $iccData;
    }

    /**
     * Extracts the ICC specification version string from the profile header.
     *
     * @param string $data Raw ICC profile payload.
     *
     * @return string|null Human readable version or null when unavailable.
     */
    private function extractVersion(string $data): ?string
    {
        $majorByte     = ord($data[8]);
        $minorBugfix   = ord($data[9]);
        $major         = $majorByte;
        $minor         = $minorBugfix >> 4;
        $bugfixVersion = $minorBugfix & BitMask::LOW_NIBBLE;

        if ($majorByte >= BitMask::BIT_4) {
            $major         = $majorByte >> 4;
            $minor         = $majorByte & BitMask::LOW_NIBBLE;
            $bugfixVersion = $minorBugfix >> 4;
        }

        if ($major === 0 && $minor === 0 && $bugfixVersion === 0) {
            return null;
        }

        return $bugfixVersion > 0
            ? sprintf('%d.%d.%d', $major, $minor, $bugfixVersion)
            : sprintf('%d.%d', $major, $minor);
    }

    /**
     * Normalises a 4-byte signature by uppercasing and validating its contents.
     *
     * @param string $signature Raw 4-byte signature string.
     *
     * @return string|null Uppercased signature or null when invalid.
     */
    private function extractSignature(string $signature): ?string
    {
        if ($signature === '' || strlen($signature) < 4) {
            return null;
        }

        return strtoupper($signature);
    }

    /**
     * Maps the rendering intent field from the profile header to a descriptive label.
     *
     * @param string $data Raw ICC profile payload.
     *
     * @return string|null Rendering intent description or null when unknown.
     */
    private function extractRenderingIntent(string $data): ?string
    {
        $intent = $this->uInt32Be(substr($data, IccTag::RENDERING_INTENT, 4));

        return IccRenderingIntent::fromProfileHeaderValue($intent)?->label();
    }

    /**
     * Extracts the profile ID digest when present.
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

        return strtoupper(bin2hex($profileId));
    }

    /**
     * Extracts the profile creation timestamp from the header.
     *
     * ICC.1:2022 §7.2.6 defines the dateTimeNumber structure.
     *
     * @param string $data Raw ICC profile payload.
     *
     * @return string|null Formatted timestamp or null when unavailable.
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

        return sprintf('%04d:%02d:%02d %02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second);
    }

    /**
     * Extracts the profile connection space illuminant as XYZ values.
     *
     * ICC.1:2022 §7.2.11 specifies the illuminant as s15Fixed16Numbers.
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

        return [
            'x' => $x,
            'y' => $y,
            'z' => $z,
        ];
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
     * ICC.1:2022 §9.2.22 (copyrightTag) and §9.2.41 (profileDescriptionTag).
     *
     * @param string $data         Raw ICC profile payload.
     * @param int    $profileSize  Declared profile size limiting the accessible range.
     * @param string $tagSignature Tag signature to search for ('desc' or 'cprt').
     *
     * @return string|null Tag text or null when not available.
     */
    private function extractTag(string $data, int $profileSize, string $tagSignature): ?string
    {
        $tagData = $this->findTagData($data, $profileSize, $tagSignature);
        if ($tagData === null) {
            return null;
        }

        $type = substr($tagData, 0, 4);

        if ($type === 'desc') {
            return $this->parseDescTag($tagData);
        }

        if ($type === 'mluc') {
            return $this->parseMlucTag($tagData);
        }

        // ICC.1:2022 §10.24 textType for older profiles
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

        // ICC.1:2022 §10.31: XYZType contains XYZNumber at offset 8
        // XYZNumber is 3 × s15Fixed16Number (each 4 bytes)
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

        $entries = [];
        $cursor  = $tagCountOffset + 4;

        for ($i = 0; $i < $tagCount; ++$i) {
            if (($cursor + self::TAG_RECORD_LENGTH) > $length) {
                return false;
            }

            $offset = $this->uInt32Be(substr($data, $cursor + 4, 4));
            $size   = $this->uInt32Be(substr($data, $cursor + 8, 4));
            $cursor += self::TAG_RECORD_LENGTH;

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

            $entries[] = [
                'offset' => $offset,
                'size'   => $size,
            ];
        }

        if ($entries === []) {
            return $this->paddingIsNull($data, $tableEnd, $length - $tableEnd);
        }

        usort(
            $entries,
            static fn (array $left, array $right): int => $left['offset'] <=> $right['offset'],
        );

        if ($entries[0]['offset'] !== $tableEnd) {
            return false;
        }

        $cursor = $tableEnd;

        foreach ($entries as $entry) {
            if ($entry['offset'] < $cursor) {
                return false;
            }

            if (!$this->paddingIsNull($data, $cursor, $entry['offset'] - $cursor)) {
                return false;
            }

            $cursor = $entry['offset'] + $entry['size'];
        }

        return $this->paddingIsNull($data, $cursor, $length - $cursor);
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
     * ICC.1:2022 §10.24: textType structure is signature (4) + reserved (4) + ASCII text.
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

        $text = substr($data, 8);

        return rtrim($text, "\0");
    }

    /**
     * Parses an ICC 'desc' tag to retrieve its ASCII description.
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

        $asciiLength = $this->uInt32Be(substr($data, 8, 4));
        if ($asciiLength === 0) {
            return null;
        }

        $available = strlen($data) - 12;
        $length    = min($asciiLength, $available);
        if ($length <= 0) {
            return null;
        }

        $text = substr($data, 12, $length);

        return rtrim($text, "\0");
    }

    /**
     * Parses an ICC 'mluc' tag to extract the first non-empty UTF-16 value.
     *
     * @param string $data Raw tag payload beginning with the type signature.
     *
     * @return string|null Extracted description string or null when no valid record exists.
     */
    private function parseMlucTag(string $data): ?string
    {
        if (strlen($data) < 16) {
            return null;
        }

        $recordCount = $this->uInt32Be(substr($data, 8, 4));
        $recordSize  = $this->uInt32Be(substr($data, 12, 4));
        if ($recordCount === 0 || $recordSize < 12) {
            return null;
        }

        $cursor = 16;
        for ($i = 0; $i < $recordCount; ++$i) {
            if ($cursor + $recordSize > strlen($data)) {
                break;
            }

            $stringLength = $this->uInt32Be(substr($data, $cursor + 4, 4));
            $stringOffset = $this->uInt32Be(substr($data, $cursor + 8, 4));
            $cursor += $recordSize;

            if ($stringLength === 0) {
                continue;
            }

            if ($stringOffset + $stringLength > strlen($data)) {
                continue;
            }

            $raw = substr($data, $stringOffset, $stringLength);
            $utf = $this->decodeUtf16Be($raw);
            if ($utf !== null && $utf !== '') {
                return $utf;
            }
        }

        return null;
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

        if (function_exists('mb_convert_encoding')) {
            return mb_convert_encoding($data, 'UTF-8', 'UTF-16BE');
        }

        if (function_exists('iconv')) {
            $converted = iconv('UTF-16BE', 'UTF-8//IGNORE', $data);

            return $converted === false ? null : $converted;
        }

        if (class_exists('Imagick') && class_exists('ImagickPixel')) {
            try {
                $imagick = new Imagick();
                $imagick->newImage(1, 1, new ImagickPixel('white'));
                $imagick->setImageFormat('png');
                $imagick->setImageProperty('icc:text', $data);
                $text = $imagick->getImageProperty('icc:text');
                if ($text !== '') {
                    return $text;
                }
            } catch (Throwable) {
                // ignore - fall through to null
            }
        }

        return null;
    }

    /**
     * Converts up to four bytes into an unsigned big-endian integer.
     *
     * @param string $bytes Raw bytes to interpret as a big-endian integer.
     *
     * @return int Parsed unsigned integer value.
     */
    private function uInt32Be(string $bytes): int
    {
        $bytes = substr($bytes . "\0\0\0\0", 0, 4);

        $unpacked = @unpack('Nvalue', $bytes);
        if (!is_array($unpacked) || !array_key_exists('value', $unpacked)) {
            return 0;
        }

        $value = $unpacked['value'];
        if (!is_int($value)) {
            throw new ParseError('Unexpected integer value while decoding ICC profile.');
        }

        return $value;
    }

    /**
     * Converts up to two bytes into an unsigned big-endian integer.
     *
     * @param string $bytes Raw bytes to interpret as a big-endian integer.
     *
     * @return int Parsed unsigned integer value.
     */
    private function uInt16Be(string $bytes): int
    {
        $bytes = substr($bytes . "\0\0", 0, 2);

        $unpacked = @unpack('nvalue', $bytes);
        if (!is_array($unpacked) || !array_key_exists('value', $unpacked)) {
            return 0;
        }

        $value = $unpacked['value'];
        if (!is_int($value)) {
            throw new ParseError('Unexpected integer value while decoding ICC profile.');
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
