<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\MakerNotes\Apple;

use MagicSunday\ImageMeta\MakerNotes\Apple\Support\QuickTimeLookup;
use MagicSunday\ImageMeta\MakerNotes\Apple\Support\SemanticStyle;
use MagicSunday\ImageMeta\MakerNotes\MakerNotesRecord;
use MagicSunday\ImageMeta\Model\QuickTimeMeta;

use function array_any;
use function array_key_exists;
use function get_object_vars;
use function is_array;
use function is_numeric;
use function preg_split;
use function str_repeat;
use function trim;

/**
 * Normalises Apple maker notes by enriching them with QuickTime metadata fallbacks.
 */
final class AppleMakerNotesMerger
{
    /**
     * Merges decoded Apple maker notes with QuickTime metadata fallbacks.
     */
    public function merge(
        ?MakerNotesRecord $makerNotes,
        ?QuickTimeMeta $quickTime,
    ): ?MakerNotesRecord {
        if ($makerNotes instanceof MakerNotesRecord && $makerNotes->vendor !== 'Apple') {
            return $makerNotes;
        }

        $apple = $this->buildAppleMakerNotes($makerNotes?->apple, $quickTime);

        if (!$this->hasAppleData($apple)) {
            return $makerNotes;
        }

        if ($makerNotes instanceof MakerNotesRecord) {
            return new MakerNotesRecord(
                $makerNotes->vendor,
                $makerNotes->length,
                $makerNotes->sha1,
                $apple,
                $makerNotes->isSafe,
            );
        }

        if ($quickTime instanceof QuickTimeMeta) {
            return new MakerNotesRecord('Apple', 0, str_repeat('0', 40), $apple);
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
        $lookup = new QuickTimeLookup($quickTime);

        $contentIdentifier = $makerNotes?->contentIdentifier;
        if ($contentIdentifier === null && $quickTime instanceof QuickTimeMeta) {
            $contentIdentifier = $quickTime->contentIdentifier();
        }

        $cameraType = $makerNotes?->cameraType;
        if ($cameraType === null) {
            $cameraType = $lookup->string('CameraType');
        }

        $hdrHeadroom = $makerNotes?->hdrHeadroom;
        if ($hdrHeadroom === null) {
            $hdrHeadroom = $lookup->float('HdrHeadroom', 'HDRHeadroom');
        }

        $hdrGain = $makerNotes?->hdrGain;
        if ($hdrGain === null) {
            $hdrGain = $this->quickTimeFloatList($lookup, 'HdrGain', 'HDRGain');
        }

        $snr = $makerNotes?->snr;
        if ($snr === null) {
            $snr = $lookup->float('SNRSetting', 'SNR');
        }

        $focusPosition = $makerNotes?->focusPosition;
        if ($focusPosition === null) {
            $focusPosition = $lookup->float('FocusPosition');
        }

        $livePhotoIndex = $makerNotes?->livePhotoIndex;
        if ($livePhotoIndex === null) {
            $livePhotoIndex = $lookup->int('LivePhotoVideoIndex', 'LivePhotoMovieIndex');
        }

        $livePhotoTime = $makerNotes?->livePhotoTime;

        $colorTemperature = $makerNotes?->colorTemperature;
        if ($colorTemperature === null) {
            $colorTemperature = $lookup->int('ColorTemperature');
        }

        $semanticPreset = $makerNotes?->semanticStylePreset;
        if ($semanticPreset === null) {
            $semanticPreset = $lookup->string('SemanticStylePreset');
        }

        $semanticWarmth = $makerNotes?->semanticStyleWarmth;
        if ($semanticWarmth === null) {
            $semanticWarmth = $lookup->float('SemanticStyleWarmth');
        }

        $semanticTone = $makerNotes?->semanticStyleTone;
        if ($semanticTone === null) {
            $semanticTone = $lookup->float('SemanticStyleTone');
        }

        $semanticStyleComposite = SemanticStyle::fromQuickTime($quickTime);
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
            $accelerationVector = $this->quickTimeFloatList($lookup, 'AccelerationVector');
        }

        $flags = $makerNotes?->flags;
        if ($flags === null) {
            $flags = [];
        }

        $quickTimeFlags = $this->quickTimeFlags($quickTime);
        foreach ($quickTimeFlags as $key => $value) {
            if (!array_key_exists($key, $flags)) {
                $flags[$key] = $value;
            }
        }

        $imageCaptureRequestId = $makerNotes?->imageCaptureRequestId;
        if ($imageCaptureRequestId === null) {
            $imageCaptureRequestId = $lookup->string('ImageCaptureRequestID');
        }

        $qualityHint = $makerNotes?->qualityHint;
        if ($qualityHint === null) {
            $qualityHint = $this->quickTimeStringOrNumeric($lookup, 'QualityHint');
        }

        $colorCorrectionMatrix = $makerNotes?->colorCorrectionMatrix;
        if ($colorCorrectionMatrix === null) {
            $colorCorrectionMatrix = $this->quickTimeFloatList($lookup, 'ColorCorrectionMatrix');
        }

        $makerNoteVersion = $makerNotes?->makerNoteVersion;
        if ($makerNoteVersion === null) {
            $makerNoteVersion = $lookup->string('MakerNoteVersion');
        }

        $hdrImageType = $this->normalizeEnumerated($makerNotes?->hdrImageType, AppleMaps::HDR_IMAGE_TYPES);
        if ($hdrImageType === null) {
            $hdrImageType = $this->quickTimeEnumerated($lookup, AppleMaps::HDR_IMAGE_TYPES, 'HDRImageType', 'HdrImageType');
        }

        $burstUuid = $makerNotes?->burstUuid;
        if ($burstUuid === null) {
            $burstUuid = $lookup->string('BurstUUID');
        }

        $focusDistanceRange = $makerNotes?->focusDistanceRange;
        if ($focusDistanceRange === null) {
            $focusDistanceRange = $this->quickTimeFocusDistanceRange($lookup);
        }

        $oisMode = $makerNotes?->oisMode;
        if ($oisMode === null) {
            $oisMode = $this->quickTimeStringOrNumeric($lookup, 'OISMode');
        }

        $imageCaptureType = $this->normalizeEnumerated($makerNotes?->imageCaptureType, AppleMaps::IMAGE_CAPTURE_TYPES);
        if ($imageCaptureType === null) {
            $imageCaptureType = $this->quickTimeEnumerated($lookup, AppleMaps::IMAGE_CAPTURE_TYPES, 'ImageCaptureType');
        }

        $imageUniqueId = $makerNotes?->imageUniqueId;
        if ($imageUniqueId === null) {
            $imageUniqueId = $lookup->string('ImageUniqueID');
        }

        $photoIdentifier = $makerNotes?->photoIdentifier;
        if ($photoIdentifier === null) {
            $photoIdentifier = $lookup->string('PhotoIdentifier');
        }

        $afMeasuredDepth = $makerNotes?->afMeasuredDepth;
        if ($afMeasuredDepth === null) {
            $afMeasuredDepth = $lookup->float('AFMeasuredDepth');
        }

        $afConfidence = $makerNotes?->afConfidence;
        if ($afConfidence === null) {
            $afConfidence = $lookup->float('AFConfidence');
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
        $values = get_object_vars($apple);

        if (array_key_exists('flags', $values) && $values['flags'] !== []) {
            return true;
        }

        unset($values['flags']);

        return array_any(
            $values,
            static function ($value): bool {
                if (is_array($value)) {
                    return $value !== [];
                }

                return $value !== null;
            },
        );
    }

    /**
     * Extracts a list of float values from QuickTime metadata.
     *
     * @param QuickTimeLookup $lookup QuickTime metadata lookup instance.
     * @param string          ...$keys One or more keys to search for.
     *
     * @return list<float>|null List of float values if found, null otherwise.
     */
    private function quickTimeFloatList(QuickTimeLookup $lookup, string ...$keys): ?array
    {
        foreach ($keys as $key) {
            $raw = $lookup->string($key);
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
     * Extracts focus distance range from QuickTime metadata.
     *
     * @param QuickTimeLookup $lookup QuickTime metadata lookup instance.
     *
     * @return list<float>|null Focus distance range [near, far] if available, null otherwise.
     */
    private function quickTimeFocusDistanceRange(QuickTimeLookup $lookup): ?array
    {
        $range = $this->quickTimeFloatList($lookup, 'FocusDistanceRange');
        if ($range !== null) {
            return $range;
        }

        $near = $lookup->float('FocusDistanceRangeNear', 'FocusDistanceNear');
        $far  = $lookup->float('FocusDistanceRangeFar', 'FocusDistanceFar');

        $values = [];
        if ($near !== null) {
            $values[] = $near;
        }

        if ($far !== null) {
            $values[] = $far;
        }

        return $values !== [] ? $values : null;
    }

    private function quickTimeStringOrNumeric(QuickTimeLookup $lookup, string ...$keys): ?string
    {
        foreach ($keys as $key) {
            $value = $lookup->string($key);
            if ($value !== null) {
                return $value;
            }

            $intValue = $lookup->int($key);
            if ($intValue !== null) {
                return (string) $intValue;
            }

            $floatValue = $lookup->float($key);
            if ($floatValue !== null) {
                return (string) $floatValue;
            }
        }

        return null;
    }

    /**
     * Normalizes an enumerated value using a mapping table.
     *
     * @param string|null        $value Raw enumerated value.
     * @param array<int, string> $map   Mapping from numeric codes to string labels.
     *
     * @return string|null Normalized value or null if input is null.
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
     * Extracts an enumerated value from QuickTime metadata.
     *
     * @param QuickTimeLookup    $lookup QuickTime metadata lookup instance.
     * @param array<int, string> $map    Mapping from numeric codes to string labels.
     * @param string             ...$keys One or more keys to search for.
     *
     * @return string|null Enumerated value if found, null otherwise.
     */
    private function quickTimeEnumerated(QuickTimeLookup $lookup, array $map, string ...$keys): ?string
    {
        foreach ($keys as $key) {
            $string = $lookup->string($key);
            if ($string !== null) {
                if (is_numeric($string)) {
                    $code = (int) $string;

                    return $map[$code] ?? $string;
                }

                return $string;
            }

            $code = $lookup->int($key);
            if ($code !== null) {
                return $map[$code] ?? (string) $code;
            }
        }

        return null;
    }

    /**
     * Extracts boolean flags from QuickTime metadata.
     *
     * @param QuickTimeMeta|null $quickTime QuickTime metadata instance.
     *
     * @return array<string, bool> Dictionary of flag names to boolean values.
     */
    private function quickTimeFlags(?QuickTimeMeta $quickTime): array
    {
        if (!$quickTime instanceof QuickTimeMeta) {
            return [];
        }

        $flags = [];
        foreach (AppleMaps::FLAG_MAP as $key => $normalized) {
            $value = $quickTime->boolValue($key);
            if ($value !== null) {
                $flags[$normalized] = $value;
            }
        }

        return $flags;
    }
}
