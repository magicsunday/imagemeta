<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Curate\Exif\SubFactory;

use MagicSunday\ImageMeta\MakerNotes\Apple\AppleMakerNotes;
use MagicSunday\ImageMeta\MakerNotes\Apple\Support\QuickTimeLookup;
use MagicSunday\ImageMeta\Model\Exif\ParsedExif;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Model\QuickTimeMeta;
use MagicSunday\ImageMeta\Value\Scene;

use function str_starts_with;
use function strtoupper;
use function trim;

/**
 * Factory for creating Scene value objects from EXIF, QuickTime and Apple metadata.
 */
final readonly class SceneFactory implements SubFactoryInterface
{
    /**
     * Creates a Scene value object from EXIF, QuickTime and Apple metadata.
     *
     * @param Metadata $metadata Metadata container with decoded EXIF, XMP and QuickTime data.
     *
     * @return Scene Scene metadata value object.
     */
    public function create(Metadata $metadata): Scene
    {
        $exif           = $metadata->exifDoc;
        $quickTime      = $metadata->quickTime;
        $appleMakerNote = $metadata->makerNotes?->apple ?? $this->emptyAppleMakerNotes();

        return $this->buildScene($exif, $quickTime, $appleMakerNote, null);
    }

    /**
     * Builds the scene metadata aggregate using EXIF, QuickTime and Apple sources.
     *
     * @param ParsedExif|null    $exif      Resolver exposing EXIF scene metadata.
     * @param QuickTimeMeta|null $quickTime QuickTime metadata providing scene hints.
     * @param AppleMakerNotes    $apple     Aggregated Apple maker note metadata.
     * @param int|null           $faceCount Number of detected face regions.
     *
     * @return Scene Scene metadata value object.
     */
    private function buildScene(
        ?ParsedExif $exif,
        ?QuickTimeMeta $quickTime,
        AppleMakerNotes $apple,
        ?int $faceCount,
    ): Scene {
        $appleFlags = $apple->flags;

        $lookup = new QuickTimeLookup($quickTime);

        $hdrLabel = $apple->hdrImageType;
        if ($hdrLabel === null) {
            $hdrLabel = $lookup->string('HDRImageType');
        }

        $nightMode = $lookup->bool('NightMode');
        if ($nightMode === null) {
            $nightMode = $this->appleFlag($appleFlags, 'nightMode');
        }

        $hdrScene = null;

        if ($hdrLabel !== null && $this->isHdrSceneLabel($hdrLabel)) {
            $hdrScene = true;
        }

        if ($hdrScene === null) {
            $hdrHeadroom = $apple->hdrHeadroom;
            if ($hdrHeadroom !== null && $hdrHeadroom > 0.0) {
                $hdrScene = true;
            } elseif (
                $this->appleFlag($appleFlags, 'hdrEnabled') === true
                || $this->appleFlag($appleFlags, 'hdrAuto') === true
            ) {
                $hdrScene = true;
            }
        }

        return new Scene(
            type: $exif?->sceneCaptureType(),
            sceneType: $exif?->sceneType(),
            light: $exif?->lightSource(),
            faceCount: $faceCount,
            hdrScene: $hdrScene,
            nightMode: $nightMode,
            subjectDistanceRange: $exif?->subjectDistanceRange(),
        );
    }

    /**
     * Determines whether the supplied label denotes an HDR scene mode.
     *
     * Apple devices record the HDR scene state as free-form strings such as
     * "HDR" or "HDR+". The check therefore normalises the label to uppercase
     * and considers every value that starts with "HDR" as an affirmative
     * indicator.
     */
    private function isHdrSceneLabel(string $label): bool
    {
        $normalized = strtoupper(trim($label));

        return str_starts_with($normalized, 'HDR');
    }

    /**
     * Extracts a boolean flag from the Apple maker note flag map.
     *
     * @param array<string, bool> $flags Normalised Apple maker note flag map.
     * @param string              $key   Name of the flag to resolve.
     *
     * @return bool|null Resolved boolean flag or null when the flag is absent.
     */
    private function appleFlag(array $flags, string $key): ?bool
    {
        return $flags[$key] ?? null;
    }

    private function emptyAppleMakerNotes(): AppleMakerNotes
    {
        return new AppleMakerNotes(
            contentIdentifier: null,
            cameraType: null,
            hdrHeadroom: null,
            hdrGain: null,
            snr: null,
            aeStable: null,
            aeTarget: null,
            aeAverage: null,
            afStable: null,
            afPerformance: null,
            signalToNoiseRatioType: null,
            luminanceNoiseAmplitude: null,
            focusPosition: null,
            livePhotoIndex: null,
            colorTemperature: null,
            semanticStylePreset: null,
            semanticStyleWarmth: null,
            semanticStyleTone: null,
            flags: [],
            accelerationVector: null,
        );
    }
}
