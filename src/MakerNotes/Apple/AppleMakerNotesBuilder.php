<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\MakerNotes\Apple;

use MagicSunday\ImageMeta\MakerNotes\Apple\Support\SemanticStyle;
use MagicSunday\ImageMeta\Value\RunTime;

use function array_key_exists;

/**
 * Builds AppleMakerNotes value objects from decoded dictionaries.
 *
 * Extracts and normalizes all maker note fields from a decoded Apple dictionary,
 * mapping enumerated types, computing derived fields, and validating presence.
 *
 * @phpstan-type NativePlistScalar bool|float|int|string|null
 * @phpstan-type NativePlistValue NativePlistScalar|array<int|string, NativePlistScalar|array<int|string, NativePlistScalar|array<int|string, NativePlistScalar|array<int|string, NativePlistScalar|array<int|string, NativePlistScalar|array<int|string, NativePlistScalar|array<int|string, NativePlistScalar>>>>>>>
 * @phpstan-type NativePlistDictionary array<string, NativePlistValue>
 */
final readonly class AppleMakerNotesBuilder
{
    private AppleDictionaryValueExtractor $extractor;

    public function __construct()
    {
        $this->extractor = new AppleDictionaryValueExtractor();
    }

    /**
     * Builds an AppleMakerNotes value object from decoded dictionary.
     *
     * @param NativePlistDictionary $dictionary Decoded maker notes dictionary.
     *
     * @phpstan-param NativePlistDictionary $dictionary
     *
     * @return AppleMakerNotes|null Apple maker notes object or null if invalid.
     */
    public function build(array $dictionary): ?AppleMakerNotes
    {
        $semanticStyleCompact = null;
        if (
            !array_key_exists('SemanticStylePreset', $dictionary)
            && !array_key_exists('SemanticStyleWarmth', $dictionary)
            && !array_key_exists('SemanticStyleTone', $dictionary)
        ) {
            $semanticStyleCompact = SemanticStyle::fromDictionary($dictionary);
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

        $contentIdentifier = $this->extractor->stringValue($dictionary, 'ContentIdentifier');
        $cameraTypeCode    = $this->extractor->intValue($dictionary, 'CameraType');

        if ($cameraTypeCode !== null) {
            $cameraType = AppleMaps::CAMERA_TYPE_MAP[$cameraTypeCode] ?? $cameraTypeCode;
        } else {
            $cameraType = $this->extractor->stringValue($dictionary, 'CameraType');
        }

        $hdrHeadroom             = $this->extractor->floatValue($dictionary, 'HdrHeadroom', 'HDRHeadroom');
        $hdrGain                 = $this->extractor->floatList($dictionary, 'HdrGain', 'HDRGain');
        $snr                     = $this->extractor->floatValue($dictionary, 'SNRSetting', 'SNR');
        $aeStable                = $this->extractor->boolDictionaryValue($dictionary, 'AEStable');
        $aeTarget                = $this->extractor->rationalFloatValue($dictionary, 'AETarget');
        $aeAverage               = $this->extractor->rationalFloatValue($dictionary, 'AEAverage');
        $afStable                = $this->extractor->boolDictionaryValue($dictionary, 'AFStable');
        $afPerformance           = $this->extractor->rationalFloatValue($dictionary, 'AFPerformance');
        $signalToNoiseRatioType  = $this->extractor->stringOrIntValue($dictionary, 'SignalToNoiseRatioType');
        $luminanceNoiseAmplitude = $this->extractor->rationalFloatValue($dictionary, 'LuminanceNoiseAmplitude');
        $focusPosition           = $this->extractor->floatValue($dictionary, 'FocusPosition');
        $runTime                 = $this->extractor->runTimeValue($dictionary, 'RunTime');
        $livePhotoIndex          = $this->extractor->intValue($dictionary, ...AppleMaps::LIVE_PHOTO_INDEX_KEYS);
        $livePhotoTime           = null;
        if ($livePhotoIndex !== null && $runTime instanceof RunTime) {
            $timescale = $runTime->timescale;
            if ($timescale !== null && $timescale > 0) {
                $livePhotoTime = $livePhotoIndex / $timescale;
            }
        }

        $colorTemperature    = $this->extractor->intValue($dictionary, 'ColorTemperature');
        $semanticStylePreset = $this->extractor->stringValue($dictionary, 'SemanticStylePreset');
        $semanticStyleWarmth = $this->extractor->floatValue($dictionary, 'SemanticStyleWarmth');
        $semanticStyleTone   = $this->extractor->floatValue($dictionary, 'SemanticStyleTone');

        if ($semanticStyleCompact === null) {
            $semanticStyleCompact = SemanticStyle::fromDictionary($dictionary);
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

        $accelerationVector    = $this->extractor->floatList($dictionary, 'AccelerationVector');
        $flags                 = $this->extractor->extractFlags($dictionary);
        $imageCaptureRequestId = $this->extractor->identifierValue($dictionary, 'ImageCaptureRequestID');
        $qualityHint           = $this->extractor->stringOrNumericValue($dictionary, 'QualityHint');
        $colorCorrectionMatrix = $this->extractor->floatList($dictionary, 'ColorCorrectionMatrix');

        $makerNoteVersion   = $this->extractor->makerNoteVersionValue($dictionary, 'MakerNoteVersion');
        $hdrImageType       = $this->extractor->enumeratedStringValue($dictionary, AppleMaps::HDR_IMAGE_TYPES, 'HDRImageType', 'HdrImageType');
        $burstUuid          = $this->extractor->stringValue($dictionary, 'BurstUUID');
        $focusDistanceRange = $this->extractor->focusDistanceRangeValue($dictionary);
        $oisMode            = $this->extractor->stringOrNumericValue($dictionary, 'OISMode');
        $imageCaptureType   = $this->extractor->enumeratedStringValue($dictionary, AppleMaps::IMAGE_CAPTURE_TYPES, 'ImageCaptureType');
        $imageUniqueId      = $this->extractor->stringValue($dictionary, 'ImageUniqueID');
        $photoIdentifier    = $this->extractor->stringValue($dictionary, 'PhotoIdentifier');
        $afMeasuredDepth    = $this->extractor->floatValue($dictionary, 'AFMeasuredDepth');
        $afConfidence       = $this->extractor->floatValue($dictionary, 'AFConfidence');

        $identity = AppleCaptureIdentity::createIfPresent($contentIdentifier, $imageCaptureRequestId, $burstUuid, $imageUniqueId, $photoIdentifier);

        $hdr = AppleHdr::createIfPresent($hdrHeadroom, $hdrGain, $hdrImageType);

        $autoExposure = ($aeStable !== null || $aeTarget !== null || $aeAverage !== null)
            ? new AppleAutoExposure($aeStable, $aeTarget, $aeAverage)
            : null;

        $autoFocus = ($afStable !== null || $afPerformance !== null || $afMeasuredDepth !== null
            || $afConfidence !== null || $focusPosition !== null || $focusDistanceRange !== null)
            ? new AppleAutoFocus($afStable, $afPerformance, $afMeasuredDepth, $afConfidence, $focusPosition, $focusDistanceRange)
            : null;

        $noise = ($snr !== null || $signalToNoiseRatioType !== null || $luminanceNoiseAmplitude !== null)
            ? new AppleNoise($snr, $signalToNoiseRatioType, $luminanceNoiseAmplitude)
            : null;

        $style = ($semanticStylePreset !== null || $semanticStyleWarmth !== null || $semanticStyleTone !== null)
            ? new AppleSemanticStyle($semanticStylePreset, $semanticStyleWarmth, $semanticStyleTone)
            : null;

        $livePhoto = ($livePhotoIndex !== null || $livePhotoTime !== null
            || $runTime instanceof RunTime || $accelerationVector !== null)
            ? new AppleLivePhoto($livePhotoIndex, $livePhotoTime, $runTime, $accelerationVector)
            : null;

        $camera = ($cameraType !== null || $imageCaptureType !== null || $makerNoteVersion !== null
            || $qualityHint !== null || $oisMode !== null || $colorTemperature !== null || $colorCorrectionMatrix !== null)
            ? new AppleCameraCapture($cameraType, $imageCaptureType, $makerNoteVersion, $qualityHint, $oisMode, $colorTemperature, $colorCorrectionMatrix)
            : null;

        if (
            !$identity instanceof AppleCaptureIdentity
            && !$hdr instanceof AppleHdr
            && !$autoExposure instanceof AppleAutoExposure
            && !$autoFocus instanceof AppleAutoFocus
            && !$noise instanceof AppleNoise
            && !$style instanceof AppleSemanticStyle
            && !$livePhoto instanceof AppleLivePhoto
            && !$camera instanceof AppleCameraCapture
            && $flags === []
        ) {
            return null;
        }

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
}
