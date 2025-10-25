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
use MagicSunday\ImageMeta\MakerNotes\Apple\BinaryPlistDecoder;

use function array_is_list;
use function array_key_exists;
use function array_unique;
use function array_values;
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
use function sort;
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
     * Maps maker note keys to normalised flag identifiers.
     *
     * @var array<string, string>
     */
    private const array FLAG_MAP = [
        'LivePhotoAuto'         => 'livePhotoAuto',
        'LivePhotoEnabled'      => 'livePhotoEnabled',
        'LivePhotoActive'       => 'livePhotoActive',
        'LivePhotoLongExposure' => 'livePhotoLongExposure',
        'LivePhoto'             => 'livePhoto',
        'HdrAuto'               => 'hdrAuto',
        'HdrEnabled'            => 'hdrEnabled',
        'NightMode'             => 'nightMode',
        'LongExposure'          => 'longExposure',
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
            0 => 'livePhoto',          // Bit 0 – Live Photo asset present.
            1 => 'livePhotoAuto',      // Bit 1 – Live Photo auto capture.
            2 => 'livePhotoEnabled',   // Bit 2 – Live Photo enabled by the user.
            3 => 'livePhotoActive',    // Bit 3 – Live Photo active during capture.
            4 => 'livePhotoLongExposure', // Bit 4 – Live Photo long exposure fused asset.
        ],
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
        try {
            $decoded = (new BinaryPlistDecoder())->decode($raw);
        } catch (ParseError) {
            return null;
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            return null;
        }

        return $this->buildAppleMakerNotes($decoded);
    }

    /**
     * @param array<int|string, array<int|string, mixed>|bool|float|int|string|null> $dictionary
     *
     * @phpstan-param array<int|string, array<int|string, mixed>|bool|float|int|string|null> $dictionary
     */
    private function buildAppleMakerNotes(array $dictionary): ?AppleMakerNotes
    {
        $contentIdentifier    = $this->stringValue($dictionary, 'ContentIdentifier');
        $cameraType           = $this->stringValue($dictionary, 'CameraType');
        $hdrHeadroom          = $this->floatValue($dictionary, 'HdrHeadroom', 'HDRHeadroom');
        $hdrGain              = $this->floatList($dictionary, 'HdrGain', 'HDRGain');
        $snr                  = $this->floatValue($dictionary, 'SNRSetting', 'SNR');
        $focusPosition        = $this->floatValue($dictionary, 'FocusPosition');
        $livePhotoIndex       = $this->intValue($dictionary, 'LivePhotoVideoIndex');
        $colorTemperature     = $this->intValue($dictionary, 'ColorTemperature');
        $semanticStylePreset  = $this->stringValue($dictionary, 'SemanticStylePreset');
        $semanticStyleWarmth  = $this->floatValue($dictionary, 'SemanticStyleWarmth');
        $semanticStyleTone    = $this->floatValue($dictionary, 'SemanticStyleTone');
        $semanticStyleCompact = $this->semanticStyleFromCollection($dictionary);
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
        $flags              = $this->extractFlags($dictionary);

        if (
            $contentIdentifier === null
            && $cameraType === null
            && $hdrHeadroom === null
            && $hdrGain === null
            && $snr === null
            && $focusPosition === null
            && $livePhotoIndex === null
            && $colorTemperature === null
            && $semanticStylePreset === null
            && $semanticStyleWarmth === null
            && $semanticStyleTone === null
            && $flags === []
            && $accelerationVector === null
        ) {
            return null;
        }

        return new AppleMakerNotes(
            $contentIdentifier,
            $cameraType,
            $hdrHeadroom,
            $hdrGain,
            $snr,
            $focusPosition,
            $livePhotoIndex,
            $colorTemperature,
            $semanticStylePreset,
            $semanticStyleWarmth,
            $semanticStyleTone,
            $flags,
            $accelerationVector,
        );
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
     * Extracts semantic style values from Apple's compact semantic style array.
     *
     * Apple stores semantic style metadata as an ordered collection where index 0 / `_0`
     * contains the preset name, index 1 / `_1` the warmth adjustment, and index 2 / `_2` the
     * tone adjustment. Index `_3` is currently unused by the curated metadata object.
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

        $resolve = static function (array $entries, int $index): string|int|float|bool|array|null {
            $intKey      = $index;
            $stringKey   = (string) $index;
            $underscored = '_' . $index;

            if (array_key_exists($intKey, $entries)) {
                return $entries[$intKey];
            }

            if (array_key_exists($stringKey, $entries)) {
                return $entries[$stringKey];
            }

            if (array_key_exists($underscored, $entries)) {
                return $entries[$underscored];
            }

            return null;
        };

        $presetRaw = $resolve($semantic, 0);
        $warmthRaw = $resolve($semantic, 1);
        $toneRaw   = $resolve($semantic, 2);

        $preset = null;
        if (is_string($presetRaw)) {
            $trimmed = trim($presetRaw);
            if ($trimmed !== '') {
                $preset = $trimmed;
            }
        }

        $warmth = null;
        if (is_float($warmthRaw)) {
            $warmth = $warmthRaw;
        } elseif (is_int($warmthRaw) || is_numeric($warmthRaw)) {
            $warmth = (float) $warmthRaw;
        }

        $tone = null;
        if (is_float($toneRaw)) {
            $tone = $toneRaw;
        } elseif (is_int($toneRaw) || is_numeric($toneRaw)) {
            $tone = (float) $toneRaw;
        }

        if ($preset === null && $warmth === null && $tone === null) {
            return null;
        }

        return [$preset, $warmth, $tone];
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
        foreach (self::FLAG_MAP as $makerKey => $normalized) {
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

            $enabledBits = $this->bitPositions($dictionary[$makerKey]);
            if ($enabledBits === []) {
                continue;
            }

            foreach ($bitMap as $bitPosition => $normalized) {
                if (in_array($bitPosition, $enabledBits, true) && !array_key_exists($normalized, $flags)) {
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
     * @return list<int> Zero-based bit positions detected in the value.
     */
    private function bitPositions(string|int|float|bool|array|null $value): array
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
                return [];
            }

            if (str_starts_with($normalized, '0x') || str_starts_with($normalized, '0X')) {
                $hex = substr($normalized, 2);
                if ($hex === '' || !ctype_xdigit($hex)) {
                    return [];
                }

                return $this->bitPositionsFromMask((int) hexdec($hex));
            }

            if (!is_numeric($normalized)) {
                return [];
            }

            return $this->bitPositionsFromMask((int) $normalized);
        }

        if (is_bool($value) || $value === null) {
            return [];
        }

        if (!is_array($value) || $value === []) {
            return [];
        }

        if (!array_is_list($value)) {
            foreach (['flags', 'Flags', 'value', 'Value', 'mask', 'Mask', 'bitPositions', 'BitPositions'] as $key) {
                if (array_key_exists($key, $value)) {
                    return $this->bitPositions($value[$key]);
                }
            }

            if (!array_key_exists('values', $value)) {
                return [];
            }

            return $this->bitPositions($value['values']);
        }

        $positions = [];
        foreach ($value as $entry) {
            if (is_int($entry) || is_float($entry) || (is_string($entry) && is_numeric($entry))) {
                $position = (int) $entry;
                if ($position >= 0) {
                    $positions[] = $position;
                }

                continue;
            }

            $nested = $this->bitPositions($entry);
            if ($nested !== []) {
                foreach ($nested as $bit) {
                    $positions[] = $bit;
                }
            }
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
