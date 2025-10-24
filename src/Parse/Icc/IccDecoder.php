<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Icc;

use MagicSunday\ImageMeta\Core\ParseError;
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
use function substr;
use function strtoupper;
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
     * Mapping of ICC rendering intent enumerations to descriptive strings.
     */
    private const array RENDERING_INTENT_MAP = [
        0 => 'Perceptual',
        1 => 'Media-Relative Colorimetric',
        2 => 'Saturation',
        3 => 'ICC-Absolute Colorimetric',
    ];

    /**
     * Decodes the ICC profile payload by extracting header fields and well known tags.
     *
     * @param string|null       $profileData Raw ICC profile data when a complete payload is available.
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
            'description' => $description,
            'version' => $version,
            'pcs' => $pcs,
            'renderingIntent' => $renderingIntent,
            'profileId' => $profileId,
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
        for ($i = 1; $i <= $expectedCount; $i++) {
            if (!array_key_exists($i, $sequence)) {
                return null;
            }

            $iccData .= $sequence[$i];
        }

        return $iccData;
    }

    private function extractVersion(string $data): ?string
    {
        $majorMinor = ord($data[8]);
        $bugfix     = ord($data[9]);

        $major = $majorMinor >> 4;
        $minor = $majorMinor & 0x0F;
        $bug   = $bugfix >> 4;

        if ($major === 0 && $minor === 0 && $bug === 0) {
            return null;
        }

        return $bug > 0
            ? sprintf('%d.%d.%d', $major, $minor, $bug)
            : sprintf('%d.%d', $major, $minor);
    }

    private function extractSignature(string $signature): ?string
    {
        if ($signature === '' || strlen($signature) < 4) {
            return null;
        }

        return strtoupper($signature);
    }

    private function extractRenderingIntent(string $data): ?string
    {
        $intent = $this->uInt32Be(substr($data, 64, 4));

        return self::RENDERING_INTENT_MAP[$intent] ?? null;
    }

    private function extractProfileId(string $data): ?string
    {
        $profileId = substr($data, 84, 16);
        if ($profileId === str_repeat("\0", 16)) {
            return null;
        }

        return strtoupper(bin2hex($profileId));
    }

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

        for ($i = 0; $i < $tagCount; $i++) {
            if ($cursor + self::TAG_RECORD_LENGTH > $length) {
                break;
            }

            $signature = substr($data, $cursor, 4);
            $offset    = $this->uInt32Be(substr($data, $cursor + 4, 4));
            $size      = $this->uInt32Be(substr($data, $cursor + 8, 4));
            $cursor   += self::TAG_RECORD_LENGTH;

            if ($signature !== 'desc') {
                continue;
            }

            if ($offset < self::HEADER_LENGTH || $size === 0) {
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
        for ($i = 0; $i < $recordCount; $i++) {
            if ($cursor + $recordSize > strlen($data)) {
                break;
            }

            $stringLength = $this->uInt32Be(substr($data, $cursor + 4, 4));
            $stringOffset = $this->uInt32Be(substr($data, $cursor + 8, 4));
            $cursor      += $recordSize;

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
                $imagick = new \Imagick();
                $imagick->newImage(1, 1, new \ImagickPixel('white'));
                $imagick->setImageFormat('png');
                $imagick->setImageProperty('icc:text', $data);
                $text = $imagick->getImageProperty('icc:text');
                if ($text !== '') {
                    return $text;
                }
            } catch (\Throwable) {
                // ignore - fall through to null
            }
        }

        return null;
    }

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
