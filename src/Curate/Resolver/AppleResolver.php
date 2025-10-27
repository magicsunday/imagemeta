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
use MagicSunday\ImageMeta\MakerNotes\AppleMetadata;
use MagicSunday\ImageMeta\Model\QuickTimeMeta;

use function is_numeric;
use function preg_split;
use function trim;

/**
 * Resolves Apple specific metadata from QuickTime containers.
 */
final readonly class AppleResolver
{
    /**
     * Builds an Apple maker note value object from available metadata.
     */
    public function resolve(?QuickTimeMeta $quickTimeMeta): ?AppleMakerNotes
    {
        if (!$quickTimeMeta instanceof QuickTimeMeta) {
            return null;
        }

        $identifier = $quickTimeMeta->contentIdentifier();
        $resolver   = new QuickTimeResolver($quickTimeMeta);

        $cameraTypeString  = $resolver->string('CameraType');
        $cameraType        = $cameraTypeString ?? $resolver->int('CameraType');
        $hdrHeadroom       = $resolver->float('HdrHeadroom') ?? $resolver->float('HDRHeadroom');
        $hdrGain           = $this->floatList($resolver, 'HdrGain', 'HDRGain');
        $snr               = $resolver->float('SNRSetting') ?? $resolver->float('SNR');
        $focusPosition     = $resolver->float('FocusPosition');
        $livePhotoIndex    = $resolver->int('LivePhotoVideoIndex');
        $colorTemperature  = $resolver->int('ColorTemperature');
        $semanticPreset    = $resolver->string('SemanticStylePreset');
        $semanticWarmth    = $resolver->float('SemanticStyleWarmth');
        $semanticTone      = $resolver->float('SemanticStyleTone');
        $accelerationVector = $this->floatList($resolver, 'AccelerationVector');
        $flags             = $this->flags($resolver);

        $makerNoteVersion  = $resolver->string('MakerNoteVersion');
        $hdrImageType      = $this->enumeratedValue($resolver, AppleMetadata::HDR_IMAGE_TYPES, 'HDRImageType', 'HdrImageType');
        $burstUuid         = $resolver->string('BurstUUID');
        $focusDistanceRange = $this->focusDistanceRange($resolver);
        $oisMode           = $this->stringOrNumeric($resolver, 'OISMode');
        $imageCaptureType  = $this->enumeratedValue($resolver, AppleMetadata::IMAGE_CAPTURE_TYPES, 'ImageCaptureType');
        $imageUniqueId     = $resolver->string('ImageUniqueID');
        $photoIdentifier   = $resolver->string('PhotoIdentifier');
        $afMeasuredDepth   = $resolver->float('AFMeasuredDepth');
        $afConfidence      = $resolver->float('AFConfidence');

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
            imageCaptureRequestId: null,
            qualityHint: null,
            colorCorrectionMatrix: null,
            livePhotoTime: null,
            runTime: null,
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
        foreach (AppleMetadata::FLAG_MAP as $key => $normalized) {
            $value = $resolver->bool($key);
            if ($value !== null) {
                $flags[$normalized] = $value;
            }
        }

        return $flags;
    }
}
