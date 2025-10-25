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
use MagicSunday\ImageMeta\MakerNotes\Apple\KeyedArchiveUnarchiver;

use function array_is_list;
use function array_key_exists;
use function ctype_xdigit;
use function hexdec;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function sha1;
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
     * Maps bit masks provided by Apple maker note dictionaries to normalised flags.
     *
     * @var array<string, array<int, string>>
     */
    private const array FLAG_MASK_MAP = [
        'SceneFlags' => [
            1 << 0 => 'nightMode',
            1 << 1 => 'longExposure',
        ],
        'ImageProcessingFlags' => [
            1 << 0 => 'hdrEnabled',
            1 << 1 => 'hdrAuto',
        ],
        'PhotosAppFeatureFlags' => [
            1 << 0 => 'livePhoto',
            1 << 1 => 'livePhotoAuto',
            1 << 2 => 'livePhotoEnabled',
            1 << 3 => 'livePhotoActive',
            1 << 4 => 'livePhotoLongExposure',
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

        if ($this->isKeyedArchive($decoded)) {
            try {
                $decoded = (new KeyedArchiveUnarchiver())->unarchive($decoded);
            } catch (ParseError) {
                return null;
            }
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            return null;
        }

        return $this->buildAppleMakerNotes($decoded);
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

            $mask = $this->maskValue($dictionary[$makerKey]);
            if ($mask === null) {
                continue;
            }

            foreach ($bitMap as $bit => $normalized) {
                if (($mask & $bit) === $bit && !array_key_exists($normalized, $flags)) {
                    $flags[$normalized] = true;
                }
            }
        }

        return $flags;
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

    /**
     * @param string|int|float|bool|array<int|string, mixed>|null $value
     *
     * @phpstan-param string|int|float|bool|null|array<int|string, mixed> $value
     */
    private function maskValue(string|int|float|bool|array|null $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) $value;
        }

        if (is_string($value)) {
            $normalized = trim($value);
            if ($normalized === '') {
                return null;
            }

            if (str_starts_with($normalized, '0x') || str_starts_with($normalized, '0X')) {
                $hex = substr($normalized, 2);
                if ($hex !== '' && ctype_xdigit($hex)) {
                    return (int) hexdec($hex);
                }

                return null;
            }

            if (is_numeric($normalized)) {
                return (int) $normalized;
            }

            return null;
        }

        if (is_bool($value) || $value === null) {
            return null;
        }

        if (!is_array($value)) {
            return null;
        }

        if ($value === []) {
            return null;
        }

        if (!array_is_list($value)) {
            foreach (['flags', 'Flags', 'value', 'Value', 'mask', 'Mask'] as $key) {
                if (array_key_exists($key, $value)) {
                    $mask = $this->maskValue($value[$key]);
                    if ($mask !== null) {
                        return $mask;
                    }
                }
            }

            if (!array_key_exists('values', $value)) {
                return null;
            }

            $values = $value['values'];
            if (!is_array($values)) {
                return $this->maskValue($values);
            }

            $value = $values;
        }

        $mask = 0;
        foreach ($value as $entry) {
            $part = $this->maskValue($entry);
            if ($part !== null) {
                $mask |= $part;
            }
        }

        return $mask !== 0 ? $mask : null;
    }
}
