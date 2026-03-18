<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Exif\Reader;

use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\FallbackIfdSet;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdValueReader;
use MagicSunday\ImageMeta\Exif\Text\JisTextDecoder;
use MagicSunday\ImageMeta\Exif\Text\UndefinedTextMarker;

use function in_array;
use function min;
use function ord;
use function preg_match;
use function strlen;
use function substr;
use function substr_count;
use function trim;

/**
 * Reads and decodes EXIF user comment fields.
 *
 * EXIF 3.0 §4.6.6.4.2 defines the UserComment tag with an 8-byte character code
 * prefix followed by the encoded payload. This reader handles ASCII, Unicode (UTF-8
 * and legacy UTF-16), JIS, and UNDEFINED marker detection.
 */
final readonly class UserCommentExifReader
{
    /**
     * @param IfdValueReader $reader       Value reader for IFD tag extraction.
     * @param Ifd|null       $exifIfd      Sub IFD containing EXIF-specific tags.
     * @param FallbackIfdSet $fallbackIfds Fallback IFD set for secondary metadata lookup.
     */
    public function __construct(
        private IfdValueReader $reader,
        private ?Ifd $exifIfd,
        private FallbackIfdSet $fallbackIfds,
    ) {
    }

    /**
     * Returns the user comment string after decoding the EXIF prefix.
     *
     * EXIF 3.0 §4.6.6.4.2 defines the multicode-compatible prefix (see §4.6.4) that annotates
     * the UserComment character code.
     */
    public function userComment(): ?string
    {
        $raw = $this->rawUserComment();

        return $raw !== null ? $this->decodeUserComment($raw) : null;
    }

    /**
     * Returns the encoding declared in the EXIF user comment prefix.
     *
     * EXIF 3.0 §4.6.4 requires UNDEFINED text fields to include an 8-byte
     * character code area. Payloads shorter than 8 bytes are non-conformant.
     */
    public function userCommentEncoding(): ?string
    {
        $raw                  = $this->rawUserComment();

        if ($raw === null) {
            return null;
        }

        $parsed               = $this->parseUserCommentPrefix($raw);

        if ($parsed === null) {
            return null;
        }

        [$encoding, $content] = $parsed;
        $hasContent           = trim($content, "\0 ") !== '';

        return $hasContent ? $encoding : null;
    }

    /**
     * Provides the declared user comment encoding falling back to content inference
     * when the 8-byte prefix is present but denotes UNDEFINED encoding.
     *
     * EXIF 3.0 §4.6.4 requires the 8-byte character code area to be present.
     */
    public function userCommentEncodingBestEffort(): ?string
    {
        $encoding    = $this->userCommentEncoding();

        if ($encoding !== null) {
            return $encoding;
        }

        $raw         = $this->rawUserComment();

        if ($raw === null) {
            return null;
        }

        $parsed      = $this->parseUserCommentPrefix($raw);

        if ($parsed === null) {
            return null;
        }

        [, $content] = $parsed;

        return $this->inferUserCommentEncoding($content);
    }

    // ========================================================================
    // Private helpers
    // ========================================================================

    /**
     * Retrieves the raw user comment value from primary and fallback directories.
     */
    private function rawUserComment(): ?string
    {
        $raw = $this->reader->rawString($this->exifIfd, ExifTag::USER_COMMENT);

        if ($raw !== null) {
            return $raw;
        }

        foreach ($this->fallbackIfds->resolve(includeIfd0: true) as $ifd) {
            $candidate = $this->reader->rawString($ifd, ExifTag::USER_COMMENT);

            if ($candidate !== null) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Decodes EXIF user comment strings with encoding prefixes.
     *
     * EXIF 3.0 §4.6.4 requires UNDEFINED text fields to include an 8-byte
     * character code area. Payloads shorter than 8 bytes are non-conformant
     * and are rejected. An unrecognised prefix is also rejected.
     */
    private function decodeUserComment(string $raw): ?string
    {
        $parsed               = $this->parseUserCommentPrefix($raw);

        if ($parsed === null) {
            return null;
        }

        [$encoding, $content] = $parsed;
        $sanitized            = trim($content, "\0 ");

        if ($sanitized === '') {
            return null;
        }

        return match ($encoding) {
            'UNICODE' => $this->decodeUnicodeUserComment($content),
            'JIS'     => $this->decodeJisComment($sanitized),
            default   => $sanitized,
        };
    }

    /**
     * Parses the 8-byte user comment prefix and returns the encoding and content.
     *
     * @return array{string, string}|null Encoding identifier and payload, or null if invalid.
     */
    private function parseUserCommentPrefix(string $raw): ?array
    {
        if (strlen($raw) < 8) {
            return null;
        }

        $prefix            = substr($raw, 0, 8);
        $canonicalEncoding = $this->canonicalUserCommentMarker($prefix);

        if ($canonicalEncoding === '') {
            return null;
        }

        return [$canonicalEncoding, substr($raw, 8)];
    }

    /**
     * Normalizes known EXIF user comment markers to their canonical identifiers.
     */
    private function canonicalUserCommentMarker(string $prefix): string
    {
        return UndefinedTextMarker::canonicalMarkerFromPrefix($prefix);
    }

    /**
     * Decodes UNICODE-marker user comments using EXIF 3.0 UTF-8 semantics.
     *
     * Compatibility policy:
     * - EXIF 3.0 `UNICODE\0`: decode as UTF-8.
     * - Legacy fallback: when UTF-8 validation fails, accept BOM-tagged UTF-16
     *   payloads for older EXIF 2.x ecosystem files.
     */
    private function decodeUnicodeUserComment(string $content): ?string
    {
        if ($content === '') {
            return null;
        }

        if (preg_match('//u', $content) === 1) {
            $trimmed = trim($content, "\0 ");

            return $trimmed === '' ? null : $trimmed;
        }

        return $this->reader->decodeLegacyUnicodeFromBom($content);
    }

    /**
     * Decodes a JIS-marker user comment using ISO-2022-JP/JIS strategy.
     */
    private function decodeJisComment(string $content): ?string
    {
        return JisTextDecoder::decode($content);
    }

    /**
     * Infers the most likely user comment encoding based on the raw payload.
     */
    private function inferUserCommentEncoding(string $content): ?string
    {
        $trimmed = trim($content, "\0 ");

        if ($trimmed === '') {
            return null;
        }

        if ($this->looksLikeUtf16($content)) {
            return 'UNICODE';
        }

        if ($this->looksPrintableAscii($trimmed)) {
            return 'ASCII';
        }

        return 'UNDEFINED';
    }

    /**
     * Checks whether the payload is limited to printable ASCII characters.
     */
    private function looksPrintableAscii(string $content): bool
    {
        $length = strlen($content);

        for ($i = 0; $i < $length; ++$i) {
            $byte = ord($content[$i]);

            if (($byte < 0x20) && !in_array($byte, [0x09, 0x0A, 0x0D], true)) {
                return false;
            }

            if ($byte > 0x7E) {
                return false;
            }
        }

        return true;
    }

    /**
     * Heuristically determines whether the payload resembles UTF-16 text.
     */
    private function looksLikeUtf16(string $content): bool
    {
        $length       = strlen($content);

        if ($length < 2) {
            return false;
        }

        $bom          = substr($content, 0, 2);

        if ($bom === "\xFF\xFE" || $bom === "\xFE\xFF") {
            return true;
        }

        $nullCount    = substr_count($content, "\x00");

        if ($nullCount < 2) {
            return false;
        }

        $sampleLength = min($length, 32);
        $sample       = substr($content, 0, $sampleLength);

        $nullsOnEven  = 0;
        $nullsOnOdd   = 0;

        $sampleSize   = strlen($sample);

        for ($i = 0; $i < $sampleSize; ++$i) {
            if ($sample[$i] === "\x00") {
                if (($i % 2) === 0) {
                    ++$nullsOnEven;
                } else {
                    ++$nullsOnOdd;
                }
            }
        }

        if (($nullsOnEven === 0) && ($nullsOnOdd === 0)) {
            return false;
        }

        if ($nullsOnEven === 0 || $nullsOnOdd === 0) {
            return true;
        }

        if ($nullCount <= 2) {
            return false;
        }

        return $nullCount >= (int) ($length / 4);
    }
}
