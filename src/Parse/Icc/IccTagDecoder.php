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

use function intdiv;
use function min;
use function ord;
use function rtrim;
use function sprintf;
use function str_starts_with;
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
        return $this->extractXyzTag($data, $profileSize, 'wtpt');
    }

    /**
     * Extracts an XYZType tag from the tag table.
     *
     * ICC.1:2022 §10.31: XYZType contains one or more XYZNumber values.
     * This method extracts the first XYZNumber (3 x s15Fixed16Number at offset 8).
     *
     * @param string $data         Raw ICC profile payload.
     * @param int    $profileSize  Declared profile size limiting the accessible range.
     * @param string $tagSignature 4-byte tag signature to search for.
     *
     * @return array{x: float, y: float, z: float}|null XYZ tristimulus values or null when not available.
     */
    public function extractXyzTag(string $data, int $profileSize, string $tagSignature): ?array
    {
        $tagData = $this->findTagData($data, $profileSize, $tagSignature);
        if ($tagData === null || strlen($tagData) < 20) {
            return null;
        }

        $type = substr($tagData, 0, 4);
        if ($type !== 'XYZ ') {
            return null;
        }

        // ICC.1:2022 §10.31 reserved bytes 4..7 must be zero
        if (!$this->hasZeroReservedBytes($tagData)) {
            throw new ParseError(
                sprintf('ICC %s XYZType reserved bytes 4..7 are non-zero', $tagSignature),
                1141,
            );
        }

        // ICC.1:2022 §10.31: XYZType contains XYZNumber at offset 8
        // XYZNumber is 3 x s15Fixed16Number (each 4 bytes)
        return $this->parseXyzTriplet($tagData, 8);
    }

    /**
     * Extracts viewing conditions data from the viewingConditionsTag ('view').
     *
     * ICC.1:2022 §9.2.51 and §10.30 define viewingConditionsType as:
     * signature + reserved + illuminant XYZ + surround XYZ + illuminant type.
     *
     * @param string $data        Raw ICC profile payload.
     * @param int    $profileSize Declared profile size limiting the accessible range.
     *
     * @return array{
     *   illuminant: array{x: float, y: float, z: float},
     *   surround: array{x: float, y: float, z: float},
     *   illuminantType: int
     * }|null
     */
    public function extractViewingConditions(string $data, int $profileSize): ?array
    {
        $tagData = $this->findTagData($data, $profileSize, 'view');
        if ($tagData === null || strlen($tagData) < 36) {
            return null;
        }

        if (!str_starts_with($tagData, 'view')) {
            return null;
        }

        if (!$this->hasZeroReservedBytes($tagData)) {
            return null;
        }

        return [
            'illuminant'     => $this->parseXyzTriplet($tagData, 8),
            'surround'       => $this->parseXyzTriplet($tagData, 20),
            'illuminantType' => $this->reader->uInt32Be(substr($tagData, 32, 4)),
        ];
    }

    /**
     * Extracts measurement data from the measurementTag ('meas').
     *
     * ICC.1:2022 §9.2.34 and §10.14 define measurementType as:
     * signature + reserved + observer + backing XYZ + geometry + flare + illuminant.
     *
     * @param string $data        Raw ICC profile payload.
     * @param int    $profileSize Declared profile size limiting the accessible range.
     *
     * @return array{
     *   observer: int,
     *   backing: array{x: float, y: float, z: float},
     *   geometry: int,
     *   flare: float,
     *   illuminant: int
     * }|null
     */
    public function extractMeasurement(string $data, int $profileSize): ?array
    {
        $tagData = $this->findTagData($data, $profileSize, 'meas');
        if ($tagData === null || strlen($tagData) < 36) {
            return null;
        }

        if (!str_starts_with($tagData, 'meas')) {
            return null;
        }

        if (!$this->hasZeroReservedBytes($tagData)) {
            return null;
        }

        return [
            'observer'   => $this->reader->uInt32Be(substr($tagData, 8, 4)),
            'backing'    => $this->parseXyzTriplet($tagData, 12),
            'geometry'   => $this->reader->uInt32Be(substr($tagData, 24, 4)),
            'flare'      => $this->reader->uInt32Be(substr($tagData, 28, 4)) / 65536.0,
            'illuminant' => $this->reader->uInt32Be(substr($tagData, 32, 4)),
        ];
    }

    /**
     * Extracts a tone response curve (TRC) tag from the tag table.
     *
     * ICC.1:2022 §9.2.48 (redTRCTag), §9.2.27 (greenTRCTag), §9.2.7 (blueTRCTag):
     * the permitted type is parametricCurveType or curveType.
     *
     * For parametricCurveType (§10.18): function type 0 stores a single gamma value.
     * For curveType (§10.6): a count of 0 means identity (gamma 1.0), a count of 1
     * stores gamma as uInt8Fixed8Number, otherwise a lookup table is present.
     *
     * @param string $data         Raw ICC profile payload.
     * @param int    $profileSize  Declared profile size limiting the accessible range.
     * @param string $tagSignature Tag signature to search for ('rTRC', 'gTRC', 'bTRC').
     *
     * @return array{gamma: float}|array{table: list<int>}|null Curve data or null when not available.
     */
    public function extractTrcTag(string $data, int $profileSize, string $tagSignature): ?array
    {
        $tagData = $this->findTagData($data, $profileSize, $tagSignature);
        if ($tagData === null || strlen($tagData) < 8) {
            return null;
        }

        $type = substr($tagData, 0, 4);

        if ($type === 'para') {
            return $this->parseParametricCurve($tagData);
        }

        if ($type === 'curv') {
            return $this->parseCurveType($tagData);
        }

        return null;
    }

    /**
     * Extracts a 4-byte signature tag from the tag table.
     *
     * ICC.1:2022 §10.22: signatureType stores a 4-byte signature after the
     * type header (signature + reserved = 8 bytes).
     *
     * @param string $data         Raw ICC profile payload.
     * @param int    $profileSize  Declared profile size limiting the accessible range.
     * @param string $tagSignature Tag signature to search for.
     *
     * @return string|null 4-byte signature value or null when not available.
     */
    public function extractSignatureTag(string $data, int $profileSize, string $tagSignature): ?string
    {
        $tagData = $this->findTagData($data, $profileSize, $tagSignature);
        if ($tagData === null || strlen($tagData) < 12) {
            return null;
        }

        $type = substr($tagData, 0, 4);
        if ($type !== 'sig ') {
            return null;
        }

        // ICC.1:2022 §10.22 reserved bytes 4..7 must be zero
        if (!$this->hasZeroReservedBytes($tagData)) {
            return null;
        }

        $signature = substr($tagData, 8, 4);
        if ($signature === "\0\0\0\0") {
            return null;
        }

        return $signature;
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

        // Guard against integer overflow before multiplying tag count by entry size.
        // On 32-bit PHP, large uint32 tag counts would overflow PHP_INT_MAX.
        if ($tagCount > intdiv(PHP_INT_MAX, self::TAG_RECORD_LENGTH)) {
            throw new ParseError(
                sprintf(
                    'ICC tag table count %d would overflow when multiplied by entry size %d',
                    $tagCount,
                    self::TAG_RECORD_LENGTH,
                ),
                1808,
            );
        }

        $tableEnd = $tagCountOffset + 4 + ($tagCount * self::TAG_RECORD_LENGTH);
        if ($tableEnd > $length) {
            throw new ParseError(
                sprintf(
                    'ICC tag table count %d requires %d bytes but only %d bytes are available',
                    $tagCount,
                    $tableEnd - $tagCountOffset,
                    $length - $tagCountOffset,
                ),
                2080,
            );
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
     * Checks whether the ICC reserved bytes at offset 4..7 are all zero.
     *
     * ICC.1:2022 §10.1: all tag types begin with a 4-byte type signature
     * followed by 4 reserved bytes that must be set to zero.
     */
    private function hasZeroReservedBytes(string $data): bool
    {
        return substr($data, 4, 4) === "\0\0\0\0";
    }

    /**
     * Parses one XYZNumber triplet (3 x s15Fixed16Number) from a payload offset.
     *
     * @return array{x: float, y: float, z: float}
     */
    private function parseXyzTriplet(string $data, int $offset): array
    {
        return [
            'x' => $this->reader->s15Fixed16($data, $offset),
            'y' => $this->reader->s15Fixed16($data, $offset + 4),
            'z' => $this->reader->s15Fixed16($data, $offset + 8),
        ];
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
        if (!$this->hasZeroReservedBytes($data)) {
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
        if (!$this->hasZeroReservedBytes($data)) {
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

            // Guard: reject offset that already reaches or exceeds the payload boundary
            // before computing offset + length, which would risk integer overflow on
            // narrow-integer platforms when the offset is a near-maximum uint32 value.
            if ($stringOffset >= $length) {
                throw new ParseError(
                    sprintf(
                        'ICC mluc record %d string offset %d meets or exceeds payload length %d',
                        $i,
                        $stringOffset,
                        $length,
                    ),
                    1890,
                );
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

            /** @noinspection OnlyWritesOnParameterInspection — both variables are read in the return below */
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
     * Parses a parametricCurveType tag payload.
     *
     * ICC.1:2022 §10.18: parametricCurveType encodes a tone curve as a function type
     * (0..4) followed by one or more s15Fixed16Number parameters. Function type 0
     * is a simple power curve Y = X^gamma with a single parameter.
     *
     * @param string $data Raw tag payload beginning with the type signature.
     *
     * @return array{gamma: float}|null Parsed gamma or null when invalid.
     */
    private function parseParametricCurve(string $data): ?array
    {
        // Minimum: 'para'(4) + reserved(4) + functionType(2) + reserved(2) + gamma(4) = 16
        if (strlen($data) < 16) {
            return null;
        }

        // ICC.1:2022 §10.18 reserved bytes 4..7 must be zero
        if (!$this->hasZeroReservedBytes($data)) {
            return null;
        }

        $functionType = $this->reader->uInt16Be(substr($data, 8, 2));

        // Only function type 0 (simple gamma) is decoded; higher types
        // require additional parameters beyond what this parser exposes.
        if ($functionType !== 0) {
            return null;
        }

        // s15Fixed16 gamma value at offset 12
        $gamma = $this->reader->s15Fixed16($data, 12);

        return ['gamma' => $gamma];
    }

    /**
     * Parses a curveType tag payload.
     *
     * ICC.1:2022 §10.6: curveType stores a count followed by curve entries.
     * - count 0: identity curve (gamma 1.0)
     * - count 1: gamma encoded as uInt8Fixed8Number (value / 256.0)
     * - count > 1: lookup table of uInt16Number values
     *
     * @param string $data Raw tag payload beginning with the type signature.
     *
     * @return array{gamma: float}|array{table: list<int>}|null Curve data or null when invalid.
     */
    private function parseCurveType(string $data): ?array
    {
        // Minimum: 'curv'(4) + reserved(4) + count(4) = 12
        if (strlen($data) < 12) {
            return null;
        }

        // ICC.1:2022 §10.6 reserved bytes 4..7 must be zero
        if (!$this->hasZeroReservedBytes($data)) {
            return null;
        }

        $count = $this->reader->uInt32Be(substr($data, 8, 4));

        // Identity curve
        if ($count === 0) {
            return ['gamma' => 1.0];
        }

        // Single gamma value as uInt8Fixed8Number
        if ($count === 1) {
            if (strlen($data) < 14) {
                return null;
            }

            $raw = $this->reader->uInt16Be(substr($data, 12, 2));

            return ['gamma' => $raw / 256.0];
        }

        // Lookup table: count x uInt16Number entries
        $needed = 12 + ($count * 2);
        if (strlen($data) < $needed) {
            return null;
        }

        $table = [];
        for ($i = 0; $i < $count; ++$i) {
            $table[] = $this->reader->uInt16Be(substr($data, 12 + ($i * 2), 2));
        }

        return ['table' => $table];
    }
}
