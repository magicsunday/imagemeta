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
use MagicSunday\ImageMeta\MakerNotes\AppleMetadata;
use MagicSunday\ImageMeta\MakerNotes\Apple\BinaryPlistDecoder;
use MagicSunday\ImageMeta\MakerNotes\Apple\KeyedArchiveUnarchiver;
use MagicSunday\ImageMeta\Value\RunTime;

use function array_is_list;
use function array_key_exists;
use function array_flip;
use function array_unique;
use function array_values;
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
use function sha1;
use function str_contains;
use function strtolower;
use function sort;
use function preg_match;
use function str_starts_with;
use function strlen;
use function substr;
use function trim;

/**
 * Decoder that extracts structured metadata from Apple maker note payloads.
 */
final class AppleDecoder implements MakerNotesDecoderInterface
{
    /**
     * Maps known camera type codes to descriptive labels.
     *
     * @var array<int, string>
     */
    private const array CAMERA_TYPE_MAP = [
        0 => 'Back Wide Angle',
        1 => 'Back Normal',
        6 => 'Front',
    ];

    /**
     * Maps Apple bitfield sources (indexed by zero-based bit position) to normalised flags.
     *
     * @var array<string, array<int, string>>
     */
    private const array FLAG_MASK_MAP = [
        'SceneFlags' => [
            0 => 'nightMode',          // Bit 0 – night mode capture.
            1 => 'longExposure',       // Bit 1 – long exposure tripod/night capture.
        ],
        'ImageProcessingFlags' => [
            0 => 'hdrEnabled',         // Bit 0 – HDR rendering enabled.
            1 => 'hdrAuto',            // Bit 1 – HDR auto detection engaged.
        ],
        'PhotosAppFeatureFlags' => [
            0 => 'personInPhoto',      // Bit 0 – Person detected in the asset.
            1 => 'petInPhoto',         // Bit 1 – Pet detected in the asset.
        ],
    ];

    /**
     * Maker note keys that encode the Live Photo still frame index.
     *
     * @var array<int, string>
     */
    private const array LIVE_PHOTO_INDEX_KEYS = [
        'LivePhotoVideoIndex',
        'LivePhotoMovieIndex',
    ];

    /**
     * Creates a metadata value object describing the Apple maker note payload.
     *
     * @param string      $raw   Raw maker note data stream.
     * @param string      $make  Reported camera make string.
     * @param string|null $model Optional camera model identifier.
     */
    public function decode(string $raw, string $make, ?string $model): MakerNotesMetadata
    {
        $appleData = $this->parseAppleData($raw);

        return new MakerNotesMetadata(
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

        $decoded = $this->resolveKeyedArchiveDictionary($decoded);
        if ($decoded === null || !is_array($decoded) || array_is_list($decoded)) {
            return null;
        }

        return $this->buildAppleMakerNotes($decoded);
    }

    /**
     * Attempts to decode the supplied payload as binary property list.
     *
     * @param string $raw Raw maker note data stream.
     *
     * @return array<int|string, array<int|string, mixed>|bool|float|int|string|null>|bool|float|int|string|null|null
     */
    private function decodeBinaryPropertyList(string $raw): array|string|int|float|bool|null
    {
        try {
            return (new BinaryPlistDecoder())->decode($raw);
        } catch (ParseError) {
            return null;
        }
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
     * @return array<int|string, array<int|string, mixed>|bool|float|int|string|null>|null
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
     * @return array<int|string, array<int|string, mixed>|bool|float|int|string|null>
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

            $value = $this->parseValue($raw, $offset, $length);
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
     * @return list<array|bool|float|int|string|null>
     */
    private function parseArray(string $raw, int &$offset, int $length): array
    {
        if ($raw[$offset] !== '(') {
            throw new ParseError('Expected array opening parenthesis.');
        }

        ++$offset;
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
                $char === ';'
                || $char === ','
                || $char === ')'
                || $char === '}'
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
     * @param array<int|string, array<int|string, mixed>|bool|float|int|string|null> $dictionary
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

        if (!is_array($top)) {
            return false;
        }

        return $this->containsUidReference($top);
    }

    /**
     * @param array<int|string, array<int|string, mixed>|bool|float|int|string|null> $value
     */
    private function containsUidReference(array $value): bool
    {
        if (array_key_exists('CF$UID', $value)) {
            return true;
        }

        foreach ($value as $entry) {
            if (is_array($entry) && $this->containsUidReference($entry)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int|string, array<int|string, mixed>|bool|float|int|string|null> $dictionary
     *
     * @return array<int|string, array<int|string, mixed>|bool|float|int|string|null>|null
     */
    private function resolveKeyedArchiveDictionary(array $dictionary): ?array
    {
        if ($this->isKeyedArchive($dictionary)) {
            try {
                return (new KeyedArchiveUnarchiver())->unarchive($dictionary);
            } catch (ParseError) {
                return null;
            }
        }

        $normalised = $this->normaliseKeyedArchive($dictionary);
        if ($normalised === null) {
            return $dictionary;
        }

        try {
            return (new KeyedArchiveUnarchiver())->unarchive($normalised);
        } catch (ParseError) {
            return null;
        }
    }

    /**
     * @param array<int|string, array<int|string, mixed>|bool|float|int|string|null> $dictionary
     *
     * @return array<int|string, array<int|string, mixed>|bool|float|int|string|null>|null
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

        $normalised            = $dictionary;
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
     * @param array<int|string, array<int|string, mixed>|bool|float|int|string|null> $dictionary
     */
    private function firstExistingKey(array $dictionary, string ...$keys): ?string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $dictionary)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * @param array<int|string, array<int|string, mixed>|bool|float|int|string|null> $dictionary
     *
     * @phpstan-param array<int|string, array<int|string, mixed>|bool|float|int|string|null> $dictionary
     */
    private function buildAppleMakerNotes(array $dictionary): ?AppleMakerNotes
    {
        $semanticStyleCompact = null;
        if (
            !array_key_exists('SemanticStylePreset', $dictionary)
            && !array_key_exists('SemanticStyleWarmth', $dictionary)
            && !array_key_exists('SemanticStyleTone', $dictionary)
        ) {
            $semanticStyleCompact = $this->semanticStyleFromCollection($dictionary);
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

        $contentIdentifier    = $this->stringValue($dictionary, 'ContentIdentifier');
        $cameraTypeCode       = $this->intValue($dictionary, 'CameraType');
        if ($cameraTypeCode !== null) {
            $cameraType = self::CAMERA_TYPE_MAP[$cameraTypeCode] ?? $cameraTypeCode;
        } else {
            $cameraType = $this->stringValue($dictionary, 'CameraType');
        }
        $hdrHeadroom          = $this->floatValue($dictionary, 'HdrHeadroom', 'HDRHeadroom');
        $hdrGain              = $this->floatList($dictionary, 'HdrGain', 'HDRGain');
        $snr                  = $this->floatValue($dictionary, 'SNRSetting', 'SNR');
        $aeStable             = $this->boolDictionaryValue($dictionary, 'AEStable');
        $aeTarget             = $this->rationalFloatValue($dictionary, 'AETarget');
        $aeAverage            = $this->rationalFloatValue($dictionary, 'AEAverage');
        $afStable             = $this->boolDictionaryValue($dictionary, 'AFStable');
        $afPerformance        = $this->rationalFloatValue($dictionary, 'AFPerformance');
        $signalToNoiseRatioType = $this->stringOrIntValue($dictionary, 'SignalToNoiseRatioType');
        $luminanceNoiseAmplitude = $this->rationalFloatValue($dictionary, 'LuminanceNoiseAmplitude');
        $focusPosition        = $this->floatValue($dictionary, 'FocusPosition');
        $runTime              = $this->runTimeValue($dictionary, 'RunTime');
        $livePhotoIndex       = $this->intValue($dictionary, ...self::LIVE_PHOTO_INDEX_KEYS);
        $livePhotoTime        = null;
        if ($livePhotoIndex !== null && $runTime instanceof RunTime) {
            $timescale = $runTime->timescale;
            if ($timescale !== null && $timescale > 0) {
                $livePhotoTime = $livePhotoIndex / $timescale;
            }
        }
        $colorTemperature     = $this->intValue($dictionary, 'ColorTemperature');
        $semanticStylePreset  = $this->stringValue($dictionary, 'SemanticStylePreset');
        $semanticStyleWarmth  = $this->floatValue($dictionary, 'SemanticStyleWarmth');
        $semanticStyleTone    = $this->floatValue($dictionary, 'SemanticStyleTone');
        $semanticStyleCompact ??= $this->semanticStyleFromCollection($dictionary);
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
        $accelerationVector = $this->floatList($dictionary, 'AccelerationVector');
        $flags                   = $this->extractFlags($dictionary);
        $imageCaptureRequestId   = $this->identifierValue($dictionary, 'ImageCaptureRequestID');
        $qualityHint             = $this->stringOrNumericValue($dictionary, 'QualityHint');
        $colorCorrectionMatrix   = $this->floatList($dictionary, 'ColorCorrectionMatrix');

        $makerNoteVersion   = $this->makerNoteVersionValue($dictionary, 'MakerNoteVersion');
        $hdrImageType       = $this->enumeratedStringValue($dictionary, AppleMetadata::HDR_IMAGE_TYPES, 'HDRImageType', 'HdrImageType');
        $burstUuid          = $this->stringValue($dictionary, 'BurstUUID');
        $focusDistanceRange = $this->focusDistanceRangeValue($dictionary);
        $oisMode            = $this->stringOrNumericValue($dictionary, 'OISMode');
        $imageCaptureType   = $this->enumeratedStringValue($dictionary, AppleMetadata::IMAGE_CAPTURE_TYPES, 'ImageCaptureType');
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
            && $runTime === null
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
     * @param array<int|string, array<int|string, mixed>|bool|float|int|string|null> $dictionary
     *
     * @phpstan-param array<int|string, array<int|string, mixed>|bool|float|int|string|null> $dictionary
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
     * @param array<int|string, array<int|string, mixed>|bool|float|int|string|null> $dictionary
     *
     * @phpstan-param array<int|string, array<int|string, mixed>|bool|float|int|string|null> $dictionary
     */
    private function boolDictionaryValue(array $dictionary, string ...$keys): ?bool
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $dictionary)) {
                continue;
            }

            $value = $this->boolValue($dictionary[$key]);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<int|string, array<int|string, mixed>|bool|float|int|string|null> $dictionary
     *
     * @phpstan-param array<int|string, array<int|string, mixed>|bool|float|int|string|null> $dictionary
     */
    private function rationalFloatValue(array $dictionary, string ...$keys): ?float
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $dictionary)) {
                continue;
            }

            $float = $this->normaliseRationalFloat($dictionary[$key]);
            if ($float !== null) {
                return $float;
            }
        }

        return null;
    }

    /**
     * @param string|int|float|array<int|string, mixed>|null $value
     */
    private function normaliseRationalFloat(string|int|float|array|null $value): ?float
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
                $numerator   = trim($numeratorRaw);
                $denominator = trim($denominatorRaw);

                if ($numerator === '' || $denominator === '' || !is_numeric($numerator) || !is_numeric($denominator)) {
                    return null;
                }

                $denominatorFloat = (float) $denominator;
                if ($denominatorFloat === 0.0) {
                    return null;
                }

                return (float) $numerator / $denominatorFloat;
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
                $nested = $this->normaliseRationalFloat($value[$key]);
                if ($nested !== null) {
                    return $nested;
                }
            }
        }

        if (array_key_exists('values', $value) && is_array($value['values'])) {
            $nested = $this->normaliseRationalFloat($value['values']);
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
            $num = $this->numericScalarValue($value[0]);
            $den = $this->numericScalarValue($value[1]);
            if ($num !== null && $den !== null && $den !== 0.0) {
                return $num / $den;
            }
        }

        foreach ($value as $entry) {
            $float = $this->normaliseRationalFloat($entry);
            if ($float !== null) {
                return $float;
            }
        }

        return null;
    }

    /**
     * @param array<int|string, mixed> $value
     *
     * @phpstan-param array<int|string, mixed> $value
     */
    private function numericComponentFromArray(array $value, string ...$keys): ?float
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $value)) {
                continue;
            }

            $numeric = $this->numericScalarValue($value[$key]);
            if ($numeric !== null) {
                return $numeric;
            }
        }

        return null;
    }

    /**
     * @param string|int|float|array<int|string, mixed>|null $value
     */
    private function numericScalarValue(string|int|float|array|null $value): ?float
    {
        if (is_float($value)) {
            return $value;
        }

        if (is_int($value)) {
            return (float) $value;
        }

        if (is_string($value)) {
            $normalized = trim($value);
            if ($normalized === '' || !is_numeric($normalized)) {
                return null;
            }

            return (float) $normalized;
        }

        if (is_array($value)) {
            return $this->normaliseRationalFloat($value);
        }

        return null;
    }

    /**
     * @param array<int|string, array<int|string, mixed>|bool|float|int|string|null> $dictionary
     *
     * @phpstan-param array<int|string, array<int|string, mixed>|bool|float|int|string|null> $dictionary
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

                if (is_numeric($trimmed)) {
                    return $trimmed;
                }

                return $trimmed;
            }
        }

        return null;
    }

    /**
     * @param array<int|string, array<int|string, mixed>|bool|float|int|string|null> $dictionary
     *
     * @phpstan-param array<int|string, array<int|string, mixed>|bool|float|int|string|null> $dictionary
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
     * @param array<int|string, array<int|string, mixed>|bool|float|int|string|null> $dictionary
     *
     * @phpstan-param array<int|string, array<int|string, mixed>|bool|float|int|string|null> $dictionary
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
     * @param array<int|string, array<int|string, mixed>|bool|float|int|string|null> $dictionary
     *
     * @phpstan-param array<int|string, array<int|string, mixed>|bool|float|int|string|null> $dictionary
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
     * @param array<int|string, array<int|string, mixed>|bool|float|int|string|null> $dictionary
     *
     * @phpstan-param array<int|string, array<int|string, mixed>|bool|float|int|string|null> $dictionary
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
     * @param array<int|string, array<int|string, mixed>|bool|float|int|string|null> $dictionary
     *
     * @phpstan-param array<int|string, array<int|string, mixed>|bool|float|int|string|null> $dictionary
     *
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
     * @param array<int|string, array<int|string, mixed>|bool|float|int|string|null> $dictionary
     *
     * @phpstan-param array<int|string, array<int|string, mixed>|bool|float|int|string|null> $dictionary
     *
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
     * @param array<int|string, array<int|string, mixed>|bool|float|int|string|null> $dictionary
     *
     * @phpstan-param array<int|string, array<int|string, mixed>|bool|float|int|string|null> $dictionary
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
            if (is_int($entry)) {
                $components[] = (string) $entry;
                continue;
            }

            if (is_string($entry)) {
                $trimmed = trim($entry);
                if ($trimmed === '' || !is_numeric($trimmed)) {
                    continue;
                }

                $components[] = (string) (int) $trimmed;
                continue;
            }

            if (is_float($entry) || is_numeric($entry)) {
                $components[] = (string) (int) $entry;
            }
        }

        if ($components === []) {
            return null;
        }

        return implode('.', $components);
    }

    /**
     * @param array<int|string, array<int|string, mixed>|bool|float|int|string|null> $dictionary
     *
     * @phpstan-param array<int|string, array<int|string, mixed>|bool|float|int|string|null> $dictionary
     */
    private function stringOrNumericValue(array $dictionary, string ...$keys): ?string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $dictionary)) {
                continue;
            }

            $value = $dictionary[$key];
            if (is_string($value)) {
                $trimmed = trim($value);
                if ($trimmed !== '') {
                    return $trimmed;
                }
            }

            if (is_int($value) || is_float($value) || is_numeric($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * @param array<int|string, array<int|string, mixed>|bool|float|int|string|null> $dictionary
     * @param array<int, string>                                                     $map
     */
    private function enumeratedStringValue(array $dictionary, array $map, string ...$keys): ?string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $dictionary)) {
                continue;
            }

            $value = $dictionary[$key];
            if (is_string($value)) {
                $trimmed = trim($value);
                if ($trimmed === '') {
                    continue;
                }

                if (is_numeric($trimmed)) {
                    $code = (int) $trimmed;

                    return $map[$code] ?? $trimmed;
                }

                return $trimmed;
            }

            if (is_int($value)) {
                return $map[$value] ?? (string) $value;
            }

            if (is_float($value) || is_numeric($value)) {
                $code = (int) $value;

                return $map[$code] ?? (string) $value;
            }
        }

        return null;
    }

    /**
     * Extracts semantic style values from Apple's compact semantic style array.
     *
     * Apple stores semantic style metadata as an ordered collection where index 0 / `_0`
     * contains the preset name. Legacy payloads store the warmth adjustment at index 1 / `_1`
     * and tone at index 2 / `_2`. Modern payloads use index 2 / `_2` for warmth and index 3 / `_3`
     * for tone.
     *
     * @param array<int|string, array<int|string, mixed>|bool|float|int|string|null> $dictionary
     *
     * @phpstan-param array<int|string, array<int|string, mixed>|bool|float|int|string|null> $dictionary
     *
     * @return array{0:?string,1:?float,2:?float}|null
     */
    private function semanticStyleFromCollection(array $dictionary): ?array
    {
        if (!array_key_exists('SemanticStyle', $dictionary)) {
            return null;
        }

        $value = $dictionary['SemanticStyle'];
        if (!is_array($value)) {
            return null;
        }

        /** @var array<int|string, mixed> $semantic */
        $semantic = $value;

        $entries = $this->semanticStyleEntries($semantic);
        if ($entries === null) {
            return null;
        }

        $presetRaw      = $this->semanticStyleEntry($entries, 0);
        $legacyWarmth   = $this->semanticStyleEntry($entries, 1);
        $modernWarmth   = $legacyWarmth === null ? $this->semanticStyleEntry($entries, 2) : null;
        $warmthRaw      = $legacyWarmth ?? $modernWarmth;
        $toneRawLegacy  = $legacyWarmth !== null ? $this->semanticStyleEntry($entries, 2) : null;
        $toneRawModern  = $legacyWarmth === null ? $this->semanticStyleEntry($entries, 3, 2) : null;
        $toneRaw        = $toneRawLegacy ?? $toneRawModern;

        $preset = $this->semanticStylePreset($presetRaw);
        $warmth = $this->semanticStyleFloat($warmthRaw);
        $tone   = $this->semanticStyleFloat($toneRaw);

        if ($preset === null && $warmth === null && $tone === null) {
            return null;
        }

        return [$preset, $warmth, $tone];
    }

    /**
     * @param array<int|string, mixed> $semantic
     *
     * @return array<int|string, string|int|float|bool|null>|null
     */
    private function semanticStyleEntries(array $semantic): ?array
    {
        if (!array_is_list($semantic)) {
            foreach (['values', 'Values'] as $key) {
                if (array_key_exists($key, $semantic) && is_array($semantic[$key])) {
                    /** @var array<int|string, mixed> $values */
                    $values = $semantic[$key];

                    return $this->semanticStyleEntries($values);
                }
            }
        }

        return $semantic;
    }

    /**
     * @param array<int|string, string|int|float|bool|null> $entries
     */
    private function semanticStyleEntry(array $entries, int ...$indexes): string|int|float|bool|null
    {
        foreach ($indexes as $index) {
            $candidates = [$index, (string) $index, '_' . $index];
            foreach ($candidates as $key) {
                if (!array_key_exists($key, $entries)) {
                    continue;
                }

                $value = $entries[$key];
                if (is_array($value)) {
                    foreach (['value', 'Value'] as $innerKey) {
                        if (array_key_exists($innerKey, $value)) {
                            $inner = $value[$innerKey];
                            if (!is_array($inner)) {
                                $value = $inner;
                            }

                            break;
                        }
                    }

                    if (is_array($value)) {
                        continue;
                    }
                }

                if (is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
                    return $value;
                }
            }
        }

        return null;
    }

    private function semanticStylePreset(string|int|float|bool|null $value): ?string
    {
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return null;
    }

    private function semanticStyleFloat(string|int|float|bool|null $value): ?float
    {
        if (is_float($value)) {
            return $value;
        }

        if (is_int($value) || is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }

    /**
     * @param array<int|string, array<int|string, mixed>|bool|float|int|string|null> $dictionary
     *
     * @phpstan-param array<int|string, array<int|string, mixed>|bool|float|int|string|null> $dictionary
     *
     * @return array<string, bool>
     */
    private function extractFlags(array $dictionary): array
    {
        $flags = [];
        foreach (AppleMetadata::FLAG_MAP as $makerKey => $normalized) {
            if (!array_key_exists($makerKey, $dictionary)) {
                continue;
            }

            $value = $dictionary[$makerKey];
            $bool  = $this->boolValue($value);
            if ($bool === null) {
                continue;
            }

            $flags[$normalized] = $bool;
        }

        foreach (self::FLAG_MASK_MAP as $makerKey => $bitMap) {
            if (!array_key_exists($makerKey, $dictionary)) {
                continue;
            }

            $enabledBits   = $this->bitPositions($dictionary[$makerKey]);
            $enabledLookup = $enabledBits === null ? null : array_flip($enabledBits);

            foreach ($bitMap as $bitPosition => $normalized) {
                $hasExisting = array_key_exists($normalized, $flags);
                if (!$hasExisting) {
                    $flags[$normalized] = false;
                }

                if ($enabledLookup === null || !array_key_exists($bitPosition, $enabledLookup)) {
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

        if (!is_array($value)) {
            return null;
        }

        if ($value === []) {
            return [];
        }

        if (!array_is_list($value)) {
            foreach (['flags', 'Flags', 'value', 'Value', 'mask', 'Mask', 'bitPositions', 'BitPositions'] as $key) {
                if (array_key_exists($key, $value)) {
                    return $this->bitPositions($value[$key]);
                }
            }

            if (!array_key_exists('values', $value)) {
                return null;
            }

            return $this->bitPositions($value['values']);
        }

        $positions = [];
        $hasEntry  = false;
        foreach ($value as $entry) {
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
            $bitIndex++;
        }

        return $positions;
    }

    /**
     * @param string|int|float|bool|array<int|string, mixed>|null $value
     *
     * @phpstan-param string|int|float|bool|null|array<int|string, mixed> $value
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
