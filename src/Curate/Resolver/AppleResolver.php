<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Curate\Resolver;

use MagicSunday\ImageMeta\MakerNotes\Apple\AppleMakerNotes;
use MagicSunday\ImageMeta\Model\QuickTimeMeta;

use function array_is_list;
use function array_key_exists;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function preg_split;
use function trim;

/**
 * Resolves Apple specific metadata from QuickTime containers.
 */
final readonly class AppleResolver
{
    /**
     * @var array<int, string>
     */
    private const array HDR_IMAGE_TYPE_MAP = [
        0 => 'Standard',
        1 => 'HDR',
        2 => 'HDR2',
        3 => 'HDR3',
    ];

    /**
     * @var array<int, string>
     */
    private const array IMAGE_CAPTURE_TYPE_MAP = [
        0  => 'Unknown',
        1  => 'ProRAW',
        2  => 'Portrait',
        3  => 'Live Photo',
        4  => 'Live Photo Long Exposure',
        5  => 'Burst',
        6  => 'Night Mode',
        7  => 'Night Mode Portrait',
        10 => 'Photo',
        11 => 'Manual Focus',
        12 => 'Scene',
    ];

    /**
     * @var array<string, string>
     */
    private const array FLAG_KEYS = [
        'LivePhotoAuto'         => 'livePhotoAuto',
        'LivePhotoEnabled'      => 'livePhotoEnabled',
        'LivePhotoActive'       => 'livePhotoActive',
        'LivePhotoLongExposure' => 'livePhotoLongExposure',
        'LivePhoto'             => 'livePhoto',
        'HdrAuto'               => 'hdrAuto',
        'HdrEnabled'            => 'hdrEnabled',
        'NightMode'             => 'nightMode',
        'LongExposure'          => 'longExposure',
        'PersonInPhoto'         => 'personInPhoto',
        'PetInPhoto'            => 'petInPhoto',
    ];

    /**
     * Builds an Apple maker note aggregate from available QuickTime metadata.
     */
    public function resolve(?QuickTimeMeta $quickTimeMeta): ?AppleMakerNotes
    {
        if (!$quickTimeMeta instanceof QuickTimeMeta) {
            return null;
        }

        $identifier = $quickTimeMeta->contentIdentifier();
        $resolver   = new QuickTimeResolver($quickTimeMeta);

        $cameraTypeString = $resolver->string('CameraType');
        $cameraType       = $cameraTypeString ?? $resolver->int('CameraType');
        $hdrHeadroom      = $resolver->float('HdrHeadroom') ?? $resolver->float('HDRHeadroom');
        $hdrGain          = $this->floatList($resolver, 'HdrGain', 'HDRGain');
        $snr              = $resolver->float('SNRSetting') ?? $resolver->float('SNR');
        $focusPosition    = $resolver->float('FocusPosition');
        $livePhotoIndex   = $this->int($resolver, 'LivePhotoVideoIndex', 'LivePhotoMovieIndex');
        $colorTemperature = $this->int($resolver, 'ColorTemperature');
        $semanticPreset   = $resolver->string('SemanticStylePreset');
        $semanticWarmth   = $resolver->float('SemanticStyleWarmth');
        $semanticTone     = $resolver->float('SemanticStyleTone');

        $semanticComposite = $this->semanticStyleFromComposite($quickTimeMeta);
        if ($semanticComposite !== null) {
            [$compositePreset, $compositeWarmth, $compositeTone] = $semanticComposite;

            if ($semanticPreset === null && $compositePreset !== null) {
                $semanticPreset = $compositePreset;
            }

            if ($semanticWarmth === null && $compositeWarmth !== null) {
                $semanticWarmth = $compositeWarmth;
            }

            if ($semanticTone === null && $compositeTone !== null) {
                $semanticTone = $compositeTone;
            }
        }

        $accelerationVector = $this->floatList($resolver, 'AccelerationVector');
        $flags              = $this->flags($resolver);

        $makerNoteVersion   = $resolver->string('MakerNoteVersion');
        $hdrImageType       = $this->enumeratedValue($resolver, self::HDR_IMAGE_TYPE_MAP, 'HDRImageType', 'HdrImageType');
        $burstUuid          = $resolver->string('BurstUUID');
        $focusDistanceRange = $this->focusDistanceRange($resolver);
        $oisMode            = $this->stringOrNumeric($resolver, 'OISMode');
        $imageCaptureType   = $this->enumeratedValue($resolver, self::IMAGE_CAPTURE_TYPE_MAP, 'ImageCaptureType');
        $imageUniqueId      = $resolver->string('ImageUniqueID');
        $photoIdentifier    = $resolver->string('PhotoIdentifier');
        $afMeasuredDepth    = $resolver->float('AFMeasuredDepth');
        $afConfidence       = $resolver->float('AFConfidence');

        if (
            $identifier === null
            && $cameraType === null
            && $hdrHeadroom === null
            && $hdrGain === null
            && $snr === null
            && $focusPosition === null
            && $livePhotoIndex === null
            && $colorTemperature === null
            && $semanticPreset === null
            && $semanticWarmth === null
            && $semanticTone === null
            && $accelerationVector === null
            && $flags === []
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
            contentIdentifier: $identifier,
            cameraType: $cameraType,
            hdrHeadroom: $hdrHeadroom,
            hdrGain: $hdrGain,
            snr: $snr,
            aeStable: null,
            aeTarget: null,
            aeAverage: null,
            afStable: null,
            afPerformance: null,
            signalToNoiseRatioType: null,
            luminanceNoiseAmplitude: null,
            focusPosition: $focusPosition,
            livePhotoIndex: $livePhotoIndex,
            colorTemperature: $colorTemperature,
            semanticStylePreset: $semanticPreset,
            semanticStyleWarmth: $semanticWarmth,
            semanticStyleTone: $semanticTone,
            flags: $flags,
            accelerationVector: $accelerationVector,
            makerNoteVersion: $makerNoteVersion,
            hdrImageType: $hdrImageType,
            burstUuid: $burstUuid,
            focusDistanceRange: $focusDistanceRange,
            oisMode: $oisMode,
            imageCaptureType: $imageCaptureType,
            imageUniqueId: $imageUniqueId,
            photoIdentifier: $photoIdentifier,
            afMeasuredDepth: $afMeasuredDepth,
            afConfidence: $afConfidence,
        );
    }

    /**
     * @return list<float>|null
     */
    private function floatList(QuickTimeResolver $resolver, string ...$keys): ?array
    {
        foreach ($keys as $key) {
            $raw = $resolver->string($key);
            if ($raw === null) {
                continue;
            }

            $parts = preg_split('/[\\s,]+/', $raw);
            if ($parts === false) {
                continue;
            }

            $values = [];
            foreach ($parts as $part) {
                if ($part === '') {
                    continue;
                }

                if (is_numeric($part)) {
                    $values[] = (float) $part;
                }
            }

            if ($values !== []) {
                return $values;
            }
        }

        return null;
    }

    /**
     * @return list<float>|null
     */
    private function focusDistanceRange(QuickTimeResolver $resolver): ?array
    {
        $range = $this->floatList($resolver, 'FocusDistanceRange');
        if ($range !== null) {
            return $range;
        }

        $near = $resolver->float('FocusDistanceRangeNear') ?? $resolver->float('FocusDistanceNear');
        $far  = $resolver->float('FocusDistanceRangeFar') ?? $resolver->float('FocusDistanceFar');

        $values = [];
        if ($near !== null) {
            $values[] = $near;
        }

        if ($far !== null) {
            $values[] = $far;
        }

        return $values !== [] ? $values : null;
    }

    private function stringOrNumeric(QuickTimeResolver $resolver, string ...$keys): ?string
    {
        foreach ($keys as $key) {
            $value = $resolver->string($key);
            if ($value !== null && $value !== '') {
                return $value;
            }

            $intValue = $resolver->int($key);
            if ($intValue !== null) {
                return (string) $intValue;
            }

            $floatValue = $resolver->float($key);
            if ($floatValue !== null) {
                return (string) $floatValue;
            }
        }

        return null;
    }

    /**
     * @param array<int, string> $map
     */
    private function enumeratedValue(QuickTimeResolver $resolver, array $map, string ...$keys): ?string
    {
        foreach ($keys as $key) {
            $value = $resolver->string($key);
            if ($value !== null && $value !== '') {
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

            $code = $resolver->int($key);
            if ($code !== null) {
                return $map[$code] ?? (string) $code;
            }
        }

        return null;
    }

    /**
     * @return array<string, bool>
     */
    private function flags(QuickTimeResolver $resolver): array
    {
        $flags = [];
        foreach (self::FLAG_KEYS as $key => $normalized) {
            $value = $resolver->bool($key);
            if ($value !== null) {
                $flags[$normalized] = $value;
            }
        }

        return $flags;
    }

    private function int(QuickTimeResolver $resolver, string ...$keys): ?int
    {
        foreach ($keys as $key) {
            $value = $resolver->int($key);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @return array{0:?string,1:?float,2:?float}|null
     */
    private function semanticStyleFromComposite(?QuickTimeMeta $meta): ?array
    {
        if ($meta === null) {
            return null;
        }

        $value = $meta->keys['SemanticStyle'] ?? null;
        if (!is_array($value)) {
            return null;
        }

        /** @var array<int|string, mixed> $semantic */
        $semantic = $value;

        $entries = $this->normaliseSemanticStyleEntries($semantic);
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
    private function normaliseSemanticStyleEntries(array $semantic): ?array
    {
        if (!array_is_list($semantic)) {
            foreach (['values', 'Values'] as $key) {
                if (array_key_exists($key, $semantic) && is_array($semantic[$key])) {
                    /** @var array<int|string, mixed> $values */
                    $values = $semantic[$key];

                    return $this->normaliseSemanticStyleEntries($values);
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
}
