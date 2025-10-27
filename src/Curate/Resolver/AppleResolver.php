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
        $cameraTypeString  = $this->quickTimeString($quickTimeMeta, 'CameraType');
        $cameraType        = $cameraTypeString ?? $this->quickTimeInt($quickTimeMeta, 'CameraType');
        $hdrHeadroom       = $this->quickTimeFloat($quickTimeMeta, 'HdrHeadroom', 'HDRHeadroom');
        $hdrGain           = $this->floatList($quickTimeMeta, 'HdrGain', 'HDRGain');
        $snr               = $this->quickTimeFloat($quickTimeMeta, 'SNRSetting', 'SNR');
        $focusPosition     = $this->quickTimeFloat($quickTimeMeta, 'FocusPosition');
        $livePhotoIndex    = $this->quickTimeInt($quickTimeMeta, 'LivePhotoVideoIndex');
        $colorTemperature  = $this->quickTimeInt($quickTimeMeta, 'ColorTemperature');
        $semanticPreset    = $this->quickTimeString($quickTimeMeta, 'SemanticStylePreset');
        $semanticWarmth    = $this->quickTimeFloat($quickTimeMeta, 'SemanticStyleWarmth');
        $semanticTone      = $this->quickTimeFloat($quickTimeMeta, 'SemanticStyleTone');
        $accelerationVector = $this->floatList($quickTimeMeta, 'AccelerationVector');
        $flags             = $this->flags($quickTimeMeta);

        $makerNoteVersion  = $this->quickTimeString($quickTimeMeta, 'MakerNoteVersion');
        $hdrImageType      = $this->enumeratedValue($quickTimeMeta, AppleMetadata::HDR_IMAGE_TYPES, 'HDRImageType', 'HdrImageType');
        $burstUuid         = $this->quickTimeString($quickTimeMeta, 'BurstUUID');
        $focusDistanceRange = $this->focusDistanceRange($quickTimeMeta);
        $oisMode           = $this->stringOrNumeric($quickTimeMeta, 'OISMode');
        $imageCaptureType  = $this->enumeratedValue($quickTimeMeta, AppleMetadata::IMAGE_CAPTURE_TYPES, 'ImageCaptureType');
        $imageUniqueId     = $this->quickTimeString($quickTimeMeta, 'ImageUniqueID');
        $photoIdentifier   = $this->quickTimeString($quickTimeMeta, 'PhotoIdentifier');
        $afMeasuredDepth   = $this->quickTimeFloat($quickTimeMeta, 'AFMeasuredDepth');
        $afConfidence      = $this->quickTimeFloat($quickTimeMeta, 'AFConfidence');

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
    private function floatList(?QuickTimeMeta $meta, string ...$keys): ?array
    {
        if ($meta === null) {
            return null;
        }

        foreach ($keys as $key) {
            $raw = $this->quickTimeString($meta, $key);
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
    private function focusDistanceRange(?QuickTimeMeta $meta): ?array
    {
        if ($meta === null) {
            return null;
        }

        $range = $this->floatList($meta, 'FocusDistanceRange');
        if ($range !== null) {
            return $range;
        }

        $near = $this->quickTimeFloat($meta, 'FocusDistanceRangeNear', 'FocusDistanceNear');
        $far  = $this->quickTimeFloat($meta, 'FocusDistanceRangeFar', 'FocusDistanceFar');

        $values = [];
        if ($near !== null) {
            $values[] = $near;
        }

        if ($far !== null) {
            $values[] = $far;
        }

        return $values !== [] ? $values : null;
    }

    private function stringOrNumeric(?QuickTimeMeta $meta, string ...$keys): ?string
    {
        if ($meta === null) {
            return null;
        }

        foreach ($keys as $key) {
            $value = $this->quickTimeString($meta, $key);
            if ($value !== null && $value !== '') {
                return $value;
            }

            $intValue = $meta->intValue($key);
            if ($intValue !== null) {
                return (string) $intValue;
            }

            $floatValue = $meta->floatValue($key);
            if ($floatValue !== null) {
                return (string) $floatValue;
            }
        }

        return null;
    }

    /**
     * @param array<int, string> $map
     */
    private function enumeratedValue(?QuickTimeMeta $meta, array $map, string ...$keys): ?string
    {
        if ($meta === null) {
            return null;
        }

        foreach ($keys as $key) {
            $value = $this->quickTimeString($meta, $key);
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

            $code = $meta->intValue($key);
            if ($code !== null) {
                return $map[$code] ?? (string) $code;
            }
        }

        return null;
    }

    /**
     * @return array<string, bool>
     */
    private function flags(?QuickTimeMeta $meta): array
    {
        if ($meta === null) {
            return [];
        }

        $flags = [];
        foreach (AppleMetadata::FLAG_MAP as $key => $normalized) {
            $value = $meta->boolValue($key);
            if ($value !== null) {
                $flags[$normalized] = $value;
            }
        }

        return $flags;
    }

    private function quickTimeString(?QuickTimeMeta $meta, string ...$keys): ?string
    {
        if ($meta === null) {
            return null;
        }

        foreach ($keys as $key) {
            $value = $meta->stringValue($key);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function quickTimeFloat(?QuickTimeMeta $meta, string ...$keys): ?float
    {
        if ($meta === null) {
            return null;
        }

        foreach ($keys as $key) {
            $value = $meta->floatValue($key);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function quickTimeInt(?QuickTimeMeta $meta, string ...$keys): ?int
    {
        if ($meta === null) {
            return null;
        }

        foreach ($keys as $key) {
            $value = $meta->intValue($key);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function quickTimeBool(?QuickTimeMeta $meta, string ...$keys): ?bool
    {
        if ($meta === null) {
            return null;
        }

        foreach ($keys as $key) {
            $value = $meta->boolValue($key);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

}
