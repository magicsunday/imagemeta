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
 * @phpstan-type NativePlistScalar = bool|float|int|string|null
 * @phpstan-type NativePlistValue = NativePlistScalar|array<int|string, NativePlistScalar|array<int|string, NativePlistScalar|array<int|string, NativePlistScalar|array<int|string, NativePlistScalar|array<int|string, NativePlistScalar|array<int|string, NativePlistScalar|array<int|string, NativePlistScalar>>>>>>>
 * @phpstan-type NativePlistDictionary = array<string, NativePlistValue>
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
     * @return AppleMakerNotes|null Apple maker notes object or null if invalid.
     */
    public function build(array $dictionary): ?AppleMakerNotes
    {
        $semanticStyleCompact = $this->seedSemanticStyleKeys($dictionary);
        $identityData         = $this->loadIdentitySection($dictionary);
        $hdrData              = $this->loadHdrSection($dictionary);
        $focusData            = $this->loadFocusSection($dictionary);
        $styleData            = $this->loadStyleSection($dictionary, $semanticStyleCompact);
        $cameraData           = $this->loadCameraSection($dictionary);

        $snr                    = $this->extractor->floatValue($dictionary, 'SNRSetting', 'SNR');
        $aeStable               = $this->extractor->boolDictionaryValue($dictionary, 'AEStable');
        $aeTarget               = $this->extractor->rationalFloatValue($dictionary, 'AETarget');
        $aeAverage              = $this->extractor->rationalFloatValue($dictionary, 'AEAverage');
        $signalToNoiseRatioType = $this->extractor->stringOrIntValue($dictionary, 'SignalToNoiseRatioType');
        $luminanceAmplitude     = $this->extractor->rationalFloatValue($dictionary, 'LuminanceNoiseAmplitude');
        $runTime                = $this->extractor->runTimeValue($dictionary, 'RunTime');
        $livePhotoIndex         = $this->extractor->intValue($dictionary, ...AppleMaps::LIVE_PHOTO_INDEX_KEYS);
        $livePhotoTime          = null;

        if (($livePhotoIndex !== null) && ($runTime instanceof RunTime)) {
            $timescale = $runTime->timescale;

            if (($timescale !== null) && ($timescale > 0)) {
                $livePhotoTime = $livePhotoIndex / $timescale;
            }
        }

        $accelerationVector = $this->extractor->floatList($dictionary, 'AccelerationVector');
        $flags              = $this->extractor->extractFlags($dictionary);
        $identity           = AppleCaptureIdentity::createIfPresent(
            $identityData['contentIdentifier'],
            $identityData['imageCaptureRequestId'],
            $identityData['burstUuid'],
            $identityData['imageUniqueId'],
            $identityData['photoIdentifier'],
            $identityData['mediaGroupUuid'],
        );

        $hdr = AppleHdr::createIfPresent(
            $hdrData['hdrHeadroom'],
            $hdrData['hdrGain'],
            $hdrData['hdrImageType'],
        );

        $autoExposure = (($aeStable !== null) || ($aeTarget !== null) || ($aeAverage !== null))
            ? new AppleAutoExposure($aeStable, $aeTarget, $aeAverage)
            : null;

        $autoFocus = (($focusData['afStable'] !== null) || ($focusData['afPerformance'] !== null) || ($focusData['afMeasuredDepth'] !== null)
            || ($focusData['afConfidence'] !== null) || ($focusData['focusPosition'] !== null) || ($focusData['focusDistanceRange'] !== null))
            ? new AppleAutoFocus(
                $focusData['afStable'],
                $focusData['afPerformance'],
                $focusData['afMeasuredDepth'],
                $focusData['afConfidence'],
                $focusData['focusPosition'],
                $focusData['focusDistanceRange'],
            )
            : null;

        $noise = (($snr !== null) || ($signalToNoiseRatioType !== null) || ($luminanceAmplitude !== null))
            ? new AppleNoise($snr, $signalToNoiseRatioType, $luminanceAmplitude)
            : null;

        $style = (($styleData['semanticStylePreset'] !== null) || ($styleData['semanticStyleWarmth'] !== null) || ($styleData['semanticStyleTone'] !== null))
            ? new AppleSemanticStyle(
                $styleData['semanticStylePreset'],
                $styleData['semanticStyleWarmth'],
                $styleData['semanticStyleTone'],
            )
            : null;

        $livePhoto = (($livePhotoIndex !== null) || ($livePhotoTime !== null)
            || ($runTime instanceof RunTime) || ($accelerationVector !== null))
            ? new AppleLivePhoto($livePhotoIndex, $livePhotoTime, $runTime, $accelerationVector)
            : null;

        $camera = (
            ($identityData['type'] !== null) || ($cameraData['imageCaptureType'] !== null) || ($cameraData['makerNoteVersion'] !== null)
            || ($cameraData['qualityHint'] !== null) || ($cameraData['oisMode'] !== null)
            || ($cameraData['colorTemperature'] !== null) || ($cameraData['colorCorrectionMatrix'] !== null)
        )
            ? new AppleCameraCapture(
                $identityData['type'],
                $cameraData['imageCaptureType'],
                $cameraData['makerNoteVersion'],
                $cameraData['qualityHint'],
                $cameraData['oisMode'],
                $cameraData['colorTemperature'],
                $cameraData['colorCorrectionMatrix'],
            )
            : null;

        if (!$this->hasAnySectionData($identity, $hdr, $autoExposure, $autoFocus, $noise, $style, $livePhoto, $camera, $flags)) {
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

    /**
     * Seeds explicit semantic-style keys from compact semantic-style fields when absent.
     *
     * @param NativePlistDictionary $dictionary Decoded maker notes dictionary to seed.
     *
     * @return array|null Compact semantic-style tuple or null when explicit keys exist.
     *
     * @phpstan-return array{0:?string,1:?float,2:?float}|null
     */
    private function seedSemanticStyleKeys(array &$dictionary): ?array
    {
        if (!array_key_exists('SemanticStylePreset', $dictionary) && !array_key_exists('SemanticStyleWarmth', $dictionary) && !array_key_exists('SemanticStyleTone', $dictionary)) {
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

            return $semanticStyleCompact;
        }

        return null;
    }

    /**
     * Loads capture identity and camera-type fields.
     *
     * @param NativePlistDictionary $dictionary Decoded maker notes dictionary.
     *
     * @return array{
     *     contentIdentifier:?string,
     *     type:string|int|null,
     *     imageCaptureRequestId:string|int|null,
     *     burstUuid:?string,
     *     imageUniqueId:?string,
     *     photoIdentifier:?string,
     *     mediaGroupUuid:?string
     * }
     */
    private function loadIdentitySection(array $dictionary): array
    {
        $contentIdentifier = $this->extractor->stringValue($dictionary, 'ContentIdentifier');
        $cameraTypeCode    = $this->extractor->intValue($dictionary, 'CameraType');

        if ($cameraTypeCode !== null) {
            if (array_key_exists($cameraTypeCode, AppleMaps::CAMERA_TYPE_MAP)) {
                $cameraType = AppleMaps::CAMERA_TYPE_MAP[$cameraTypeCode];
            } else {
                $cameraType = $cameraTypeCode;
            }
        } else {
            $cameraType = $this->extractor->stringValue($dictionary, 'CameraType');
        }

        return [
            'contentIdentifier'     => $contentIdentifier,
            'type'                  => $cameraType,
            'imageCaptureRequestId' => $this->extractor->identifierValue($dictionary, 'ImageCaptureRequestID'),
            'burstUuid'             => $this->extractor->stringValue($dictionary, 'BurstUUID'),
            'imageUniqueId'         => $this->extractor->stringValue($dictionary, 'ImageUniqueID'),
            'photoIdentifier'       => $this->extractor->stringValue($dictionary, 'PhotoIdentifier'),
            'mediaGroupUuid'        => $this->extractor->stringValue($dictionary, 'MediaGroupUUID'),
        ];
    }

    /**
     * Loads HDR-specific fields from the dictionary.
     *
     * @param NativePlistDictionary $dictionary Decoded maker notes dictionary.
     *
     * @return array{
     *     hdrHeadroom:?float,
     *     hdrGain:list<float>|null,
     *     hdrImageType:?string
     * }
     */
    private function loadHdrSection(array $dictionary): array
    {
        return [
            'hdrHeadroom'  => $this->extractor->floatValue($dictionary, 'HdrHeadroom', 'HDRHeadroom'),
            'hdrGain'      => $this->extractor->floatList($dictionary, 'HdrGain', 'HDRGain'),
            'hdrImageType' => $this->extractor->enumeratedStringValue($dictionary, AppleMaps::HDR_IMAGE_TYPES, 'HDRImageType', 'HdrImageType'),
        ];
    }

    /**
     * Loads autofocus and focus-distance related fields.
     *
     * @param NativePlistDictionary $dictionary Decoded maker notes dictionary.
     *
     * @return array{
     *     afStable:?bool,
     *     afPerformance:?float,
     *     afMeasuredDepth:?float,
     *     afConfidence:?float,
     *     focusPosition:?float,
     *     focusDistanceRange:list<float>|null
     * }
     */
    private function loadFocusSection(array $dictionary): array
    {
        return [
            'afStable'           => $this->extractor->boolDictionaryValue($dictionary, 'AFStable'),
            'afPerformance'      => $this->extractor->rationalFloatValue($dictionary, 'AFPerformance'),
            'afMeasuredDepth'    => $this->extractor->floatValue($dictionary, 'AFMeasuredDepth'),
            'afConfidence'       => $this->extractor->floatValue($dictionary, 'AFConfidence'),
            'focusPosition'      => $this->extractor->floatValue($dictionary, 'FocusPosition'),
            'focusDistanceRange' => $this->extractor->focusDistanceRangeValue($dictionary),
        ];
    }

    /**
     * Loads semantic-style fields with compact semantic-style fallback behavior.
     *
     * @param NativePlistDictionary $dictionary           Decoded maker notes dictionary.
     * @param array|null            $semanticStyleCompact Compact semantic-style tuple from prior seeding.
     *
     * @phpstan-param array{0:?string,1:?float,2:?float}|null $semanticStyleCompact
     *
     * @return array{
     *     semanticStylePreset:?string,
     *     semanticStyleWarmth:?float,
     *     semanticStyleTone:?float
     * }
     */
    private function loadStyleSection(array $dictionary, ?array $semanticStyleCompact): array
    {
        $semanticStylePreset = $this->extractor->stringValue($dictionary, 'SemanticStylePreset');
        $semanticStyleWarmth = $this->extractor->floatValue($dictionary, 'SemanticStyleWarmth');
        $semanticStyleTone   = $this->extractor->floatValue($dictionary, 'SemanticStyleTone');

        if ($semanticStyleCompact === null) {
            $semanticStyleCompact = SemanticStyle::fromDictionary($dictionary);
        }

        if ($semanticStyleCompact !== null) {
            [
                'preset' => $semanticStylePreset,
                'warmth' => $semanticStyleWarmth,
                'tone'   => $semanticStyleTone,
            ] = SemanticStyle::mergeIntoFields($semanticStylePreset, $semanticStyleWarmth, $semanticStyleTone, $semanticStyleCompact);
        }

        return [
            'semanticStylePreset' => $semanticStylePreset,
            'semanticStyleWarmth' => $semanticStyleWarmth,
            'semanticStyleTone'   => $semanticStyleTone,
        ];
    }

    /**
     * Loads camera-capture related fields.
     *
     * @param NativePlistDictionary $dictionary Decoded maker notes dictionary.
     *
     * @return array{
     *     makerNoteVersion:?string,
     *     imageCaptureType:?string,
     *     qualityHint:?string,
     *     oisMode:?string,
     *     colorTemperature:?int,
     *     colorCorrectionMatrix:list<float>|null
     * }
     */
    private function loadCameraSection(array $dictionary): array
    {
        return [
            'makerNoteVersion'      => $this->extractor->makerNoteVersionValue($dictionary, 'MakerNoteVersion'),
            'imageCaptureType'      => $this->extractor->enumeratedStringValue($dictionary, AppleMaps::IMAGE_CAPTURE_TYPES, 'ImageCaptureType'),
            'qualityHint'           => $this->extractor->stringOrNumericValue($dictionary, 'QualityHint'),
            'oisMode'               => $this->extractor->stringOrNumericValue($dictionary, 'OISMode'),
            'colorTemperature'      => $this->extractor->intValue($dictionary, 'ColorTemperature'),
            'colorCorrectionMatrix' => $this->extractor->floatList($dictionary, 'ColorCorrectionMatrix'),
        ];
    }

    /**
     * Returns whether at least one maker-notes section contains data.
     *
     * @param ?AppleCaptureIdentity $identity     Capture identity section.
     * @param ?AppleHdr             $hdr          HDR section.
     * @param ?AppleAutoExposure    $autoExposure Auto-exposure section.
     * @param ?AppleAutoFocus       $autoFocus    Autofocus section.
     * @param ?AppleNoise           $noise        Noise section.
     * @param ?AppleSemanticStyle   $style        Semantic style section.
     * @param ?AppleLivePhoto       $livePhoto    Live photo section.
     * @param ?AppleCameraCapture   $camera       Camera capture section.
     * @param array<string, bool>   $flags        Extracted boolean flags.
     *
     * @return bool True when at least one section contains data, otherwise false.
     */
    private function hasAnySectionData(
        ?AppleCaptureIdentity $identity,
        ?AppleHdr $hdr,
        ?AppleAutoExposure $autoExposure,
        ?AppleAutoFocus $autoFocus,
        ?AppleNoise $noise,
        ?AppleSemanticStyle $style,
        ?AppleLivePhoto $livePhoto,
        ?AppleCameraCapture $camera,
        array $flags,
    ): bool {
        return $identity instanceof AppleCaptureIdentity
            || ($hdr instanceof AppleHdr)
            || ($autoExposure instanceof AppleAutoExposure)
            || ($autoFocus instanceof AppleAutoFocus)
            || ($noise instanceof AppleNoise)
            || ($style instanceof AppleSemanticStyle)
            || ($livePhoto instanceof AppleLivePhoto)
            || ($camera instanceof AppleCameraCapture)
            || ($flags !== []);
    }
}
