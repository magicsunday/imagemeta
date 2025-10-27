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
use MagicSunday\ImageMeta\Core\ParseError;
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

/**
 * Decodes ICC profiles to expose header information and human readable tags.
 */
final class IccDecoder
{
    private const int HEADER_LENGTH = 128;

    private const int TAG_RECORD_LENGTH = 12;

    private const string ICC_SIGNATURE = 'ICC_PROFILE\0';

    /**
     * Decodes the ICC profile payload by extracting header fields and well known tags.
     *
     * @param string|null        $profileData Raw ICC profile data when a complete payload is available.
     * @param array<int, string> $segments    ICC segments collected from APP2 markers ordered by appearance.
     *
     * @return array{
     *     description: string|null,
     *     version: string|null,
     *     pcs: string|null,
     *     renderingIntent: string|null,
     *     profileId: string|null,
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

        $profileSize = $this->uInt32Be(substr($data, 0, 4));
        $length      = strlen($data);
        if ($profileSize > $length) {
            return null; // truncated payload
        }

        $version         = $this->extractVersion($data);
        $pcs             = $this->extractSignature(substr($data, 20, 4));
        $renderingIntent = $this->extractRenderingIntent($data);
        $profileId       = $this->extractProfileId($data);
        $description     = $this->extractDescription($data, $profileSize);

        return [
            'description'     => $description,
            'version'         => $version,
            'pcs'             => $pcs,
            'renderingIntent' => $renderingIntent,
            'profileId'       => $profileId,
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
        $bugfixVersion = $minorBugfix & 0x0F;

        if ($majorByte >= 0x10) {
            $major         = $majorByte >> 4;
            $minor         = $majorByte & 0x0F;
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
        $intent = $this->uInt32Be(substr($data, 64, 4));

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
        $profileId = substr($data, 84, 16);
        if ($profileId === str_repeat("\0", 16)) {
            return null;
        }

        return strtoupper(bin2hex($profileId));
    }

    /**
     * Extracts the profile description from the tag table.
     *
     * @param string $data        Raw ICC profile payload.
     * @param int    $profileSize Declared profile size limiting the accessible range.
     *
     * @return string|null Profile description text or null when not available.
     */
    private function extractDescription(string $data, int $profileSize): ?string
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

        for ($i = 0; $i < $tagCount; ++$i) {
            if ($cursor + self::TAG_RECORD_LENGTH > $length) {
                break;
            }

            $signature = substr($data, $cursor, 4);
            $offset    = $this->uInt32Be(substr($data, $cursor + 4, 4));
            $size      = $this->uInt32Be(substr($data, $cursor + 8, 4));
            $cursor += self::TAG_RECORD_LENGTH;

            if ($signature !== 'desc') {
                continue;
            }

            if ($offset < self::HEADER_LENGTH) {
                continue;
            }

            if ($size === 0) {
                continue;
            }

            if ($offset + $size > $length) {
                continue;
            }

            $tagData = substr($data, $offset, $size);
            $type    = substr($tagData, 0, 4);

            if ($type === 'desc') {
                return $this->parseDescTag($tagData);
            }

            if ($type === 'mluc') {
                $value = $this->parseMlucTag($tagData);
                if ($value !== null) {
                    return $value;
                }
            }
        }

        return null;
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

        $unpacked = unpack('Nvalue', $bytes);
        if (!is_array($unpacked) || !array_key_exists('value', $unpacked)) {
            return 0;
        }

        $value = $unpacked['value'];
        if (!is_int($value)) {
            throw new ParseError('Unexpected integer value while decoding ICC profile.');
        }

        return $value;
    }
}
