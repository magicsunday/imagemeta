<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Exif\Model;

use MagicSunday\ImageMeta\Core\Util\UInt64;
use MagicSunday\ImageMeta\Exif\ValueConverters;

use function array_map;
use function count;
use function iconv;
use function is_array;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function ord;
use function preg_match;
use function preg_replace;
use function restore_error_handler;
use function round;
use function rtrim;
use function set_error_handler;
use function strlen;
use function substr;
use function trim;

/**
 * Provides core IFD value access and normalisation helpers.
 *
 * All methods in this class depend only on the {@see ValueConverters} facade and the
 * {@see Ifd} data model — they carry no ParsedExif state.
 */
final readonly class IfdValueReader
{
    public const int RATIONAL_BYTE_LENGTH = 8;

    public const int SHORT_BYTE_LENGTH = 2;

    public function __construct(
        private ValueConverters $converters,
    ) {
    }

    /**
     * Retrieves the raw entry value for the provided tag.
     *
     * @param Ifd|null $ifd IFD to inspect.
     * @param int      $tag Tag identifier.
     */
    public function value(?Ifd $ifd, int $tag): int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null
    {
        if (!$ifd instanceof Ifd) {
            return null;
        }

        return $ifd->get($tag)?->value;
    }

    /**
     * Reads and normalizes a scalar tag value from an IFD.
     *
     * @param Ifd|null $ifd IFD to inspect.
     * @param int      $tag Tag identifier.
     *
     * @return int|float|string|ExifRational|ExifRationalList|ExifNumericList|null Normalized value.
     */
    public function normalizedValue(
        ?Ifd $ifd,
        int $tag,
    ): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null {
        $value = $this->value($ifd, $tag);

        return $this->normalizeScalarValue($value);
    }

    /**
     * Normalizes scalar EXIF values, converting UInt64 when possible.
     *
     * @param int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value Raw value.
     *
     * @return int|float|string|ExifRational|ExifRationalList|ExifNumericList|null Normalized value.
     */
    public function normalizeScalarValue(
        int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value,
    ): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null {
        if ($value instanceof UInt64) {
            // Only expose UInt64 values that fit into the platform signed integer range.
            if (!$value->fitsSignedInt()) {
                return null;
            }

            return $value->toInt('EXIF scalar normalisation');
        }

        if ($value instanceof ExifNumericList) {
            $normalized = [];
            $changed    = false;

            foreach ($value->values as $component) {
                if ($component instanceof UInt64) {
                    // Convert list components to integers when safe.
                    if (!$component->fitsSignedInt()) {
                        return null;
                    }

                    $normalized[] = $component->toInt('EXIF numeric list normalisation');
                    $changed      = true;

                    continue;
                }

                $normalized[] = $component;
            }

            if ($changed) {
                return new ExifNumericList($normalized);
            }

            return $value;
        }

        return $value;
    }

    /**
     * Returns a normalized string value, trimming null bytes and spaces.
     *
     * @param Ifd|null $ifd IFD to inspect.
     * @param int      $tag Tag identifier.
     *
     * @return string|null Normalized string or null.
     */
    public function str(?Ifd $ifd, int $tag): ?string
    {
        $value = $this->normalizedValue($ifd, $tag);

        if (!is_string($value)) {
            return null;
        }

        $trimmed = rtrim($value, "\0 ");

        if ($trimmed === '') {
            return null;
        }

        return trim($trimmed) === '' ? null : $trimmed;
    }

    /**
     * Returns an integer value from the given IFD if present.
     */
    public function int(?Ifd $ifd, int $tag): ?int
    {
        $value = $this->normalizedValue($ifd, $tag);

        return $this->coerceIntValue($value);
    }

    /**
     * Returns a rational or numeric value converted to float if present in the given IFD.
     */
    public function rational(?Ifd $ifd, int $tag): ?float
    {
        $value = $this->normalizedValue($ifd, $tag);

        if ($value === null) {
            return null;
        }

        return $this->converters->rationalToFloat($value);
    }

    /**
     * Returns the raw string value without trimming trailing null bytes.
     */
    public function rawString(?Ifd $ifd, int $tag): ?string
    {
        $value = $this->value($ifd, $tag);

        return is_string($value) ? $value : null;
    }

    /**
     * Returns a scalar value suitable for enum conversion.
     *
     * @param Ifd|null $ifd IFD to inspect.
     * @param int      $tag Tag identifier.
     *
     * @return int|string|null Normalized enum scalar.
     */
    public function enumValue(?Ifd $ifd, int $tag): int|string|null
    {
        $value = $this->value($ifd, $tag);

        return $this->normalizeEnumScalar($value);
    }

    /**
     * Normalizes a mixed EXIF value to an enum-compatible scalar.
     *
     * @param int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value Raw value.
     *
     * @return int|string|null Enum-compatible scalar value.
     */
    public function normalizeEnumScalar(
        int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value,
    ): int|string|null {
        if ($value instanceof ExifNumericList) {
            // Only the first entry is relevant for enum conversion.
            $first = $value->values[0] ?? null;

            return $this->normalizeEnumScalar($first);
        }

        if ($value instanceof ExifRationalList) {
            // Only the first entry is relevant for enum conversion.
            $first = $value->values[0] ?? null;

            return $this->normalizeEnumScalar($first);
        }

        if ($value instanceof ExifRational) {
            // Reduce rationals to a rounded integer for enum lookups.
            $float = $this->converters->rationalToFloat($value);

            return $float === null ? null : $this->normalizeEnumScalar($float);
        }

        if ($value instanceof UInt64) {
            if (!$value->fitsSignedInt()) {
                return null;
            }

            return $value->toInt('EXIF enum value normalisation');
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) round($value);
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            if ($trimmed === '') {
                return null;
            }

            if (is_numeric($trimmed)) {
                return (int) round((float) $trimmed);
            }

            return $trimmed;
        }

        return null;
    }

    /**
     * Converts a numeric list or undefined string into a list of integers.
     *
     * @return list<int>|null
     */
    public function numericList(?Ifd $ifd, int $tag): ?array
    {
        $value = $this->value($ifd, $tag);

        if ($value instanceof ExifNumericList) {
            return array_map(
                static function (int|float|UInt64 $component): int {
                    if ($component instanceof UInt64) {
                        return $component->toInt('EXIF numeric list component');
                    }

                    return (int) $component;
                },
                $value->values,
            );
        }

        if (is_int($value)) {
            return [$value];
        }

        if (is_string($value) && ($value !== '')) {
            $length = strlen($value);
            $bytes  = [];

            for ($i = 0; $i < $length; ++$i) {
                $bytes[] = ord($value[$i]);
            }

            return $bytes;
        }

        return null;
    }

    /**
     * Converts rational or numeric list values into floating point lists.
     *
     * @return list<float>|null
     */
    public function rationalList(?Ifd $ifd, int $tag): ?array
    {
        $value = $this->value($ifd, $tag);

        if ($value instanceof ExifRationalList) {
            $result = [];

            foreach ($value->values as $item) {
                $float = $this->converters->rationalToFloat($item);

                if ($float === null) {
                    return null;
                }

                $result[] = $float;
            }

            return $result;
        }

        if ($value instanceof ExifRational) {
            $float = $this->converters->rationalToFloat($value);

            return $float !== null ? [$float] : null;
        }

        if ($value instanceof ExifNumericList) {
            $floats = [];

            foreach ($value->values as $component) {
                if ($component instanceof UInt64) {
                    if (!$component->fitsSignedInt()) {
                        return null;
                    }

                    $floats[] = (float) $component->toInt('EXIF rational list component');

                    continue;
                }

                $floats[] = (float) $component;
            }

            return $floats;
        }

        if (is_int($value) || is_float($value)) {
            return [(float) $value];
        }

        return null;
    }

    /**
     * Coerces a raw EXIF scalar value into an integer when possible.
     */
    public function coerceIntValue(
        int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value,
    ): ?int {
        if ($value instanceof ExifNumericList) {
            $first = $value->values[0] ?? null;

            return $this->coerceIntValue($first);
        }

        if ($value instanceof ExifRationalList) {
            $first = $value->values[0] ?? null;

            return $first instanceof ExifRational ? $this->coerceIntValue($first) : null;
        }

        if ($value instanceof ExifRational) {
            $float = $this->converters->rationalToFloat($value);

            return $float === null ? null : (int) round($float);
        }

        if ($value instanceof UInt64) {
            if (!$value->fitsSignedInt()) {
                return null;
            }

            return $value->toInt('EXIF integer coercion');
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) round($value);
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            if ($trimmed === '') {
                return null;
            }

            if (is_numeric($trimmed)) {
                return (int) round((float) $trimmed);
            }

            if (preg_match('/\d+/', $trimmed, $matches) === 1) {
                return (int) $matches[0];
            }

            return null;
        }

        return null;
    }

    /**
     * Extracts components configuration input from IFD.
     *
     * @param Ifd|null $ifd IFD to search.
     * @param int      $tag Tag number to retrieve.
     *
     * @return array<int, int|float|string>|int|string|null Components input value or null if not found.
     */
    public function componentsInput(?Ifd $ifd, int $tag): array|int|string|null
    {
        $value = $this->value($ifd, $tag);

        if ($value instanceof ExifNumericList) {
            /** @var list<int|float> $components */
            $components = [];

            foreach ($value->values as $component) {
                if ($component instanceof UInt64) {
                    $components[] = $component->toInt('ComponentsConfiguration');
                } else {
                    $components[] = $component;
                }
            }

            return $components;
        }

        if ($value instanceof ExifRationalList) {
            $first = $value->values[0] ?? null;

            if (!$first instanceof ExifRational) {
                return null;
            }

            $float = $this->converters->rationalToFloat($first);

            return $float === null ? null : $this->componentsInputFromScalar($float);
        }

        if ($value instanceof ExifRational) {
            $float = $this->converters->rationalToFloat($value);

            return $float === null ? null : $this->componentsInputFromScalar($float);
        }

        if ($value instanceof UInt64) {
            return $this->componentsInputFromScalar($value->toInt('ComponentsConfiguration'));
        }

        return $this->componentsInputFromScalar($value);
    }

    /**
     * Converts a scalar components configuration value to normalized form.
     *
     * @param int|float|string|null $value Scalar value to normalize.
     *
     * @return int|string|null Normalized component value or null.
     */
    public function componentsInputFromScalar(int|float|string|null $value): int|string|null
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) round($value);
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed === '' ? null : $trimmed;
        }

        return null;
    }

    /**
     * Formats DNG BYTE[4] version tags into dotted notation.
     *
     * @param Ifd|null $ifd IFD that may contain the DNG version tag.
     * @param int      $tag DNG tag identifier for the requested version field.
     */
    public function dngVersionTag(?Ifd $ifd, int $tag): ?string
    {
        $components = $this->numericList($ifd, $tag);

        if (!is_array($components) || count($components) !== 4) {
            return null;
        }

        return $components[0]
            . '.'
            . $components[1]
            . '.'
            . $components[2]
            . '.'
            . $components[3];
    }

    /**
     * Returns the raw textual offset value from an EXIF OffsetTime* tag.
     *
     * EXIF 3.0 §4.6.6.6.3–§4.6.6.6.5 defines OffsetTime* as ASCII text.
     */
    public function rawOffset(?Ifd $ifd, int $tag): ?string
    {
        $value = $this->value($ifd, $tag);

        if (!is_string($value)) {
            return null;
        }

        $trimmed = rtrim(trim($value), "\0");

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Returns sanitized sub-second components limited to microsecond precision.
     */
    public function sanitizedSubSec(?Ifd $ifd, int $tag): ?string
    {
        $value = $this->value($ifd, $tag);

        if (is_string($value)) {
            $digits = preg_replace('/\D/', '', $value);

            return ($digits !== null && $digits !== '') ? $digits : null;
        }

        if ($value instanceof ExifNumericList) {
            $first = $value->values[0] ?? null;

            if ($first instanceof UInt64) {
                if (!$first->fitsSignedInt()) {
                    return null;
                }

                $first = $first->toInt('EXIF sub-second component');
            }

            if ($first === null) {
                return null;
            }

            return (string) (int) $first;
        }

        if (is_int($value)) {
            return (string) $value;
        }

        return null;
    }

    /**
     * Decodes a BOM-prefixed UTF-16 payload into a UTF-8 string.
     *
     * Both UTF-16LE (FF FE) and UTF-16BE (FE FF) byte order marks are recognised.
     * Returns null when the payload is empty, malformed, or cannot be converted.
     */
    public function decodeLegacyUnicodeFromBom(string $content): ?string
    {
        if (strlen($content) < 2) {
            return null;
        }

        $byteOrderMark = substr($content, 0, 2);
        $payload       = substr($content, 2);

        $encoding = match ($byteOrderMark) {
            "\xFF\xFE" => 'UTF-16LE',
            "\xFE\xFF" => 'UTF-16BE',
            default    => null,
        };

        if (($encoding === null) || ($payload === '') || (strlen($payload) % 2 !== 0)) {
            return null;
        }

        $converted = $this->convertTextToUtf8($encoding, $payload);

        if ($converted === null) {
            return null;
        }

        $trimmed = trim($converted, "\0 ");

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Decodes a BOM-less UTF-16LE byte payload into a UTF-8 string.
     *
     * Windows XP tags (XPTitle, XPComment, XPAuthor, XPKeywords, XPSubject) store
     * text as UTF-16LE encoded BYTE arrays without a byte order mark.
     * Returns null when the payload is empty, has odd length, or cannot be converted.
     */
    public function decodeUtf16Le(string $bytes): ?string
    {
        if (($bytes === '') || (strlen($bytes) % 2 !== 0)) {
            return null;
        }

        $converted = $this->convertTextToUtf8('UTF-16LE', $bytes);

        if ($converted === null) {
            return null;
        }

        $result = trim($converted, "\0 ");

        return $result === '' ? null : $result;
    }

    /**
     * Converts text to UTF-8 while handling iconv failures explicitly.
     */
    private function convertTextToUtf8(string $sourceEncoding, string $payload): ?string
    {
        set_error_handler(static fn (): bool => true);

        try {
            $converted = iconv($sourceEncoding, 'UTF-8', $payload);
        } finally {
            restore_error_handler();
        }

        return is_string($converted) ? $converted : null;
    }
}
