<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\IsoBmff;

use Closure;
use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\Util\Unpack;

use function count;
use function iconv;
use function is_bool;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function mb_check_encoding;
use function ord;
use function rtrim;
use function sprintf;
use function str_starts_with;
use function strtolower;
use function substr;
use function trim;

/**
 * Decodes type-specific QuickTime metadata values, performs coercion,
 * and validates locale indicators and data ordering per QuickTime File Format 2012 §9.
 *
 * @phpstan-type QuickTimeValue = string|int|float|bool
 * @phpstan-type QuickTimeKeyMap = array<string, QuickTimeValue>
 * @phpstan-type QuickTimeKeyEntry = array{namespace: string, name: string}
 * @phpstan-type QuickTimeRawDataAtom = array{type: int, locale: int, value: string|int|float, nestedKeys?: QuickTimeKeyMap, nestedAtoms?: QuickTimeDataAtomList}
 * @phpstan-type QuickTimeCoercedDataAtom = array{type: int, locale: int, value: string|int|float|bool}
 * @phpstan-type QuickTimeDataAtomList = array<string, list<QuickTimeCoercedDataAtom>>
 */
final readonly class QuickTimeValueDecoder
{
    /**
     * QuickTime metadata keys that should be coerced into expected value types.
     *
     * @var array<string, 'int'|'float'|'bool'|'string'>
     */
    private const array QUICKTIME_KEY_TYPES = [
        'com.apple.quicktime.videoOrientation'             => 'int',
        'com.apple.quicktime.location.accuracy.horizontal' => 'float',
        'com.apple.quicktime.location.accuracy.vertical'   => 'float',
        'com.apple.quicktime.isHDRVideo'                   => 'bool',
        'com.apple.quicktime.make'                         => 'string',
        'com.apple.quicktime.software'                     => 'string',
    ];

    /**
     * Maximum number of entries in a ctry/lang locale list atom.
     *
     * Protocol-defined ceiling (fits in a single byte).
     */
    public const int MAX_LOCALE_LIST_ENTRIES = 255;

    /**
     * @param Closure(string): array{keys: QuickTimeKeyMap, atoms: QuickTimeDataAtomList} $nestedMetadataParser Closure that parses nested type-28 metadata payloads.
     */
    public function __construct(
        private Closure $nestedMetadataParser,
    ) {
    }

    /**
     * Parses a `data` box into a structured array preserving the type indicator and locale.
     *
     * QuickTime File Format 2012, "Value Atom" (p. 139): the data atom header
     * contains a 32-bit type indicator and a 32-bit locale indicator. The type
     * indicator byte (bits 24–31) must be 0; the lower 24 bits identify the
     * well-known type. The locale indicator encodes country (upper 16 bits) and
     * language (lower 16 bits).
     *
     * @param BoxDescriptor $data Box descriptor for the `data` box.
     *
     * @return QuickTimeRawDataAtom
     */
    public function parseDataBoxStructured(BoxDescriptor $data): array
    {
        $win = $data->window;
        $win->seek(0);
        if ($data->contentSize < 8) {
            throw new ParseError('data box too small', 1251);
        }

        $type = $win->readU32BE();

        // QuickTime File Format 2012, "Type Indicator" (p. 139): the indicator
        // byte (bits 24–31) must be 0, meaning the type is drawn from the
        // well-known set. All other values are reserved.
        if (($type >> 24) !== 0) {
            throw new ParseError('data box type indicator byte must be 0', 1252);
        }

        $locale      = $win->readU32BE();
        $payloadSize = $data->contentSize - 8;
        $payload     = $payloadSize > 0 ? $win->read($payloadSize) : '';

        if ($type === QuickTimeDataType::NestedMetadata->value) {
            $nested = ($this->nestedMetadataParser)($payload);

            return [
                'type'        => $type,
                'locale'      => $locale,
                'value'       => '',
                'nestedKeys'  => $nested['keys'],
                'nestedAtoms' => $nested['atoms'],
            ];
        }

        return [
            'type'   => $type,
            'locale' => $locale,
            'value'  => $this->decodeDataPayload($type, $payload, $payloadSize),
        ];
    }

    /**
     * Decodes a data box payload according to its well-known type code.
     *
     * @param int    $type        Well-known type code (24-bit).
     * @param string $payload     Raw payload bytes.
     * @param int    $payloadSize Length of the payload in bytes.
     */
    public function decodeDataPayload(int $type, string $payload, int $payloadSize): string|int|float
    {
        $dataType = QuickTimeDataType::tryFrom($type);

        if (($dataType === QuickTimeDataType::Utf8) || ($dataType === QuickTimeDataType::Utf8Sort)) {
            if (!mb_check_encoding($payload, 'UTF-8')) {
                throw new ParseError('data box UTF-8 payload contains invalid byte sequence.', 1253);
            }

            // QuickTime File Format 2012, Table 3-5: UTF-8 variants are stored
            // as raw UTF-8 bytes without count/terminator metadata.
            return $payload;
        }

        if ($dataType === QuickTimeDataType::ShiftJis) {
            if (!mb_check_encoding($payload, 'SJIS')) {
                throw new ParseError('data box Shift-JIS payload contains malformed sequence.', 1450);
            }

            $converted = iconv('SJIS', 'UTF-8', $payload);
            if ($converted === false) {
                throw new ParseError('data box Shift-JIS payload contains malformed sequence.', 1919);
            }

            return rtrim($converted, "\0");
        }

        if (($dataType === QuickTimeDataType::Utf16) || ($dataType === QuickTimeDataType::Utf16Sort)) {
            if (($payloadSize % 2) !== 0) {
                throw new ParseError('data box UTF-16BE payload has odd byte count.', 1254);
            }

            $converted = iconv('UTF-16BE', 'UTF-8', $payload);

            if ($converted === false) {
                throw new ParseError('data box UTF-16BE payload contains malformed sequence.', 1255);
            }

            return rtrim($converted, "\0");
        }

        if ($dataType === QuickTimeDataType::JpegWrapper) {
            if (($payloadSize < 2) || (!str_starts_with($payload, "\xFF\xD8"))) {
                throw new ParseError('data box type 13 payload does not match JPEG/JFIF signature.', 1994);
            }

            return $payload;
        }

        if ($dataType === QuickTimeDataType::PngWrapper) {
            if (($payloadSize < 8) || (!str_starts_with($payload, "\x89PNG\x0D\x0A\x1A\x0A"))) {
                throw new ParseError('data box type 14 payload does not match PNG signature.', 1468);
            }

            return $payload;
        }

        if ($dataType === QuickTimeDataType::BmpWrapper) {
            if (($payloadSize < 2) || (!str_starts_with($payload, 'BM'))) {
                throw new ParseError('data box type 27 payload does not match BMP signature.', 1469);
            }

            return $payload;
        }

        $trimmed = trim($payload, "\0");

        if ($dataType === QuickTimeDataType::MacRoman) {
            $converted = iconv('macintosh', 'UTF-8', $trimmed);

            if ($converted === false) {
                throw new ParseError('MacRoman payload contains invalid byte sequence.', 1963);
            }

            return trim($converted, "\0");
        }

        // QuickTime File Format 2012 Table 3-5: type 21/22 encode integers
        // in 1, 2, 3, or 4 bytes (big-endian).
        if ($dataType === QuickTimeDataType::SignedInt) {
            return $this->decodeQuickTimeSignedInt($payload, $payloadSize);
        }

        if ($dataType === QuickTimeDataType::UnsignedInt) {
            return $this->decodeQuickTimeUnsignedInt($payload, $payloadSize);
        }

        if ($dataType === QuickTimeDataType::Float32) {
            // Reject truncated float32 payloads
            if ($payloadSize < 4) {
                throw new ParseError('data box float32 payload truncated', 1418);
            }

            if ($payloadSize > 4) {
                throw new ParseError('data box float32 payload must be exactly 4 bytes', 1911);
            }

            return Unpack::float('G', substr($payload, 0, 4), 'QuickTime float32 payload');
        }

        if ($dataType === QuickTimeDataType::Float64) {
            // Reject truncated float64 payloads
            if ($payloadSize < 8) {
                throw new ParseError('data box float64 payload truncated', 1419);
            }

            if ($payloadSize > 8) {
                throw new ParseError('data box float64 payload must be exactly 8 bytes', 1913);
            }

            return Unpack::float('E', substr($payload, 0, 8), 'QuickTime float64 payload');
        }

        return $payload;
    }

    /**
     * Coerces QuickTime metadata values into expected value types when possible.
     *
     * @param QuickTimeValue $value
     *
     * @return QuickTimeValue
     */
    public function coerceQuickTimeValue(string $key, string|int|float|bool $value): string|int|float|bool
    {
        /** @var 'int'|'float'|'bool'|'string'|null $targetType */
        $targetType = self::QUICKTIME_KEY_TYPES[$key] ?? null;
        if ($targetType === null) {
            return $value;
        }

        return match ($targetType) {
            'int'   => $this->parseQuickTimeInt($value) ?? $value,
            'float' => $this->parseQuickTimeFloat($value) ?? $value,
            'bool'  => $this->parseQuickTimeBool($value) ?? $value,
            default => is_string($value) ? $value : (string) $value,
        };
    }

    /**
     * Converts a four-character code into its integer representation.
     *
     * @param string $fourcc Four-character code to convert.
     */
    public function fourccToIndex(string $fourcc): ?int
    {
        if (strlen($fourcc) !== 4) {
            return null;
        }

        $value = Unpack::int('N', $fourcc, 'four-character code');

        return $value >= 0 ? $value : null;
    }

    /**
     * Validates a locale indicator from a data atom against the available locale lists.
     *
     * QuickTime File Format 2012, "Locale Indicator" (p. 139): country and language
     * indicator values 1–255 are 1-based indices into the ctry/lang list atoms.
     * Values > 255 are direct ISO codes. Value 0 means default/any.
     *
     * @param int             $locale        32-bit locale indicator (country << 16 | language).
     * @param list<list<int>> $countryLists  Country list arrays from ctry atom.
     * @param list<list<int>> $languageLists Language list arrays from lang atom.
     */
    public function validateLocaleIndicator(int $locale, array $countryLists, array $languageLists): void
    {
        $country  = ($locale >> 16) & 0xFFFF;
        $language = $locale & 0xFFFF;

        if ($country >= 1 && $country <= 255) {
            if ($countryLists === []) {
                throw new ParseError(sprintf('data atom locale country index %d requires a ctry list atom', $country), 1247);
            }

            if ($country > count($countryLists)) {
                throw new ParseError(sprintf('data atom locale country index %d exceeds ctry list entry count %d', $country, count($countryLists)), 1248);
            }
        }

        if ($language >= 1 && $language <= 255) {
            if ($languageLists === []) {
                throw new ParseError(sprintf('data atom locale language index %d requires a lang list atom', $language), 1249);
            }

            if ($language > count($languageLists)) {
                throw new ParseError(sprintf('data atom locale language index %d exceeds lang list entry count %d', $language, count($languageLists)), 1250);
            }
        }
    }

    /**
     * Validates that metadata item data atoms are ordered from most-specific to most-general.
     *
     * QuickTime File Format 2012, "Data Ordering" (p. 142): applications may
     * stop searching once they encounter an acceptable locale/type pair, which
     * requires deterministic ordering from specific locale variants to defaults.
     *
     * @param string                     $entryType  Item entry type for diagnostics.
     * @param list<QuickTimeRawDataAtom> $entryAtoms Parsed data atoms in encounter order.
     */
    public function validateDataOrdering(string $entryType, array $entryAtoms): void
    {
        $previousSpecificity = null;

        foreach ($entryAtoms as $atom) {
            $specificity = $this->localeSpecificityScore($atom['locale']);

            if (($previousSpecificity !== null) && ($specificity > $previousSpecificity)) {
                throw new ParseError(sprintf(
                    'metadata item "%s" data values must be ordered from most-specific to most-general per QuickTime File Format 2012 Data Ordering (p. 142)',
                    $entryType,
                ), 1985);
            }

            $previousSpecificity = $specificity;
        }
    }

    /**
     * Decodes a variable-width big-endian signed integer from a QuickTime data box.
     *
     * QuickTime File Format 2012, Table 3-5: type 21 defines up to 4-byte payloads.
     * Reader tolerance accepts 8-byte encodings observed in the wild.
     *
     * @param string $payload     Raw payload bytes.
     * @param int    $payloadSize Length of the payload in bytes.
     *
     * @return int Decoded signed integer value.
     */
    private function decodeQuickTimeSignedInt(string $payload, int $payloadSize): int
    {
        if ($payloadSize === 8) {
            $parts = unpack('Nhigh/Nlow', $payload);
            if ($parts === false || !isset($parts['high'], $parts['low']) || !is_int($parts['high']) || !is_int($parts['low'])) {
                throw new ParseError('Failed to decode QuickTime signed integer payload.', 2095);
            }

            $high = $parts['high'];
            $low  = $parts['low'];

            if (($high & 0x80000000) === 0) {
                return ($high << 32) | $low;
            }

            if (($high === 0x80000000) && ($low === 0)) {
                return PHP_INT_MIN;
            }

            $invHigh = (~$high) & 0xFFFFFFFF;
            $invLow  = (~$low) & 0xFFFFFFFF;

            if ($invLow === 0xFFFFFFFF) {
                $invLow = 0;
                ++$invHigh;
            } else {
                ++$invLow;
            }

            $magnitude = ($invHigh << 32) | $invLow;

            return -$magnitude;
        }

        $unsigned = $this->decodeQuickTimeUnsignedInt($payload, $payloadSize);
        $signBit  = 1 << (($payloadSize * 8) - 1);

        return ($unsigned >= $signBit) ? ($unsigned - ($signBit << 1)) : $unsigned;
    }

    /**
     * Decodes a variable-width big-endian unsigned integer from a QuickTime data box.
     *
     * QuickTime File Format 2012, Table 3-5: type 22 defines up to 4-byte payloads.
     * Reader tolerance accepts 8-byte encodings observed in the wild.
     *
     * @param string $payload     Raw payload bytes.
     * @param int    $payloadSize Length of the payload in bytes.
     *
     * @return int Decoded unsigned integer value.
     */
    private function decodeQuickTimeUnsignedInt(string $payload, int $payloadSize): int
    {
        if ($payloadSize < 1 || $payloadSize > 8) {
            throw new ParseError(
                sprintf('QuickTime integer payload must be 1–8 bytes, got %d', $payloadSize),
                1993,
            );
        }

        $value = 0;
        for ($i = 0; $i < $payloadSize; ++$i) {
            $byte = ord($payload[$i]);
            if ($value > intdiv(PHP_INT_MAX - $byte, 256)) {
                throw new ParseError('QuickTime integer payload exceeds supported integer range.', 2096);
            }

            $value = ($value * 256) + $byte;
        }

        return $value;
    }

    /**
     * Converts QuickTime metadata values into integers when possible.
     *
     * @param QuickTimeValue $value
     */
    private function parseQuickTimeInt(string|int|float|bool $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (is_float($value)) {
            $intValue = (int) $value;

            return (float) $intValue === $value ? $intValue : null;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    /**
     * Converts QuickTime metadata values into floats when possible.
     *
     * @param QuickTimeValue $value
     */
    private function parseQuickTimeFloat(string|int|float|bool $value): ?float
    {
        if (is_float($value)) {
            return $value;
        }

        if (is_int($value)) {
            return (float) $value;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }

    /**
     * Converts QuickTime metadata values into booleans when possible.
     *
     * @param QuickTimeValue $value
     */
    private function parseQuickTimeBool(string|int|float|bool $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (bool) $value;
        }

        $normalized = strtolower(trim($value));

        return match ($normalized) {
            'true', '1' => true,
            'false', '0' => false,
            default => null,
        };
    }

    /**
     * Computes the locale specificity score for ordering checks.
     *
     * Country and language indicators contribute one specificity point each.
     * A default locale (country=0, language=0) therefore ranks lowest.
     *
     * @param int $locale 32-bit locale indicator (country << 16 | language).
     */
    private function localeSpecificityScore(int $locale): int
    {
        $country  = ($locale >> 16) & 0xFFFF;
        $language = $locale & 0xFFFF;
        $score    = 0;

        if ($country !== 0) {
            ++$score;
        }

        if ($language !== 0) {
            ++$score;
        }

        return $score;
    }
}
