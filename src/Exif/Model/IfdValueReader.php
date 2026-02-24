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
use function round;
use function rtrim;
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
     * Reads and normalises a scalar tag value from an IFD.
     *
     * @param Ifd|null $ifd IFD to inspect.
     * @param int      $tag Tag identifier.
     *
     * @return int|float|string|ExifRational|ExifRationalList|ExifNumericList|null Normalised value.
     */
    public function normalisedValue(
        ?Ifd $ifd,
        int $tag,
    ): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null {
        $value = $this->value($ifd, $tag);

        return $this->normaliseScalarValue($value);
    }

    /**
     * Normalises scalar EXIF values, converting UInt64 when possible.
     *
     * @param int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value Raw value.
     *
     * @return int|float|string|ExifRational|ExifRationalList|ExifNumericList|null Normalised value.
     */
    public function normaliseScalarValue(
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
            $normalised = [];
            $changed    = false;

            foreach ($value->values as $component) {
                if ($component instanceof UInt64) {
                    // Convert list components to integers when safe.
                    if (!$component->fitsSignedInt()) {
                        return null;
                    }

                    $normalised[] = $component->toInt('EXIF numeric list normalisation');
                    $changed      = true;

                    continue;
                }

                $normalised[] = $component;
            }

            if ($changed) {
                return new ExifNumericList($normalised);
            }

            return $value;
        }

        return $value;
    }

    /**
     * Returns a normalised string value, trimming null bytes and spaces.
     *
     * @param Ifd|null $ifd IFD to inspect.
     * @param int      $tag Tag identifier.
     *
     * @return string|null Normalised string or null.
     */
    public function str(?Ifd $ifd, int $tag): ?string
    {
        $value = $this->normalisedValue($ifd, $tag);

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
        $value = $this->normalisedValue($ifd, $tag);

        return $this->coerceIntValue($value);
    }

    /**
     * Returns a rational or numeric value converted to float if present in the given IFD.
     */
    public function rational(?Ifd $ifd, int $tag): ?float
    {
        $value = $this->normalisedValue($ifd, $tag);

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
     * @return int|string|null Normalised enum scalar.
     */
    public function enumValue(?Ifd $ifd, int $tag): int|string|null
    {
        $value = $this->value($ifd, $tag);

        return $this->normaliseEnumScalar($value);
    }

    /**
     * Normalises a mixed EXIF value to an enum-compatible scalar.
     *
     * @param int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value Raw value.
     *
     * @return int|string|null Enum-compatible scalar value.
     */
    public function normaliseEnumScalar(
        int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value,
    ): int|string|null {
        if ($value instanceof ExifNumericList) {
            // Only the first entry is relevant for enum conversion.
            $first = $value->values[0] ?? null;

            return $this->normaliseEnumScalar($first);
        }

        if ($value instanceof ExifRationalList) {
            // Only the first entry is relevant for enum conversion.
            $first = $value->values[0] ?? null;

            return $this->normaliseEnumScalar($first);
        }

        if ($value instanceof ExifRational) {
            // Reduce rationals to a rounded integer for enum lookups.
            $float = $this->converters->rationalToFloat($value);

            return $float === null ? null : $this->normaliseEnumScalar($float);
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

        if (is_string($value) && $value !== '') {
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
            $digits = preg_replace('/[^0-9]/', '', $value);

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

        $converted = @iconv($encoding, 'UTF-8', $payload);
        if ($converted === false) {
            return null;
        }

        $trimmed = trim($converted, "\0 ");

        return $trimmed === '' ? null : $trimmed;
    }
}
