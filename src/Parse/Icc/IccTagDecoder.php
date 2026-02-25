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

use function min;
use function rtrim;
use function sprintf;
use function strlen;
use function substr;

/**
 * Traverses the ICC tag table and decodes tag payloads.
 *
 * ICC.1:2022 §7.3 defines the tag table layout. §9 defines common tags
 * (copyrightTag, profileDescriptionTag, mediaWhitePointTag). §10 defines
 * tag types (textType, descType, multiLocalizedUnicodeType, XYZType).
 */
final readonly class IccTagDecoder
{
    private const int HEADER_LENGTH = 128;

    private const int TAG_RECORD_LENGTH = 12;

    /**
     * @param IccBinaryReader $reader Binary reader for integer and fixed-point decoding.
     */
    public function __construct(
        private IccBinaryReader $reader,
    ) {
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
    public function extractTag(string $data, int $profileSize, string $tagSignature, int $majorVersion): ?string
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
    public function extractWhitePoint(string $data, int $profileSize): ?array
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
            'x' => $this->reader->s15Fixed16($tagData, 8),
            'y' => $this->reader->s15Fixed16($tagData, 12),
            'z' => $this->reader->s15Fixed16($tagData, 16),
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

        $tagCount = $this->reader->uInt32Be(substr($data, $tagCountOffset, 4));
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
            $offset    = $this->reader->uInt32Be(substr($data, $cursor + 4, 4));
            $size      = $this->reader->uInt32Be(substr($data, $cursor + 8, 4));
            $cursor += self::TAG_RECORD_LENGTH;

            if ($signature !== $tagSignature) {
                continue;
            }

            // ICC.1:2022 §7.3 — tag offsets must be 4-byte aligned
            if (($offset % 4) !== 0) {
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

        $asciiLength = $this->reader->uInt32Be(substr($data, 8, 4));
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

        // Tolerate non-zero reserved bytes 4..7 per Postel's Law.

        $recordCount = $this->reader->uInt32Be(substr($data, 8, 4));
        $recordSize  = $this->reader->uInt32Be(substr($data, 12, 4));

        if ($recordCount === 0) {
            return null;
        }

        // Tolerate record sizes >= 12 for forward compatibility.
        if ($recordSize < 12) {
            return null;
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
            $stringLength = $this->reader->uInt32Be(substr($data, $cursor + 4, 4));
            $stringOffset = $this->reader->uInt32Be(substr($data, $cursor + 8, 4));
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
            $utf = $this->reader->decodeUtf16Be($raw);
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
}
