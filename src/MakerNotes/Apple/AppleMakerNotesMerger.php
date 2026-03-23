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
        if (($makerNotes instanceof MakerNotesRecord) && ($makerNotes->vendor !== 'Apple')) {
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

        if (($contentIdentifier === null) && ($quickTime instanceof QuickTimeMeta)) {
            $contentIdentifier = $quickTime->contentIdentifier();
        }

        $imageCaptureRequestId = $makerNotes?->identity?->imageCaptureRequestId;

        if ($imageCaptureRequestId === null) {
            $imageCaptureRequestId = $lookup->string('ImageCaptureRequestID');
        }

        $burstUuid       = $this->preferMakerString($makerNotes?->identity?->burstUuid, $lookup, 'BurstUUID');
        $imageUniqueId   = $this->preferMakerString($makerNotes?->identity?->imageUniqueId, $lookup, 'ImageUniqueID');
        $photoIdentifier = $this->preferMakerString($makerNotes?->identity?->photoIdentifier, $lookup, 'PhotoIdentifier');
        $mediaGroupUuid  = $this->preferMakerString($makerNotes?->identity?->mediaGroupUuid, $lookup, 'MediaGroupUUID');

        $hdrHeadroom  = $this->preferMakerFloat($makerNotes?->hdr?->headroom, $lookup, 'HdrHeadroom', 'HDRHeadroom');
        $hdrGain      = $this->preferMakerFloatList($makerNotes?->hdr?->gain, $lookup, 'HdrGain', 'HDRGain');
        $hdrImageType = $this->preferMakerEnumerated($makerNotes?->hdr?->imageType, $lookup, AppleMaps::HDR_IMAGE_TYPES, 'HDRImageType', 'HdrImageType');

        $snr = $this->preferMakerFloat($makerNotes?->noise?->snr, $lookup, 'SNRSetting', 'SNR');

        $focusPosition = $this->preferMakerFloat($makerNotes?->autoFocus?->focusPosition, $lookup, 'FocusPosition');

        $focusDistanceRange = $makerNotes?->autoFocus?->focusDistanceRange;

        if ($focusDistanceRange === null) {
            $focusDistanceRange = $this->quickTimeFocusDistanceRange($lookup);
        }

        $afMeasuredDepth = $this->preferMakerFloat($makerNotes?->autoFocus?->measuredDepth, $lookup, 'AFMeasuredDepth');
        $afConfidence    = $this->preferMakerFloat($makerNotes?->autoFocus?->confidence, $lookup, 'AFConfidence');

        $livePhotoIndex = $this->preferMakerInt($makerNotes?->livePhoto?->index, $lookup, 'LivePhotoVideoIndex', 'LivePhotoMovieIndex');

        $livePhotoTime = $makerNotes?->livePhoto?->time;

        $accelerationVector = $this->preferMakerFloatList($makerNotes?->livePhoto?->accelerationVector, $lookup, 'AccelerationVector');

        $cameraType            = $this->preferMakerStringOrInt($makerNotes?->camera?->type, $lookup, 'CameraType');
        $colorTemperature      = $this->preferMakerInt($makerNotes?->camera?->colorTemperature, $lookup, 'ColorTemperature');
        $qualityHint           = $this->preferMakerStringOrNumeric($makerNotes?->camera?->qualityHint, $lookup, 'QualityHint');
        $colorCorrectionMatrix = $this->preferMakerFloatList($makerNotes?->camera?->colorCorrectionMatrix, $lookup, 'ColorCorrectionMatrix');
        $makerNoteVersion      = $this->preferMakerString($makerNotes?->camera?->makerNoteVersion, $lookup, 'MakerNoteVersion');
        $oisMode               = $this->preferMakerStringOrNumeric($makerNotes?->camera?->oisMode, $lookup, 'OISMode');
        $imageCaptureType      = $this->preferMakerEnumerated($makerNotes?->camera?->imageCaptureType, $lookup, AppleMaps::IMAGE_CAPTURE_TYPES, 'ImageCaptureType');

        $semanticPreset = $this->preferMakerString($makerNotes?->semanticStyle?->preset, $lookup, 'SemanticStylePreset');
        $semanticWarmth = $this->preferMakerFloat($makerNotes?->semanticStyle?->warmth, $lookup, 'SemanticStyleWarmth');
        $semanticTone   = $this->preferMakerFloat($makerNotes?->semanticStyle?->tone, $lookup, 'SemanticStyleTone');

        $semanticStyleComposite = SemanticStyle::fromQuickTime($quickTime);

        if ($semanticStyleComposite !== null) {
            [
                'preset' => $semanticPreset,
                'warmth' => $semanticWarmth,
                'tone'   => $semanticTone,
            ] = SemanticStyle::mergeIntoFields($semanticPreset, $semanticWarmth, $semanticTone, $semanticStyleComposite);
        }

        $flags = $this->normalizeFlags($makerNotes?->flags, $this->quickTimeFlags($quickTime));

        $identity = AppleCaptureIdentity::createIfPresent($contentIdentifier, $imageCaptureRequestId, $burstUuid, $imageUniqueId, $photoIdentifier, $mediaGroupUuid);

        $hdr = AppleHdr::createIfPresent($hdrHeadroom, $hdrGain, $hdrImageType);

        $autoExposure = $makerNotes?->autoExposure;

        $autoFocus = (($makerNotes?->autoFocus?->stable !== null) || ($makerNotes?->autoFocus?->performance !== null)
            || ($afMeasuredDepth !== null) || ($afConfidence !== null) || ($focusPosition !== null) || ($focusDistanceRange !== null))
            ? new AppleAutoFocus($makerNotes?->autoFocus?->stable, $makerNotes?->autoFocus?->performance, $afMeasuredDepth, $afConfidence, $focusPosition, $focusDistanceRange)
            : null;

        $noise = (($snr !== null) || ($makerNotes?->noise?->signalToNoiseRatioType !== null)
            || ($makerNotes?->noise?->luminanceAmplitude !== null))
            ? new AppleNoise($snr, $makerNotes?->noise?->signalToNoiseRatioType, $makerNotes?->noise?->luminanceAmplitude)
            : null;

        $style = (($semanticPreset !== null) || ($semanticWarmth !== null) || ($semanticTone !== null))
            ? new AppleSemanticStyle($semanticPreset, $semanticWarmth, $semanticTone)
            : null;

        $livePhoto = (($livePhotoIndex !== null) || ($livePhotoTime !== null)
            || ($makerNotes?->livePhoto?->runTime instanceof RunTime) || ($accelerationVector !== null))
            ? new AppleLivePhoto($livePhotoIndex, $livePhotoTime, $makerNotes?->livePhoto?->runTime, $accelerationVector)
            : null;

        $camera = (($cameraType !== null) || ($imageCaptureType !== null) || ($makerNoteVersion !== null)
            || ($qualityHint !== null) || ($oisMode !== null) || ($colorTemperature !== null) || ($colorCorrectionMatrix !== null))
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
     * Keeps a maker-note string value when present, otherwise resolves QuickTime fallback keys.
     */
    private function preferMakerString(?string $makerValue, QuickTimeLookup $lookup, string ...$keys): ?string
    {
        return $makerValue ?? $lookup->string(...$keys);
    }

    /**
     * Keeps a maker-note string/int value when present, otherwise resolves QuickTime string fallback keys.
     */
    private function preferMakerStringOrInt(string|int|null $makerValue, QuickTimeLookup $lookup, string ...$keys): string|int|null
    {
        return $makerValue ?? $lookup->string(...$keys);
    }

    /**
     * Keeps a maker-note integer value when present, otherwise resolves QuickTime fallback keys.
     */
    private function preferMakerInt(?int $makerValue, QuickTimeLookup $lookup, string ...$keys): ?int
    {
        return $makerValue ?? $lookup->int(...$keys);
    }

    /**
     * Keeps a maker-note float value when present, otherwise resolves QuickTime fallback keys.
     */
    private function preferMakerFloat(?float $makerValue, QuickTimeLookup $lookup, string ...$keys): ?float
    {
        return $makerValue ?? $lookup->float(...$keys);
    }

    /**
     * Keeps a maker-note float list when present, otherwise resolves QuickTime fallback keys.
     *
     * @param list<float>|null $makerValue Existing maker-note value.
     * @param string           ...$keys    Ordered QuickTime fallback keys.
     *
     * @return list<float>|null
     */
    private function preferMakerFloatList(?array $makerValue, QuickTimeLookup $lookup, string ...$keys): ?array
    {
        return $makerValue ?? $this->quickTimeFloatList($lookup, ...$keys);
    }

    /**
     * Keeps a maker-note value when present, otherwise resolves QuickTime string/int/float fallback keys.
     */
    private function preferMakerStringOrNumeric(?string $makerValue, QuickTimeLookup $lookup, string ...$keys): ?string
    {
        return $makerValue ?? $this->quickTimeStringOrNumeric($lookup, ...$keys);
    }

    /**
     * Normalizes maker-note enumerated values and falls back to QuickTime enumerations.
     *
     * @param string|null        $makerValue Existing maker-note value.
     * @param array<int, string> $map        Mapping from numeric codes to labels.
     * @param string             ...$keys    Ordered QuickTime fallback keys.
     */
    private function preferMakerEnumerated(?string $makerValue, QuickTimeLookup $lookup, array $map, string ...$keys): ?string
    {
        return $this->normalizeEnumerated($makerValue, $map) ?? $this->quickTimeEnumerated($lookup, $map, ...$keys);
    }

    /**
     * Normalizes null maker-note flags to an empty array and merges missing QuickTime flags.
     *
     * @param array<string, bool>|null $makerFlags Existing maker-note flags.
     * @param array<string, bool>      $quickFlags Derived QuickTime flags.
     *
     * @return array<string, bool>
     */
    private function normalizeFlags(?array $makerFlags, array $quickFlags): array
    {
        $flags = $makerFlags ?? [];

        foreach ($quickFlags as $key => $value) {
            if (!array_key_exists($key, $flags)) {
                $flags[$key] = $value;
            }
        }

        return $flags;
    }

    /**
     * Determines whether the Apple maker notes contain any populated values.
     */
    private function hasAppleData(AppleMakerNotes $apple): bool
    {
        $values = get_object_vars($apple);

        if (array_key_exists('flags', $values) && ($values['flags'] !== [])) {
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
