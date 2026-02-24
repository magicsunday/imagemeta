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
use MagicSunday\ImageMeta\Model\QuickTime\QuickTimeMeta;
use MagicSunday\ImageMeta\Value\RunTime;

use function array_any;
use function array_key_exists;
use function get_object_vars;
use function is_array;
use function is_numeric;
use function preg_split;
use function str_repeat;
use function trim;

/**
 * Normalizes Apple maker notes by enriching them with QuickTime metadata fallbacks.
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
                $makerNotes->samsung,
            );
        }

        if ($quickTime instanceof QuickTimeMeta) {
            return new MakerNotesRecord(
                'Apple',
                0,
                str_repeat('0', 40),
                $apple,
            );
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

        $contentIdentifier = $makerNotes?->identity?->contentIdentifier;
        if ($contentIdentifier === null && $quickTime instanceof QuickTimeMeta) {
            $contentIdentifier = $quickTime->contentIdentifier();
        }

        $imageCaptureRequestId = $makerNotes?->identity?->imageCaptureRequestId;
        if ($imageCaptureRequestId === null) {
            $imageCaptureRequestId = $lookup->string('ImageCaptureRequestID');
        }

        $burstUuid = $makerNotes?->identity?->burstUuid;
        if ($burstUuid === null) {
            $burstUuid = $lookup->string('BurstUUID');
        }

        $imageUniqueId = $makerNotes?->identity?->imageUniqueId;
        if ($imageUniqueId === null) {
            $imageUniqueId = $lookup->string('ImageUniqueID');
        }

        $photoIdentifier = $makerNotes?->identity?->photoIdentifier;
        if ($photoIdentifier === null) {
            $photoIdentifier = $lookup->string('PhotoIdentifier');
        }

        $hdrHeadroom = $makerNotes?->hdr?->headroom;
        if ($hdrHeadroom === null) {
            $hdrHeadroom = $lookup->float('HdrHeadroom', 'HDRHeadroom');
        }

        $hdrGain = $makerNotes?->hdr?->gain;
        if ($hdrGain === null) {
            $hdrGain = $this->quickTimeFloatList($lookup, 'HdrGain', 'HDRGain');
        }

        $hdrImageType = $this->normalizeEnumerated($makerNotes?->hdr?->imageType, AppleMaps::HDR_IMAGE_TYPES);
        if ($hdrImageType === null) {
            $hdrImageType = $this->quickTimeEnumerated($lookup, AppleMaps::HDR_IMAGE_TYPES, 'HDRImageType', 'HdrImageType');
        }

        $snr = $makerNotes?->noise?->snr;
        if ($snr === null) {
            $snr = $lookup->float('SNRSetting', 'SNR');
        }

        $focusPosition = $makerNotes?->autoFocus?->focusPosition;
        if ($focusPosition === null) {
            $focusPosition = $lookup->float('FocusPosition');
        }

        $focusDistanceRange = $makerNotes?->autoFocus?->focusDistanceRange;
        if ($focusDistanceRange === null) {
            $focusDistanceRange = $this->quickTimeFocusDistanceRange($lookup);
        }

        $afMeasuredDepth = $makerNotes?->autoFocus?->measuredDepth;
        if ($afMeasuredDepth === null) {
            $afMeasuredDepth = $lookup->float('AFMeasuredDepth');
        }

        $afConfidence = $makerNotes?->autoFocus?->confidence;
        if ($afConfidence === null) {
            $afConfidence = $lookup->float('AFConfidence');
        }

        $livePhotoIndex = $makerNotes?->livePhoto?->index;
        if ($livePhotoIndex === null) {
            $livePhotoIndex = $lookup->int('LivePhotoVideoIndex', 'LivePhotoMovieIndex');
        }

        $livePhotoTime = $makerNotes?->livePhoto?->time;

        $accelerationVector = $makerNotes?->livePhoto?->accelerationVector;
        if ($accelerationVector === null) {
            $accelerationVector = $this->quickTimeFloatList($lookup, 'AccelerationVector');
        }

        $cameraType = $makerNotes?->camera?->cameraType;
        if ($cameraType === null) {
            $cameraType = $lookup->string('CameraType');
        }

        $colorTemperature = $makerNotes?->camera?->colorTemperature;
        if ($colorTemperature === null) {
            $colorTemperature = $lookup->int('ColorTemperature');
        }

        $qualityHint = $makerNotes?->camera?->qualityHint;
        if ($qualityHint === null) {
            $qualityHint = $this->quickTimeStringOrNumeric($lookup, 'QualityHint');
        }

        $colorCorrectionMatrix = $makerNotes?->camera?->colorCorrectionMatrix;
        if ($colorCorrectionMatrix === null) {
            $colorCorrectionMatrix = $this->quickTimeFloatList($lookup, 'ColorCorrectionMatrix');
        }

        $makerNoteVersion = $makerNotes?->camera?->makerNoteVersion;
        if ($makerNoteVersion === null) {
            $makerNoteVersion = $lookup->string('MakerNoteVersion');
        }

        $oisMode = $makerNotes?->camera?->oisMode;
        if ($oisMode === null) {
            $oisMode = $this->quickTimeStringOrNumeric($lookup, 'OISMode');
        }

        $imageCaptureType = $this->normalizeEnumerated($makerNotes?->camera?->imageCaptureType, AppleMaps::IMAGE_CAPTURE_TYPES);
        if ($imageCaptureType === null) {
            $imageCaptureType = $this->quickTimeEnumerated($lookup, AppleMaps::IMAGE_CAPTURE_TYPES, 'ImageCaptureType');
        }

        $semanticPreset = $makerNotes?->semanticStyle?->preset;
        if ($semanticPreset === null) {
            $semanticPreset = $lookup->string('SemanticStylePreset');
        }

        $semanticWarmth = $makerNotes?->semanticStyle?->warmth;
        if ($semanticWarmth === null) {
            $semanticWarmth = $lookup->float('SemanticStyleWarmth');
        }

        $semanticTone = $makerNotes?->semanticStyle?->tone;
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

        $identity = AppleCaptureIdentity::createIfPresent($contentIdentifier, $imageCaptureRequestId, $burstUuid, $imageUniqueId, $photoIdentifier);

        $hdr = AppleHdr::createIfPresent($hdrHeadroom, $hdrGain, $hdrImageType);

        $autoExposure = $makerNotes?->autoExposure;

        $autoFocus = ($makerNotes?->autoFocus?->stable !== null || $makerNotes?->autoFocus?->performance !== null
            || $afMeasuredDepth !== null || $afConfidence !== null || $focusPosition !== null || $focusDistanceRange !== null)
            ? new AppleAutoFocus($makerNotes?->autoFocus?->stable, $makerNotes?->autoFocus?->performance, $afMeasuredDepth, $afConfidence, $focusPosition, $focusDistanceRange)
            : null;

        $noise = ($snr !== null || $makerNotes?->noise?->signalToNoiseRatioType !== null
            || $makerNotes?->noise?->luminanceNoiseAmplitude !== null)
            ? new AppleNoise($snr, $makerNotes?->noise?->signalToNoiseRatioType, $makerNotes?->noise?->luminanceNoiseAmplitude)
            : null;

        $style = ($semanticPreset !== null || $semanticWarmth !== null || $semanticTone !== null)
            ? new AppleSemanticStyle($semanticPreset, $semanticWarmth, $semanticTone)
            : null;

        $livePhoto = ($livePhotoIndex !== null || $livePhotoTime !== null
            || $makerNotes?->livePhoto?->runTime instanceof RunTime || $accelerationVector !== null)
            ? new AppleLivePhoto($livePhotoIndex, $livePhotoTime, $makerNotes?->livePhoto?->runTime, $accelerationVector)
            : null;

        $camera = ($cameraType !== null || $imageCaptureType !== null || $makerNoteVersion !== null
            || $qualityHint !== null || $oisMode !== null || $colorTemperature !== null || $colorCorrectionMatrix !== null)
            ? new AppleCameraCapture($cameraType, $imageCaptureType, $makerNoteVersion, $qualityHint, $oisMode, $colorTemperature, $colorCorrectionMatrix)
            : null;

        return new AppleMakerNotes(
            $identity,
            $hdr,
            $autoExposure,
            $autoFocus,
            $noise,
            $style,
            $livePhoto,
            $camera,
            $flags,
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
     * @param QuickTimeLookup $lookup  QuickTime metadata lookup instance.
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

    /**
     * Resolves the first QuickTime metadata value as a string, coercing numeric values.
     *
     * @param QuickTimeLookup $lookup  Lookup helper for QuickTime keys.
     * @param string          ...$keys Candidate keys to inspect in order.
     *
     * @return string|null Resolved string value or null.
     */
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
     * @param QuickTimeLookup    $lookup  QuickTime metadata lookup instance.
     * @param array<int, string> $map     Mapping from numeric codes to string labels.
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
