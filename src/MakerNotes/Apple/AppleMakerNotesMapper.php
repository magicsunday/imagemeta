<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\MakerNotes\Apple;

use MagicSunday\ImageMeta\MakerNotes\AppleMetadata;
use MagicSunday\ImageMeta\MakerNotes\MakerNotesMetadata;
use MagicSunday\ImageMeta\Model\QuickTimeMeta;

use function array_is_list;
use function array_key_exists;
use function get_object_vars;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function preg_split;
use function str_repeat;
use function trim;

/**
 * Normalises Apple maker notes by enriching them with QuickTime metadata fallbacks.
 */
final class AppleMakerNotesMapper
{
    /**
     * Merges decoded Apple maker notes with QuickTime metadata fallbacks.
     */
    public function map(
        ?MakerNotesMetadata $makerNotes,
        ?QuickTimeMeta $quickTime,
    ): ?MakerNotesMetadata {
        if ($makerNotes instanceof MakerNotesMetadata && $makerNotes->vendor() !== 'Apple') {
            return $makerNotes;
        }

        $apple = $this->buildAppleMakerNotes($makerNotes?->apple(), $quickTime);

        if ($makerNotes instanceof MakerNotesMetadata) {
            return new MakerNotesMetadata(
                $makerNotes->vendor(),
                $makerNotes->length(),
                $makerNotes->sha1(),
                $apple,
                $makerNotes->isSafe(),
            );
        }

        if ($quickTime instanceof QuickTimeMeta && $this->hasAppleData($apple)) {
            return new MakerNotesMetadata('Apple', 0, str_repeat('0', 40), $apple);
        }

        return $makerNotes;
    }

    /**
     * Builds the Apple maker note value object by applying QuickTime fallbacks.
     */
    private function buildAppleMakerNotes(
        ?AppleMakerNotes $makerNotes,
        ?QuickTimeMeta $quickTime,
    ): AppleMakerNotes {
        $contentIdentifier = $makerNotes?->contentIdentifier ?? $quickTime?->contentIdentifier();

        $cameraType = $makerNotes?->cameraType;
        if ($cameraType === null) {
            $cameraType = $this->quickTimeString($quickTime, 'CameraType');
        }

        $hdrHeadroom = $makerNotes?->hdrHeadroom;
        if ($hdrHeadroom === null) {
            $hdrHeadroom = $this->quickTimeFloat($quickTime, 'HdrHeadroom', 'HDRHeadroom');
        }

        $hdrGain = $makerNotes?->hdrGain;
        if ($hdrGain === null) {
            $hdrGain = $this->quickTimeFloatList($quickTime, 'HdrGain', 'HDRGain');
        }

        $snr = $makerNotes?->snr;
        if ($snr === null) {
            $snr = $this->quickTimeFloat($quickTime, 'SNRSetting', 'SNR');
        }

        $focusPosition = $makerNotes?->focusPosition;
        if ($focusPosition === null) {
            $focusPosition = $this->quickTimeFloat($quickTime, 'FocusPosition');
        }

        $livePhotoIndex = $makerNotes?->livePhotoIndex;
        if ($livePhotoIndex === null) {
            $livePhotoIndex = $this->quickTimeInt($quickTime, 'LivePhotoVideoIndex', 'LivePhotoMovieIndex');
        }

        $livePhotoTime = $makerNotes?->livePhotoTime;

        $colorTemperature = $makerNotes?->colorTemperature;
        if ($colorTemperature === null) {
            $colorTemperature = $this->quickTimeInt($quickTime, 'ColorTemperature');
        }

        $semanticPreset = $makerNotes?->semanticStylePreset;
        if ($semanticPreset === null) {
            $semanticPreset = $this->quickTimeString($quickTime, 'SemanticStylePreset');
        }

        $semanticWarmth = $makerNotes?->semanticStyleWarmth;
        if ($semanticWarmth === null) {
            $semanticWarmth = $this->quickTimeFloat($quickTime, 'SemanticStyleWarmth');
        }

        $semanticTone = $makerNotes?->semanticStyleTone;
        if ($semanticTone === null) {
            $semanticTone = $this->quickTimeFloat($quickTime, 'SemanticStyleTone');
        }

        $semanticStyleComposite = $this->quickTimeSemanticStyle($quickTime);
        if ($semanticStyleComposite !== null) {
            [$compositePreset, $compositeWarmth, $compositeTone] = $semanticStyleComposite;

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

        $accelerationVector = $makerNotes?->accelerationVector;
        if ($accelerationVector === null) {
            $accelerationVector = $this->quickTimeFloatList($quickTime, 'AccelerationVector');
        }

        $flags = $makerNotes?->flags ?? [];
        $quickTimeFlags = $this->quickTimeFlags($quickTime);
        foreach ($quickTimeFlags as $key => $value) {
            if (!array_key_exists($key, $flags)) {
                $flags[$key] = $value;
            }
        }

        $imageCaptureRequestId = $makerNotes?->imageCaptureRequestId;
        if ($imageCaptureRequestId === null) {
            $imageCaptureRequestId = $this->quickTimeString($quickTime, 'ImageCaptureRequestID');
        }

        $qualityHint = $makerNotes?->qualityHint;
        if ($qualityHint === null) {
            $qualityHint = $this->quickTimeStringOrNumeric($quickTime, 'QualityHint');
        }

        $colorCorrectionMatrix = $makerNotes?->colorCorrectionMatrix;
        if ($colorCorrectionMatrix === null) {
            $colorCorrectionMatrix = $this->quickTimeFloatList($quickTime, 'ColorCorrectionMatrix');
        }

        $makerNoteVersion = $makerNotes?->makerNoteVersion;
        if ($makerNoteVersion === null) {
            $makerNoteVersion = $this->quickTimeString($quickTime, 'MakerNoteVersion');
        }

        $hdrImageType = $this->normalizeEnumerated($makerNotes?->hdrImageType, AppleMetadata::HDR_IMAGE_TYPES);
        if ($hdrImageType === null) {
            $hdrImageType = $this->quickTimeEnumerated($quickTime, AppleMetadata::HDR_IMAGE_TYPES, 'HDRImageType', 'HdrImageType');
        }

        $burstUuid = $makerNotes?->burstUuid;
        if ($burstUuid === null) {
            $burstUuid = $this->quickTimeString($quickTime, 'BurstUUID');
        }

        $focusDistanceRange = $makerNotes?->focusDistanceRange;
        if ($focusDistanceRange === null) {
            $focusDistanceRange = $this->quickTimeFocusDistanceRange($quickTime);
        }

        $oisMode = $makerNotes?->oisMode;
        if ($oisMode === null) {
            $oisMode = $this->quickTimeStringOrNumeric($quickTime, 'OISMode');
        }

        $imageCaptureType = $this->normalizeEnumerated($makerNotes?->imageCaptureType, AppleMetadata::IMAGE_CAPTURE_TYPES);
        if ($imageCaptureType === null) {
            $imageCaptureType = $this->quickTimeEnumerated($quickTime, AppleMetadata::IMAGE_CAPTURE_TYPES, 'ImageCaptureType');
        }

        $imageUniqueId = $makerNotes?->imageUniqueId;
        if ($imageUniqueId === null) {
            $imageUniqueId = $this->quickTimeString($quickTime, 'ImageUniqueID');
        }

        $photoIdentifier = $makerNotes?->photoIdentifier;
        if ($photoIdentifier === null) {
            $photoIdentifier = $this->quickTimeString($quickTime, 'PhotoIdentifier');
        }

        $afMeasuredDepth = $makerNotes?->afMeasuredDepth;
        if ($afMeasuredDepth === null) {
            $afMeasuredDepth = $this->quickTimeFloat($quickTime, 'AFMeasuredDepth');
        }

        $afConfidence = $makerNotes?->afConfidence;
        if ($afConfidence === null) {
            $afConfidence = $this->quickTimeFloat($quickTime, 'AFConfidence');
        }

        return new AppleMakerNotes(
            contentIdentifier: $contentIdentifier,
            cameraType: $cameraType,
            hdrHeadroom: $hdrHeadroom,
            hdrGain: $hdrGain,
            snr: $snr,
            aeStable: $makerNotes?->aeStable,
            aeTarget: $makerNotes?->aeTarget,
            aeAverage: $makerNotes?->aeAverage,
            afStable: $makerNotes?->afStable,
            afPerformance: $makerNotes?->afPerformance,
            signalToNoiseRatioType: $makerNotes?->signalToNoiseRatioType,
            luminanceNoiseAmplitude: $makerNotes?->luminanceNoiseAmplitude,
            focusPosition: $focusPosition,
            livePhotoIndex: $livePhotoIndex,
            colorTemperature: $colorTemperature,
            semanticStylePreset: $semanticPreset,
            semanticStyleWarmth: $semanticWarmth,
            semanticStyleTone: $semanticTone,
            flags: $flags,
            accelerationVector: $accelerationVector,
            imageCaptureRequestId: $imageCaptureRequestId,
            qualityHint: $qualityHint,
            colorCorrectionMatrix: $colorCorrectionMatrix,
            livePhotoTime: $livePhotoTime,
            runTime: $makerNotes?->runTime,
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
     * Determines whether the Apple maker notes contain any populated values.
     */
    private function hasAppleData(AppleMakerNotes $apple): bool
    {
        foreach (get_object_vars($apple) as $key => $value) {
            if ($key === 'flags') {
                if ($value !== []) {
                    return true;
                }

                continue;
            }

            if (is_array($value)) {
                if ($value !== []) {
                    return true;
                }

                continue;
            }

            if ($value !== null) {
                return true;
            }
        }

        return false;
    }

    private function quickTimeString(?QuickTimeMeta $quickTime, string ...$keys): ?string
    {
        if ($quickTime === null) {
            return null;
        }

        foreach ($keys as $key) {
            $value = $quickTime->stringValue($key);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function quickTimeFloat(?QuickTimeMeta $quickTime, string ...$keys): ?float
    {
        if ($quickTime === null) {
            return null;
        }

        foreach ($keys as $key) {
            $value = $quickTime->floatValue($key);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function quickTimeInt(?QuickTimeMeta $quickTime, string ...$keys): ?int
    {
        if ($quickTime === null) {
            return null;
        }

        foreach ($keys as $key) {
            $value = $quickTime->intValue($key);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @return list<float>|null
     */
    private function quickTimeFloatList(?QuickTimeMeta $quickTime, string ...$keys): ?array
    {
        if ($quickTime === null) {
            return null;
        }

        foreach ($keys as $key) {
            $raw = $this->quickTimeString($quickTime, $key);
            if ($raw === null) {
                continue;
            }

            $parts = preg_split('/[ ,]+/', trim($raw));
            if (!is_array($parts)) {
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
    private function quickTimeFocusDistanceRange(?QuickTimeMeta $quickTime): ?array
    {
        $range = $this->quickTimeFloatList($quickTime, 'FocusDistanceRange');
        if ($range !== null) {
            return $range;
        }

        $near = $this->quickTimeFloat($quickTime, 'FocusDistanceRangeNear', 'FocusDistanceNear');
        $far = $this->quickTimeFloat($quickTime, 'FocusDistanceRangeFar', 'FocusDistanceFar');

        $values = [];
        if ($near !== null) {
            $values[] = $near;
        }

        if ($far !== null) {
            $values[] = $far;
        }

        return $values !== [] ? $values : null;
    }

    private function quickTimeStringOrNumeric(?QuickTimeMeta $quickTime, string ...$keys): ?string
    {
        if ($quickTime === null) {
            return null;
        }

        foreach ($keys as $key) {
            $value = $this->quickTimeString($quickTime, $key);
            if ($value !== null) {
                return $value;
            }

            $intValue = $quickTime->intValue($key);
            if ($intValue !== null) {
                return (string) $intValue;
            }

            $floatValue = $quickTime->floatValue($key);
            if ($floatValue !== null) {
                return (string) $floatValue;
            }
        }

        return null;
    }

    /**
     * @param array<int, string> $map
     */
    private function normalizeEnumerated(?string $value, array $map): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        if (is_numeric($trimmed)) {
            $code = (int) $trimmed;

            return $map[$code] ?? $trimmed;
        }

        return $trimmed;
    }

    /**
     * @param array<int, string> $map
     */
    private function quickTimeEnumerated(?QuickTimeMeta $quickTime, array $map, string ...$keys): ?string
    {
        if ($quickTime === null) {
            return null;
        }

        foreach ($keys as $key) {
            $string = $this->quickTimeString($quickTime, $key);
            if ($string !== null) {
                if (is_numeric($string)) {
                    $code = (int) $string;

                    return $map[$code] ?? $string;
                }

                return $string;
            }

            $code = $quickTime->intValue($key);
            if ($code !== null) {
                return $map[$code] ?? (string) $code;
            }
        }

        return null;
    }

    /**
     * @return array{0:?string,1:?float,2:?float}|null
     */
    private function quickTimeSemanticStyle(?QuickTimeMeta $meta = null): ?array
    {
        if ($meta === null) {
            return null;
        }

        $value = $meta->keys['SemanticStyle'] ?? null;
        if (!is_array($value)) {
            return null;
        }

        $entries = $this->normaliseSemanticStyleEntries($value);
        if ($entries === null) {
            return null;
        }

        $presetRaw = $this->semanticStyleEntry($entries, 0);
        $legacyWarmth = $this->semanticStyleEntry($entries, 1);
        $modernWarmth = $legacyWarmth === null ? $this->semanticStyleEntry($entries, 2) : null;
        $warmthRaw = $legacyWarmth ?? $modernWarmth;
        $toneRawLegacy = $legacyWarmth !== null ? $this->semanticStyleEntry($entries, 2) : null;
        $toneRawModern = $legacyWarmth === null ? $this->semanticStyleEntry($entries, 3, 2) : null;
        $toneRaw = $toneRawLegacy ?? $toneRawModern;

        $preset = $this->semanticStylePreset($presetRaw);
        $warmth = $this->semanticStyleFloat($warmthRaw);
        $tone = $this->semanticStyleFloat($toneRaw);

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
                    return $this->normaliseSemanticStyleEntries($semantic[$key]);
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
     * @return array<string, bool>
     */
    private function quickTimeFlags(?QuickTimeMeta $quickTime): array
    {
        if ($quickTime === null) {
            return [];
        }

        $flags = [];
        foreach (AppleMetadata::FLAG_MAP as $key => $normalized) {
            $value = $quickTime->boolValue($key);
            if ($value !== null) {
                $flags[$normalized] = $value;
            }
        }

        return $flags;
    }
}
