<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\MakerNotes;

use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleMakerNotes;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleMaps;
use MagicSunday\ImageMeta\MakerNotes\Apple\ApplePlistArray;
use MagicSunday\ImageMeta\MakerNotes\Apple\ApplePlistDictionary;
use MagicSunday\ImageMeta\MakerNotes\Apple\ApplePlistScalar;
use MagicSunday\ImageMeta\MakerNotes\Apple\ApplePlistValue;
use MagicSunday\ImageMeta\MakerNotes\Apple\BinaryPlistDecoder;
use MagicSunday\ImageMeta\MakerNotes\Apple\KeyedArchiveUnarchiver;
use MagicSunday\ImageMeta\MakerNotes\Apple\Support\SemanticStyle;
use MagicSunday\ImageMeta\Value\RunTime;

use function array_any;
use function array_find;
use function array_flip;
use function array_is_list;
use function array_key_exists;
use function array_unique;
use function array_values;
use function count;
use function ctype_space;
use function ctype_xdigit;
use function hexdec;
use function in_array;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function preg_match;
use function preg_split;
use function sha1;
use function sort;
use function str_contains;
use function str_starts_with;
use function strlen;
use function strpos;
use function strtolower;
use function substr;
use function trim;

/**
 * Decoder that extracts structured metadata from Apple maker note payloads.
 */
final class AppleDecoder implements MakerNotesDecoderInterface
{
    /**
     * Creates a metadata value object describing the Apple maker note payload.
     *
     * @param string      $raw   Raw maker note data stream.
     * @param string      $make  Reported camera make string.
     * @param string|null $model Optional camera model identifier.
     */
    public function decode(string $raw, string $make, ?string $model): MakerNotesRecord
    {
        $appleData = $this->parseAppleData($raw);

        return new MakerNotesRecord(
            'Apple',
            strlen($raw),
            sha1($raw),
            $appleData
        );
    }

    /**
     * Parses the raw Apple maker note payload into a structured representation.
     *
     * @param string $raw Raw maker note data stream.
     *
     * @return AppleMakerNotes|null Parsed maker notes instance or null when the payload cannot be decoded.
     */
    private function parseAppleData(string $raw): ?AppleMakerNotes
    {
        $decoded = $this->decodeBinaryPropertyList($raw);
        if ($decoded === null) {
            $decoded = $this->parseRawDictionaryPayload($raw);
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            return null;
        }

        /** @var array<int|string, mixed> $dictionary */
        $dictionary = $decoded;

        $decoded = $this->resolveKeyedArchiveDictionary($dictionary);
        if ($decoded === null || array_is_list($decoded)) {
            return null;
        }

        return $this->buildAppleMakerNotes($decoded);
    }

    /**
     * Attempts to decode the supplied payload as binary property list.
     *
     * @param string $raw Raw maker note data stream.
     *
     * @return array<int|string, mixed>|bool|float|int|string|null
     */
    private function decodeBinaryPropertyList(string $raw): array|string|int|float|bool|null
    {
        $signatureOffset = strpos($raw, 'bplist00');
        if ($signatureOffset === false) {
            return null;
        }

        $payload = substr($raw, $signatureOffset);

        try {
            $value = (new BinaryPlistDecoder())->decode($payload);
        } catch (ParseError) {
            return null;
        }

        return $this->plistValueToPhp($value);
    }

    /**
     * Parses a textual Apple NSDictionary representation.
     *
     * Apple devices sometimes embed maker notes as the string form of a
     * dictionary instead of a binary property list. The representation mirrors
     * the Objective-C `-[NSDictionary description]` format using braces,
     * semicolon separated key/value pairs, and parentheses for arrays.
     *
     * @param string $raw Raw maker note data stream.
     *
     * @return array<int|string, mixed>|null
     */
    private function parseRawDictionaryPayload(string $raw): ?array
    {
        $offset = 0;
        $length = strlen($raw);

        $this->skipWhitespace($raw, $offset, $length);
        if ($offset >= $length || $raw[$offset] !== '{') {
            return null;
        }

        try {
            $dictionary = $this->parseDictionary($raw, $offset, $length);
        } catch (ParseError) {
            return null;
        }

        $this->skipWhitespace($raw, $offset, $length);
        if ($offset < $length) {
            return null;
        }

        return $dictionary;
    }

    /**
     * Parses a dictionary section from the textual representation.
     *
     * @return array<int|string, mixed>
     */
    private function parseDictionary(string $raw, int &$offset, int $length): array
    {
        if ($raw[$offset] !== '{') {
            throw new ParseError('Expected dictionary opening brace.');
        }

        ++$offset;
        $dictionary = [];

        while (true) {
            $this->skipWhitespace($raw, $offset, $length);
            if ($offset >= $length) {
                throw new ParseError('Unterminated dictionary payload.');
            }

            $char = $raw[$offset];
            if ($char === '}') {
                ++$offset;

                break;
            }

            $key = $this->parseKey($raw, $offset, $length);

            $this->skipWhitespace($raw, $offset, $length);
            if ($offset >= $length) {
                throw new ParseError('Dictionary entry without value.');
            }

            $delimiter = $raw[$offset];
            if ($delimiter !== '=' && $delimiter !== ':') {
                throw new ParseError('Dictionary entry is missing a separator.');
            }

            ++$offset;

            $value            = $this->parseValue($raw, $offset, $length);
            $dictionary[$key] = $value;

            $this->skipWhitespace($raw, $offset, $length);
            if ($offset >= $length) {
                throw new ParseError('Unexpected end of dictionary payload.');
            }

            $terminator = $raw[$offset];
            if ($terminator === ';' || $terminator === ',') {
                ++$offset;

                continue;
            }

            if ($terminator === '}') {
                continue;
            }
        }

        return $dictionary;
    }

    /**
     * Parses a single value from the textual dictionary representation.
     *
     * @return array<int|string, mixed>|bool|float|int|string|null
     */
    private function parseValue(string $raw, int &$offset, int $length): array|bool|float|int|string|null
    {
        $this->skipWhitespace($raw, $offset, $length);
        if ($offset >= $length) {
            throw new ParseError('Missing value for dictionary entry.');
        }

        $char = $raw[$offset];
        if ($char === '{') {
            return $this->parseDictionary($raw, $offset, $length);
        }

        if ($char === '(') {
            return $this->parseArray($raw, $offset, $length);
        }

        if ($char === '"') {
            return $this->parseQuotedString($raw, $offset, $length);
        }

        $word = $this->parseWord($raw, $offset, $length);
        if ($word === '') {
            return null;
        }

        $lower = strtolower($word);
        if ($lower === 'true' || $word === 'YES') {
            return true;
        }

        if ($lower === 'false' || $word === 'NO') {
            return false;
        }

        if ($lower === 'null') {
            return null;
        }

        if (is_numeric($word)) {
            if (str_contains($word, '.') || str_contains($word, 'e') || str_contains($word, 'E')) {
                return (float) $word;
            }

            return (int) $word;
        }

        return $word;
    }

    /**
     * Parses an array from Apple's legacy text format.
     *
     * @param string $raw    Raw text-format payload.
     * @param int    $offset Current parsing offset (modified by reference).
     * @param int    $length Total payload length.
     *
     * @return array<int, array<int|string, mixed>|bool|float|int|string|null> Parsed array values.
     *
     * @phpstan-return array<int, array<int|string, mixed>|bool|float|int|string|null>
     *
     * @throws ParseError If array syntax is invalid.
     */
    private function parseArray(string $raw, int &$offset, int $length): array
    {
        if ($raw[$offset] !== '(') {
            throw new ParseError('Expected array opening parenthesis.');
        }

        ++$offset;
        /** @var array<int, array<int|string, mixed>|bool|float|int|string|null> $values */
        $values = [];

        while (true) {
            $this->skipWhitespace($raw, $offset, $length);
            if ($offset >= $length) {
                throw new ParseError('Unterminated array payload.');
            }

            if ($raw[$offset] === ')') {
                ++$offset;

                break;
            }

            $values[] = $this->parseValue($raw, $offset, $length);

            $this->skipWhitespace($raw, $offset, $length);
            if ($offset >= $length) {
                throw new ParseError('Unexpected end of array payload.');
            }

            $terminator = $raw[$offset];
            if ($terminator === ',' || $terminator === ';') {
                ++$offset;
            }

            if ($terminator === ')') {
                continue;
            }
        }

        /** @var array<int, array<int|string, mixed>|bool|float|int|string|null> $values */
        return $values;
    }

    private function parseQuotedString(string $raw, int &$offset, int $length): string
    {
        if ($raw[$offset] !== '"') {
            throw new ParseError('Expected quoted string.');
        }

        ++$offset;
        $start  = $offset;
        $buffer = '';

        while ($offset < $length) {
            $char = $raw[$offset];
            if ($char === '\\') {
                if ($offset + 1 >= $length) {
                    throw new ParseError('Invalid escape sequence in string.');
                }

                $next = $raw[$offset + 1];
                $buffer .= substr($raw, $start, $offset - $start);
                $buffer .= $next;
                $offset += 2;
                $start = $offset;

                continue;
            }

            if ($char === '"') {
                $buffer .= substr($raw, $start, $offset - $start);
                ++$offset;

                return $buffer;
            }

            ++$offset;
        }

        throw new ParseError('Unterminated quoted string.');
    }

    private function parseWord(string $raw, int &$offset, int $length): string
    {
        $start = $offset;

        while ($offset < $length) {
            $char = $raw[$offset];
            if (
                in_array($char, [';', ',', ')', '}'], true)
                || ctype_space($char)
            ) {
                break;
            }

            ++$offset;
        }

        return substr($raw, $start, $offset - $start);
    }

    private function parseKey(string $raw, int &$offset, int $length): string
    {
        $this->skipWhitespace($raw, $offset, $length);
        if ($offset >= $length) {
            throw new ParseError('Missing dictionary key.');
        }

        if ($raw[$offset] === '"') {
            return $this->parseQuotedString($raw, $offset, $length);
        }

        $key = $this->parseWord($raw, $offset, $length);
        if ($key === '') {
            throw new ParseError('Dictionary key is empty.');
        }

        return $key;
    }

    private function skipWhitespace(string $raw, int &$offset, int $length): void
    {
        while ($offset < $length) {
            $char = $raw[$offset];

            if ($char === "\0") {
                ++$offset;

                continue;
            }

            if (!ctype_space($char)) {
                break;
            }

            ++$offset;
        }
    }

    /**
     * Checks if a dictionary represents an NSKeyedArchive structure.
     *
     * @param array<int|string, mixed> $dictionary Dictionary to check.
     *
     * @return bool True if dictionary is a keyed archive.
     */
    private function isKeyedArchive(array $dictionary): bool
    {
        if (!array_key_exists('$archiver', $dictionary)) {
            return false;
        }

        if (!array_key_exists('$top', $dictionary) || !is_array($dictionary['$top'])) {
            return false;
        }

        if (!array_key_exists('$objects', $dictionary) || !is_array($dictionary['$objects'])) {
            return false;
        }

        $top = $dictionary['$top'];

        return $this->containsUidReference($top);
    }

    /**
     * Recursively checks if a value contains UID references.
     *
     * @param array<int|string, mixed> $value Value to inspect.
     *
     * @return bool True if value or nested values contain CF$UID keys.
     */
    private function containsUidReference(array $value): bool
    {
        if (array_key_exists('CF$UID', $value)) {
            return true;
        }

        return array_any($value, fn ($entry): bool => is_array($entry) && $this->containsUidReference($entry));
    }

    /**
     * Resolves and unarchives a keyed archive dictionary.
     *
     * @param array<int|string, mixed> $dictionary Raw dictionary from binary plist.
     *
     * @return array<int|string, mixed>|null Unarchived dictionary or null if not a keyed archive.
     */
    private function resolveKeyedArchiveDictionary(array $dictionary): ?array
    {
        $unarchived = $this->unarchiveKeyedArchive($dictionary);
        if ($unarchived !== null) {
            return $unarchived;
        }

        foreach ($dictionary as $value) {
            if (!is_array($value)) {
                continue;
            }

            $candidate = $this->resolveNestedKeyedArchive($value);
            if ($candidate !== null) {
                return $candidate;
            }
        }

        return array_is_list($dictionary) ? null : $dictionary;
    }

    /**
     * Recursively searches for and resolves nested keyed archive structures.
     *
     * @param array<int|string, mixed> $value Value that may contain nested keyed archives.
     *
     * @return array<int|string, mixed>|null Resolved archive or null if not found.
     */
    private function resolveNestedKeyedArchive(array $value): ?array
    {
        $unarchived = $this->unarchiveKeyedArchive($value);
        if ($unarchived !== null) {
            return $unarchived;
        }

        if (array_is_list($value)) {
            foreach ($value as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $candidate = $this->resolveNestedKeyedArchive($entry);
                if ($candidate !== null) {
                    return $candidate;
                }
            }

            return null;
        }

        foreach ($value as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $candidate = $this->resolveNestedKeyedArchive($entry);
            if ($candidate !== null) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Unarchives an NSKeyedArchive dictionary to plain dictionary.
     *
     * @param array<int|string, mixed> $dictionary Keyed archive structure.
     *
     * @return array<int|string, mixed>|null Unarchived dictionary or null if invalid.
     */
    private function unarchiveKeyedArchive(array $dictionary): ?array
    {
        if ($this->isKeyedArchive($dictionary)) {
            return $this->unarchiveNormalisedKeyedArchive($dictionary);
        }

        $normalised = $this->normaliseKeyedArchive($dictionary);
        if ($normalised === null) {
            return null;
        }

        return $this->unarchiveNormalisedKeyedArchive($normalised);
    }

    /**
     * Unarchives a normalized keyed archive dictionary.
     *
     * @param array<int|string, mixed> $dictionary Normalized keyed archive structure.
     *
     * @return array<int|string, mixed>|null Unarchived dictionary or null if invalid.
     */
    private function unarchiveNormalisedKeyedArchive(array $dictionary): ?array
    {
        try {
            $plist = $this->nativeToPlistValue($dictionary);
            if (!$plist instanceof ApplePlistDictionary) {
                return null;
            }

            $resolved = (new KeyedArchiveUnarchiver())->unarchive($plist);
            $native   = $this->plistValueToPhp($resolved);

            return is_array($native) && !array_is_list($native) ? $native : null;
        } catch (ParseError) {
            return null;
        }
    }

    /**
     * Converts a property list value into native PHP types.
     *
     * @return array<int|string, mixed>|bool|float|int|string|null
     */
    private function plistValueToPhp(ApplePlistValue $value): array|string|int|float|bool|null
    {
        if ($value instanceof ApplePlistScalar) {
            return $value->value();
        }

        if ($value instanceof ApplePlistArray) {
            $result = [];
            foreach ($value->values() as $entry) {
                $result[] = $this->plistValueToPhp($entry);
            }

            return $result;
        }

        if ($value instanceof ApplePlistDictionary) {
            return array_map(
                $this->plistValueToPhp(...),
                $value->entries()
            );
        }

        throw new ParseError('Unsupported property list value.');
    }

    private function nativeToPlistValue(mixed $value): ApplePlistValue
    {
        if (!is_array($value)) {
            if (is_bool($value) || is_int($value) || is_float($value) || is_string($value) || $value === null) {
                return new ApplePlistScalar($value);
            }

            throw new ParseError('Unsupported scalar property list value.');
        }

        if (array_is_list($value)) {
            $entries = [];
            foreach ($value as $entry) {
                $entries[] = $this->nativeToPlistValue($entry);
            }

            return new ApplePlistArray($entries);
        }

        $entries = [];
        foreach ($value as $key => $entry) {
            if (!is_string($key)) {
                throw new ParseError('Property list dictionaries must use string keys.');
            }

            $entries[$key] = $this->nativeToPlistValue($entry);
        }

        return new ApplePlistDictionary($entries);
    }

    /**
     * Normalizes a keyed archive dictionary to standard structure.
     *
     * @param array<int|string, mixed> $dictionary Raw keyed archive dictionary.
     *
     * @return array<int|string, mixed>|null Normalized structure or null if invalid.
     */
    private function normaliseKeyedArchive(array $dictionary): ?array
    {
        $objectsKey = $this->firstExistingKey($dictionary, '$objects', 'objects');
        if ($objectsKey === null) {
            return null;
        }

        $topKey = $this->firstExistingKey($dictionary, '$top', 'top');
        if ($topKey === null) {
            return null;
        }

        $objects = $dictionary[$objectsKey];
        $top     = $dictionary[$topKey];

        if (!is_array($objects) || !is_array($top)) {
            return null;
        }

        if (!$this->containsUidReference($top)) {
            return null;
        }

        $normalised             = $dictionary;
        $normalised['$objects'] = $objects;
        $normalised['$top']     = $top;

        if (!array_key_exists('$archiver', $normalised)) {
            $archiverKey = $this->firstExistingKey($dictionary, '$archiver', 'archiver');
            if ($archiverKey !== null) {
                $normalised['$archiver'] = $dictionary[$archiverKey];
            }
        }

        if (!array_key_exists('$version', $normalised)) {
            $versionKey = $this->firstExistingKey($dictionary, '$version', 'version');
            if ($versionKey !== null) {
                $normalised['$version'] = $dictionary[$versionKey];
            }
        }

        return $normalised;
    }

    /**
     * Returns the first existing key from a prioritized list.
     *
     * @param array<int|string, mixed> $dictionary Dictionary to search.
     * @param string                   ...$keys    Priority-ordered keys to check.
     *
     * @return string|null First matching key or null if none exist.
     */
    private function firstExistingKey(array $dictionary, string ...$keys): ?string
    {
        return array_find(
            $keys,
            static fn (string $key): bool => array_key_exists($key, $dictionary)
        );
    }

    /**
     * Builds an AppleMakerNotes value object from decoded dictionary.
     *
     * @param array<int|string, mixed> $dictionary Decoded maker notes dictionary.
     *
     * @phpstan-param array<int|string, mixed> $dictionary
     *
     * @return AppleMakerNotes|null Apple maker notes object or null if invalid.
     */
    private function buildAppleMakerNotes(array $dictionary): ?AppleMakerNotes
    {
        /** @var array<int|string, mixed> $dictionary */
        $semanticStyleCompact = null;
        if (
            !array_key_exists('SemanticStylePreset', $dictionary)
            && !array_key_exists('SemanticStyleWarmth', $dictionary)
            && !array_key_exists('SemanticStyleTone', $dictionary)
        ) {
            /** @var array<int|string, bool|float|int|string|array<int|string, bool|float|int|string|array<int|string, bool|float|int|string|array<int|string, bool|float|int|string|null>|null>|null>|object|null> $semanticDictionary */
            $semanticDictionary   = $dictionary;
            $semanticStyleCompact = SemanticStyle::fromDictionary($semanticDictionary);
            if ($semanticStyleCompact !== null) {
                [$compactPreset, $compactWarmth, $compactTone] = $semanticStyleCompact;

                if ($compactPreset !== null) {
                    $dictionary['SemanticStylePreset'] = $compactPreset;
                }

                if ($compactWarmth !== null) {
                    $dictionary['SemanticStyleWarmth'] = $compactWarmth;
                }

                if ($compactTone !== null) {
                    $dictionary['SemanticStyleTone'] = $compactTone;
                }
            }
        }

        $contentIdentifier = $this->stringValue($dictionary, 'ContentIdentifier');
        $cameraTypeCode    = $this->intValue($dictionary, 'CameraType');

        if ($cameraTypeCode !== null) {
            $cameraType = AppleMaps::CAMERA_TYPE_MAP[$cameraTypeCode] ?? $cameraTypeCode;
        } else {
            $cameraType = $this->stringValue($dictionary, 'CameraType');
        }

        $hdrHeadroom             = $this->floatValue($dictionary, 'HdrHeadroom', 'HDRHeadroom');
        $hdrGain                 = $this->floatList($dictionary, 'HdrGain', 'HDRGain');
        $snr                     = $this->floatValue($dictionary, 'SNRSetting', 'SNR');
        $aeStable                = $this->boolDictionaryValue($dictionary, 'AEStable');
        $aeTarget                = $this->rationalFloatValue($dictionary, 'AETarget');
        $aeAverage               = $this->rationalFloatValue($dictionary, 'AEAverage');
        $afStable                = $this->boolDictionaryValue($dictionary, 'AFStable');
        $afPerformance           = $this->rationalFloatValue($dictionary, 'AFPerformance');
        $signalToNoiseRatioType  = $this->stringOrIntValue($dictionary, 'SignalToNoiseRatioType');
        $luminanceNoiseAmplitude = $this->rationalFloatValue($dictionary, 'LuminanceNoiseAmplitude');
        $focusPosition           = $this->floatValue($dictionary, 'FocusPosition');
        $runTime                 = $this->runTimeValue($dictionary, 'RunTime');
        $livePhotoIndex          = $this->intValue($dictionary, ...AppleMaps::LIVE_PHOTO_INDEX_KEYS);
        $livePhotoTime           = null;
        if ($livePhotoIndex !== null && $runTime instanceof RunTime) {
            $timescale = $runTime->timescale;
            if ($timescale !== null && $timescale > 0) {
                $livePhotoTime = $livePhotoIndex / $timescale;
            }
        }

        $colorTemperature    = $this->intValue($dictionary, 'ColorTemperature');
        $semanticStylePreset = $this->stringValue($dictionary, 'SemanticStylePreset');
        $semanticStyleWarmth = $this->floatValue($dictionary, 'SemanticStyleWarmth');
        $semanticStyleTone   = $this->floatValue($dictionary, 'SemanticStyleTone');

        if ($semanticStyleCompact === null) {
            /** @var array<int|string, bool|float|int|string|array<int|string, bool|float|int|string|array<int|string, bool|float|int|string|array<int|string, bool|float|int|string|null>|null>|null>|object|null> $semanticDictionary */
            $semanticDictionary   = $dictionary;
            $semanticStyleCompact = SemanticStyle::fromDictionary($semanticDictionary);
        }

        if ($semanticStyleCompact !== null) {
            [$compactPreset, $compactWarmth, $compactTone] = $semanticStyleCompact;

            if ($semanticStylePreset === null && $compactPreset !== null) {
                $semanticStylePreset = $compactPreset;
            }

            if ($semanticStyleWarmth === null && $compactWarmth !== null) {
                $semanticStyleWarmth = $compactWarmth;
            }

            if ($semanticStyleTone === null && $compactTone !== null) {
                $semanticStyleTone = $compactTone;
            }
        }

        $accelerationVector    = $this->floatList($dictionary, 'AccelerationVector');
        $flags                 = $this->extractFlags($dictionary);
        $imageCaptureRequestId = $this->identifierValue($dictionary, 'ImageCaptureRequestID');
        $qualityHint           = $this->stringOrNumericValue($dictionary, 'QualityHint');
        $colorCorrectionMatrix = $this->floatList($dictionary, 'ColorCorrectionMatrix');

        $makerNoteVersion   = $this->makerNoteVersionValue($dictionary, 'MakerNoteVersion');
        $hdrImageType       = $this->enumeratedStringValue($dictionary, AppleMaps::HDR_IMAGE_TYPES, 'HDRImageType', 'HdrImageType');
        $burstUuid          = $this->stringValue($dictionary, 'BurstUUID');
        $focusDistanceRange = $this->focusDistanceRangeValue($dictionary);
        $oisMode            = $this->stringOrNumericValue($dictionary, 'OISMode');
        $imageCaptureType   = $this->enumeratedStringValue($dictionary, AppleMaps::IMAGE_CAPTURE_TYPES, 'ImageCaptureType');
        $imageUniqueId      = $this->stringValue($dictionary, 'ImageUniqueID');
        $photoIdentifier    = $this->stringValue($dictionary, 'PhotoIdentifier');
        $afMeasuredDepth    = $this->floatValue($dictionary, 'AFMeasuredDepth');
        $afConfidence       = $this->floatValue($dictionary, 'AFConfidence');

        if (
            $contentIdentifier === null
            && $cameraType === null
            && $hdrHeadroom === null
            && $hdrGain === null
            && $snr === null
            && $aeStable === null
            && $aeTarget === null
            && $aeAverage === null
            && $afStable === null
            && $afPerformance === null
            && $signalToNoiseRatioType === null
            && $luminanceNoiseAmplitude === null
            && $focusPosition === null
            && $livePhotoIndex === null
            && $livePhotoTime === null
            && $colorTemperature === null
            && $semanticStylePreset === null
            && $semanticStyleWarmth === null
            && $semanticStyleTone === null
            && $flags === []
            && $accelerationVector === null
            && $imageCaptureRequestId === null
            && $qualityHint === null
            && $colorCorrectionMatrix === null
            && !$runTime instanceof RunTime
            && $makerNoteVersion === null
            && $hdrImageType === null
            && $burstUuid === null
            && $focusDistanceRange === null
            && $oisMode === null
            && $imageCaptureType === null
            && $imageUniqueId === null
            && $photoIdentifier === null
            && $afMeasuredDepth === null
            && $afConfidence === null
        ) {
            return null;
        }

        return new AppleMakerNotes(
            $contentIdentifier,
            $cameraType,
            $hdrHeadroom,
            $hdrGain,
            $snr,
            $aeStable,
            $aeTarget,
            $aeAverage,
            $afStable,
            $afPerformance,
            $signalToNoiseRatioType,
            $luminanceNoiseAmplitude,
            $focusPosition,
            $livePhotoIndex,
            $colorTemperature,
            $semanticStylePreset,
            $semanticStyleWarmth,
            $semanticStyleTone,
            $flags,
            $accelerationVector,
            $imageCaptureRequestId,
            $qualityHint,
            $colorCorrectionMatrix,
            $livePhotoTime,
            $runTime,
            $makerNoteVersion,
            $hdrImageType,
            $burstUuid,
            $focusDistanceRange,
            $oisMode,
            $imageCaptureType,
            $imageUniqueId,
            $photoIdentifier,
            $afMeasuredDepth,
            $afConfidence,
        );
    }

    /**
     * Extracts a RunTime value from dictionary.
     *
     * @param array<int|string, mixed> $dictionary Dictionary containing runtime data.
     * @param string                   $key        Key to look up.
     *
     * @phpstan-param array<int|string, mixed> $dictionary
     *
     * @return RunTime|null RunTime value object or null if not found.
     */
    private function runTimeValue(array $dictionary, string $key): ?RunTime
    {
        if (!array_key_exists($key, $dictionary)) {
            return null;
        }

        $value = $dictionary[$key];
        if (!is_array($value)) {
            return null;
        }

        /** @var array<int|string, mixed> $value */
        $epoch     = $this->intValue($value, 'epoch', 'Epoch');
        $timescale = $this->intValue($value, 'timescale', 'Timescale');
        $rawValue  = $this->intValue($value, 'value', 'Value');
        $flags     = $this->intValue($value, 'flags', 'Flags');

        if ($epoch === null && $timescale === null && $rawValue === null && $flags === null) {
            return null;
        }

        return new RunTime($epoch, $timescale, $rawValue, $flags);
    }

    /**
     * Extracts a boolean value from dictionary using prioritized keys.
     *
     * @param array<int|string, mixed> $dictionary Dictionary to search.
     * @param string                   ...$keys    Priority-ordered keys to check.
     *
     * @phpstan-param array<int|string, mixed> $dictionary
     *
     * @return bool|null Boolean value if found, null otherwise.
     */
    private function boolDictionaryValue(array $dictionary, string ...$keys): ?bool
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $dictionary)) {
                continue;
            }

            /** @var array<int|string, mixed>|bool|float|int|string|null $candidate */
            $candidate = $dictionary[$key];
            $value     = $this->boolValue($candidate);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Extracts a rational float value from dictionary using prioritized keys.
     *
     * @param array<int|string, mixed> $dictionary Dictionary to search.
     * @param string                   ...$keys    Priority-ordered keys to check.
     *
     * @phpstan-param array<int|string, mixed> $dictionary
     *
     * @return float|null Rational float value if found, null otherwise.
     */
    private function rationalFloatValue(array $dictionary, string ...$keys): ?float
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $dictionary)) {
                continue;
            }

            /** @var array<int|string, mixed>|bool|float|int|string|null $candidate */
            $candidate = $dictionary[$key];
            $float     = $this->normaliseRationalFloat($candidate);
            if ($float !== null) {
                return $float;
            }
        }

        return null;
    }

    /**
     * Normalizes a rational value to float representation.
     *
     * @param string|int|float|bool|array<int|string, mixed>|null $value Raw value to normalize.
     *
     * @return float|null Normalized float value or null if invalid.
     */
    private function normaliseRationalFloat(string|int|float|bool|array|null $value): ?float
    {
        if (is_float($value)) {
            return $value;
        }

        if (is_int($value)) {
            return (float) $value;
        }

        if (is_string($value)) {
            $normalized = trim($value);
            if ($normalized === '') {
                return null;
            }

            if (str_contains($normalized, '/')) {
                [$numeratorRaw, $denominatorRaw] = explode('/', $normalized, 2);
                $numerator                       = trim($numeratorRaw);
                $denominator                     = trim($denominatorRaw);

                if ($numerator === '' || $denominator === '' || !is_numeric($numerator) || !is_numeric($denominator)) {
                    return null;
                }

                $denominatorFloat = (float) $denominator;
                if ($denominatorFloat === 0.0) {
                    return null;
                }

                return (float) $numerator / $denominatorFloat;
            }

            $components = preg_split('/\s+/', $normalized);
            if ($components !== false && count($components) === 2) {
                [$numerator, $denominator] = $components;

                if ($numerator !== '' && $denominator !== '' && is_numeric($numerator) && is_numeric($denominator)) {
                    $denominatorFloat = (float) $denominator;
                    if ($denominatorFloat === 0.0) {
                        return null;
                    }

                    return (float) $numerator / $denominatorFloat;
                }
            }

            if (!is_numeric($normalized)) {
                return null;
            }

            return (float) $normalized;
        }

        if (!is_array($value)) {
            return null;
        }

        foreach (['value', 'Value', 'data', 'Data', 'ratio', 'Ratio'] as $key) {
            if (array_key_exists($key, $value)) {
                /** @var array<int|string, mixed>|bool|float|int|string|null $candidate */
                $candidate = $value[$key];
                $nested    = $this->normaliseRationalFloat($candidate);
                if ($nested !== null) {
                    return $nested;
                }
            }
        }

        if (array_key_exists('values', $value) && is_array($value['values'])) {
            /** @var array<int|string, mixed> $candidate */
            $candidate = $value['values'];
            $nested    = $this->normaliseRationalFloat($candidate);
            if ($nested !== null) {
                return $nested;
            }
        }

        $numerator   = $this->numericComponentFromArray($value, 'numerator', 'Numerator', 'num', 'Num', 'numer');
        $denominator = $this->numericComponentFromArray($value, 'denominator', 'Denominator', 'den', 'Den', 'denom', 'Denom');
        if ($numerator !== null && $denominator !== null) {
            if ($denominator === 0.0) {
                return null;
            }

            return $numerator / $denominator;
        }

        if (!array_is_list($value)) {
            return null;
        }

        $count = count($value);
        if ($count >= 2) {
            /** @var array<int|string, mixed>|bool|float|int|string|null $component */
            $component = $value[0];
            $num       = $this->numericScalarValue($component);

            /** @var array<int|string, mixed>|bool|float|int|string|null $component */
            $component = $value[1];
            $den       = $this->numericScalarValue($component);
            if ($num !== null && $den !== null && $den !== 0.0) {
                return $num / $den;
            }
        }

        foreach ($value as $entry) {
            /** @var array<int|string, mixed>|bool|float|int|string|null $entryValue */
            $entryValue = $entry;
            $float      = $this->normaliseRationalFloat($entryValue);
            if ($float !== null) {
                return $float;
            }
        }

        return null;
    }

    /**
     * Extracts a numeric component from an array using prioritized keys.
     *
     * @param array<int|string, mixed> $value   Array containing numeric components.
     * @param string                   ...$keys Priority-ordered keys to check.
     *
     * @phpstan-param array<int|string, mixed> $value
     *
     * @return float|null Numeric component value or null if not found.
     */
    private function numericComponentFromArray(array $value, string ...$keys): ?float
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $value)) {
                continue;
            }

            /** @var array<int|string, mixed>|bool|float|int|string|null $candidate */
            $candidate = $value[$key];
            $numeric   = $this->numericScalarValue($candidate);
            if ($numeric !== null) {
                return $numeric;
            }
        }

        return null;
    }

    /**
     * Normalizes a scalar value to numeric representation.
     *
     * @param string|int|float|bool|array<int|string, mixed>|null $value Raw scalar value.
     *
     * @return float|null Numeric value or null if invalid.
     */
    private function numericScalarValue(string|int|float|bool|array|null $value): ?float
    {
        if (is_float($value)) {
            return $value;
        }

        if (is_int($value)) {
            return (float) $value;
        }

        if (is_string($value)) {
            $normalized = trim($value);
            if ($normalized === '') {
                return null;
            }

            if (is_numeric($normalized)) {
                return (float) $normalized;
            }

            return $this->normaliseRationalFloat($normalized);
        }

        if (is_array($value)) {
            return $this->normaliseRationalFloat($value);
        }

        return null;
    }

    /**
     * Extracts a string or integer value from dictionary using prioritized keys.
     *
     * @param array<int|string, mixed> $dictionary Dictionary to search.
     * @param string                   ...$keys    Priority-ordered keys to check.
     *
     * @phpstan-param array<int|string, mixed> $dictionary
     *
     * @return string|int|null String or integer value if found, null otherwise.
     */
    private function stringOrIntValue(array $dictionary, string ...$keys): string|int|null
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $dictionary)) {
                continue;
            }

            $value = $dictionary[$key];

            if (is_int($value)) {
                return $value;
            }

            if (is_float($value)) {
                $intValue = (int) $value;
                if ((float) $intValue === $value) {
                    return $intValue;
                }

                return (string) $value;
            }

            if (is_string($value)) {
                $trimmed = trim($value);
                if ($trimmed === '') {
                    continue;
                }

                if ($this->isIntegerString($trimmed)) {
                    return $this->stringWithinIntRange($trimmed) ? (int) $trimmed : $trimmed;
                }

                return $trimmed;
            }
        }

        return null;
    }

    /**
     * Extracts an identifier value from dictionary using prioritized keys.
     *
     * @param array<int|string, mixed> $dictionary Dictionary to search.
     * @param string                   ...$keys    Priority-ordered keys to check.
     *
     * @phpstan-param array<int|string, mixed> $dictionary
     *
     * @return string|int|null Identifier value if found, null otherwise.
     */
    private function identifierValue(array $dictionary, string ...$keys): string|int|null
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $dictionary)) {
                continue;
            }

            $value = $dictionary[$key];

            if (is_int($value)) {
                return $value;
            }

            if (is_float($value)) {
                $intValue = (int) $value;
                if ((float) $intValue === $value) {
                    return $intValue;
                }

                return (string) $value;
            }

            if (is_string($value)) {
                $trimmed = trim($value);

                return $trimmed !== '' ? $trimmed : null;
            }
        }

        return null;
    }

    private function isIntegerString(string $value): bool
    {
        return preg_match('/^-?\d+$/', $value) === 1;
    }

    private function stringWithinIntRange(string $value): bool
    {
        $negative = $value !== '' && $value[0] === '-';
        $digits   = $negative ? substr($value, 1) : $value;

        if ($digits === '') {
            return false;
        }

        $maxDigits = (string) PHP_INT_MAX;

        $digitLength = strlen($digits);
        $maxLength   = strlen($maxDigits);

        if ($digitLength < $maxLength) {
            return true;
        }

        if ($digitLength > $maxLength) {
            return false;
        }

        $comparison = strcmp($digits, $maxDigits);
        if ($comparison > 0) {
            return false;
        }

        if ($comparison < 0) {
            return true;
        }

        return !$negative;
    }

    /**
     * Extracts a string value from dictionary for a specific key.
     *
     * @param array<int|string, mixed> $dictionary Dictionary to search.
     * @param string                   $key        Key to look up.
     *
     * @phpstan-param array<int|string, mixed> $dictionary
     *
     * @return string|null String value if found, null otherwise.
     */
    private function stringValue(array $dictionary, string $key): ?string
    {
        if (!array_key_exists($key, $dictionary)) {
            return null;
        }

        $value = $dictionary[$key];
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * Extracts a float value from dictionary using prioritized keys.
     *
     * @param array<int|string, mixed> $dictionary Dictionary to search.
     * @param string                   ...$keys    Priority-ordered keys to check.
     *
     * @phpstan-param array<int|string, mixed> $dictionary
     *
     * @return float|null Float value if found, null otherwise.
     */
    private function floatValue(array $dictionary, string ...$keys): ?float
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $dictionary)) {
                continue;
            }

            $value = $dictionary[$key];
            if (is_float($value)) {
                return $value;
            }

            if (is_int($value) || is_numeric($value)) {
                return (float) $value;
            }
        }

        return null;
    }

    /**
     * Extracts an integer value from dictionary using prioritized keys.
     *
     * @param array<int|string, mixed> $dictionary Dictionary to search.
     * @param string                   ...$keys    Priority-ordered keys to check.
     *
     * @phpstan-param array<int|string, mixed> $dictionary
     *
     * @return int|null Integer value if found, null otherwise.
     */
    private function intValue(array $dictionary, string ...$keys): ?int
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $dictionary)) {
                continue;
            }

            $value = $dictionary[$key];
            if (is_int($value)) {
                return $value;
            }

            if (is_numeric($value)) {
                return (int) $value;
            }
        }

        return null;
    }

    /**
     * Extracts a list of float values from dictionary using prioritized keys.
     *
     * @param array<int|string, mixed> $dictionary Dictionary to search.
     * @param string                   ...$keys    Priority-ordered keys to check.
     *
     * @phpstan-param array<int|string, mixed> $dictionary
     *
     * @return list<float>|null List of float values if found, null otherwise.
     * @return list<float>|null
     */
    private function floatList(array $dictionary, string ...$keys): ?array
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $dictionary)) {
                continue;
            }

            $value = $dictionary[$key];
            if (is_float($value)) {
                return [$value];
            }

            if (is_int($value)) {
                return [(float) $value];
            }

            if (is_string($value) && is_numeric($value)) {
                return [(float) $value];
            }

            if (!is_array($value)) {
                continue;
            }

            if (!array_is_list($value) && array_key_exists('values', $value) && is_array($value['values'])) {
                $value = $value['values'];
            }

            if (!array_is_list($value)) {
                continue;
            }

            $result = [];
            foreach ($value as $entry) {
                if (is_float($entry)) {
                    $result[] = $entry;
                } elseif (is_int($entry) || is_numeric($entry)) {
                    $result[] = (float) $entry;
                }
            }

            if ($result !== []) {
                return $result;
            }
        }

        return null;
    }

    /**
     * Extracts focus distance range from dictionary.
     *
     * @param array<int|string, mixed> $dictionary Dictionary containing focus distance data.
     *
     * @phpstan-param array<int|string, mixed> $dictionary
     *
     * @return list<float>|null Focus distance range [near, far] or null if not found.
     * @return list<float>|null
     */
    private function focusDistanceRangeValue(array $dictionary): ?array
    {
        $range = $this->floatList($dictionary, 'FocusDistanceRange');
        if ($range !== null) {
            return $range;
        }

        $near = $this->floatValue(
            $dictionary,
            'FocusDistanceRangeNear',
            'FocusDistanceRangeMin',
            'FocusDistanceNear',
        );
        $far = $this->floatValue(
            $dictionary,
            'FocusDistanceRangeFar',
            'FocusDistanceRangeMax',
            'FocusDistanceFar',
        );

        $values = [];
        if ($near !== null) {
            $values[] = $near;
        }

        if ($far !== null) {
            $values[] = $far;
        }

        return $values !== [] ? $values : null;
    }

    /**
     * Extracts maker note version string from dictionary.
     *
     * @param array<int|string, mixed> $dictionary Dictionary to search.
     * @param string                   $key        Key to look up.
     *
     * @phpstan-param array<int|string, mixed> $dictionary
     *
     * @return string|null Version string if found, null otherwise.
     */
    private function makerNoteVersionValue(array $dictionary, string $key): ?string
    {
        if (!array_key_exists($key, $dictionary)) {
            return null;
        }

        $scalar = $this->stringOrNumericValue($dictionary, $key);
        if ($scalar !== null) {
            return $scalar;
        }

        $value = $dictionary[$key];
        if (!is_array($value)) {
            return null;
        }

        if (!array_is_list($value) && array_key_exists('values', $value) && is_array($value['values'])) {
            $value = $value['values'];
        }

        if (!array_is_list($value)) {
            return null;
        }

        $components = [];
        foreach ($value as $entry) {
            /** @var array<int|string, mixed>|bool|float|int|string|null $entry */
            if (is_int($entry)) {
                $components[] = (string) $entry;
                continue;
            }

            if (is_string($entry)) {
                $trimmed = trim($entry);
                if ($trimmed === '') {
                    continue;
                }

                if (!is_numeric($trimmed)) {
                    continue;
                }

                $components[] = (string) (int) $trimmed;
                continue;
            }

            if (is_float($entry)) {
                $components[] = (string) (int) $entry;
            }
        }

        if ($components === []) {
            return null;
        }

        return implode('.', $components);
    }

    /**
     * Extracts a string or numeric value from dictionary using prioritized keys.
     *
     * @param array<int|string, mixed> $dictionary Dictionary to search.
     * @param string                   ...$keys    Priority-ordered keys to check.
     *
     * @phpstan-param array<int|string, mixed> $dictionary
     *
     * @return string|null String representation of value if found, null otherwise.
     */
    private function stringOrNumericValue(array $dictionary, string ...$keys): ?string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $dictionary)) {
                continue;
            }

            /** @var array<int|string, mixed>|bool|float|int|string|null $candidate */
            $candidate = $dictionary[$key];
            if (is_string($candidate)) {
                $trimmed = trim($candidate);
                if ($trimmed !== '') {
                    return $trimmed;
                }
            }

            if (is_int($candidate) || is_float($candidate)) {
                return (string) $candidate;
            }
        }

        return null;
    }

    /**
     * Extracts an enumerated string value from dictionary using a mapping table.
     *
     * @param array<int|string, mixed> $dictionary Dictionary to search.
     * @param array<int, string>       $map        Mapping from numeric codes to string labels.
     * @param string                   ...$keys    Priority-ordered keys to check.
     *
     * @return string|null Enumerated string value if found, null otherwise.
     */
    private function enumeratedStringValue(array $dictionary, array $map, string ...$keys): ?string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $dictionary)) {
                continue;
            }

            /** @var array<int|string, mixed>|bool|float|int|string|null $candidate */
            $candidate = $dictionary[$key];
            if (is_string($candidate)) {
                $trimmed = trim($candidate);
                if ($trimmed === '') {
                    continue;
                }

                if (is_numeric($trimmed)) {
                    $code = (int) $trimmed;

                    return $map[$code] ?? $trimmed;
                }

                return $trimmed;
            }

            if (is_int($candidate)) {
                return $map[$candidate] ?? (string) $candidate;
            }

            if (is_float($candidate)) {
                $code = (int) $candidate;

                return $map[$code] ?? (string) $candidate;
            }
        }

        return null;
    }

    /**
     * Extracts boolean flags from dictionary.
     *
     * @param array<int|string, mixed> $dictionary Dictionary containing flag data.
     *
     * @phpstan-param array<int|string, mixed> $dictionary
     *
     * @return array<string, bool> Dictionary of flag names to boolean values.
     * @return array<string, bool>
     */
    private function extractFlags(array $dictionary): array
    {
        $flags = [];
        foreach (AppleMaps::FLAG_MAP as $makerKey => $normalized) {
            if (!array_key_exists($makerKey, $dictionary)) {
                continue;
            }

            /** @var array<int|string, mixed>|bool|float|int|string|null $candidate */
            $candidate = $dictionary[$makerKey];
            $bool      = $this->boolValue($candidate);
            if ($bool === null) {
                continue;
            }

            $flags[$normalized] = $bool;
        }

        foreach (AppleMaps::FLAG_MASK_MAP as $makerKey => $bitMap) {
            if (!array_key_exists($makerKey, $dictionary)) {
                continue;
            }

            /** @var array<int|string, mixed>|bool|float|int|string|null $candidate */
            $candidate     = $dictionary[$makerKey];
            $enabledBits   = $this->bitPositions($candidate);
            $enabledLookup = $enabledBits === null ? null : array_flip($enabledBits);

            foreach ($bitMap as $bitPosition => $normalized) {
                $hasExisting = array_key_exists($normalized, $flags);
                if (!$hasExisting) {
                    $flags[$normalized] = false;
                }

                if ($enabledLookup === null) {
                    continue;
                }

                if (!array_key_exists($bitPosition, $enabledLookup)) {
                    continue;
                }

                if (!$hasExisting) {
                    $flags[$normalized] = true;
                }
            }
        }

        return $flags;
    }

    /**
     * Normalises Apple bitfield metadata to a list of enabled bit positions.
     *
     * Apple encodes bitfields either as integral masks (decimal/hex strings included) or
     * as ordered collections enumerating the zero-based bit positions that are enabled.
     * Nested collections can appear under helper keys such as "values" or "Flags".
     *
     * @param string|int|float|bool|array<int|string, mixed>|null $value
     *
     * @return list<int>|null Zero-based bit positions detected in the value or null when the value does not encode a bit mask.
     */
    private function bitPositions(string|int|float|bool|array|null $value): ?array
    {
        if (is_int($value)) {
            return $this->bitPositionsFromMask($value);
        }

        if (is_float($value)) {
            return $this->bitPositionsFromMask((int) $value);
        }

        if (is_string($value)) {
            $normalized = trim($value);
            if ($normalized === '') {
                return null;
            }

            if (str_starts_with($normalized, '0x') || str_starts_with($normalized, '0X')) {
                $hex = substr($normalized, 2);
                if ($hex === '' || !ctype_xdigit($hex)) {
                    return null;
                }

                return $this->bitPositionsFromMask((int) hexdec($hex));
            }

            if (!is_numeric($normalized)) {
                return null;
            }

            return $this->bitPositionsFromMask((int) $normalized);
        }

        if (is_bool($value) || $value === null) {
            return null;
        }

        if ($value === []) {
            return [];
        }

        if (!array_is_list($value)) {
            foreach (['flags', 'Flags', 'value', 'Value', 'mask', 'Mask', 'bitPositions', 'BitPositions'] as $key) {
                if (array_key_exists($key, $value)) {
                    /** @var array<int|string, mixed>|bool|float|int|string|null $candidate */
                    $candidate = $value[$key];

                    return $this->bitPositions($candidate);
                }
            }

            if (!array_key_exists('values', $value)) {
                return null;
            }

            /** @var array<int|string, mixed>|bool|float|int|string|null $candidate */
            $candidate = $value['values'];

            return $this->bitPositions($candidate);
        }

        $positions = [];
        $hasEntry  = false;
        foreach ($value as $entry) {
            /** @var array<int|string, mixed>|bool|float|int|string|null $entry */
            if (is_int($entry) || is_float($entry) || (is_string($entry) && is_numeric($entry))) {
                $position = (int) $entry;
                if ($position >= 0) {
                    $positions[] = $position;
                }

                $hasEntry = true;
                continue;
            }

            $nested = $this->bitPositions($entry);
            if ($nested === null) {
                continue;
            }

            $hasEntry = true;

            foreach ($nested as $bit) {
                $positions[] = $bit;
            }
        }

        if (!$hasEntry) {
            return null;
        }

        if ($positions === []) {
            return [];
        }

        $positions = array_values(array_unique($positions, SORT_NUMERIC));
        sort($positions);

        return $positions;
    }

    /**
     * Converts an integer bit mask into a list of zero-based bit positions.
     *
     * @param int $mask Bit mask with enabled bits set to 1.
     *
     * @return list<int>
     */
    private function bitPositionsFromMask(int $mask): array
    {
        if ($mask <= 0) {
            return [];
        }

        $positions = [];
        $bitIndex  = 0;
        while ($mask !== 0) {
            if (($mask & 1) === 1) {
                $positions[] = $bitIndex;
            }

            $mask >>= 1;
            ++$bitIndex;
        }

        return $positions;
    }

    /**
     * Normalizes a value to boolean representation.
     *
     * @param string|int|float|bool|array<int|string, mixed>|null $value Raw value to normalize.
     *
     * @phpstan-param string|int|float|bool|null|array<int|string, mixed> $value
     *
     * @return bool|null Boolean value or null if invalid.
     */
    private function boolValue(string|int|float|bool|array|null $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value !== 0;
        }

        if (is_float($value)) {
            return $value !== 0.0;
        }

        if (is_string($value)) {
            $normalized = trim($value);
            if ($normalized === '') {
                return null;
            }

            if (in_array($normalized, ['1', 'true', 'TRUE'], true)) {
                return true;
            }

            if (in_array($normalized, ['0', 'false', 'FALSE'], true)) {
                return false;
            }
        }

        return null;
    }
}
