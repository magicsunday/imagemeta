<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Factory\Structured;

use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\ParsedExif;
use MagicSunday\ImageMeta\Exif\Reconciliation\XmpFallbackResolver;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleMakerNotes;
use MagicSunday\ImageMeta\MakerNotes\Apple\Support\QuickTimeLookup;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Value\Enum\LightSource;
use MagicSunday\ImageMeta\Value\Enum\SceneCaptureType;
use MagicSunday\ImageMeta\Value\Enum\SceneType;
use MagicSunday\ImageMeta\Value\Enum\SubjectDistanceRange;
use MagicSunday\ImageMeta\Value\Scene;

use function str_starts_with;
use function strtoupper;
use function trim;

/**
 * Factory for creating Scene value objects from EXIF, QuickTime and Apple metadata.
 */
final readonly class SceneFactory
{
    /**
     * Creates a Scene value object from EXIF, QuickTime and Apple metadata.
     *
     * @param Metadata        $metadata  Metadata container with decoded EXIF, XMP and QuickTime data.
     * @param AppleMakerNotes $apple     Pre-resolved Apple maker note metadata.
     * @param int|null        $faceCount Optional number of detected face regions.
     *
     * @return Scene Scene metadata value object.
     */
    public function create(Metadata $metadata, AppleMakerNotes $apple, ?int $faceCount = null): Scene
    {
        $exifDocument    = $metadata->exifDoc;
        $quickTimeLookup = $metadata->quickTimeLookup();
        $resolver        = XmpFallbackResolver::fromMetadata($metadata);

        return $this->buildScene($exifDocument, $quickTimeLookup, $apple, $faceCount, $resolver);
    }

    /**
     * Builds the scene metadata aggregate using EXIF, QuickTime and Apple sources.
     *
     * @param ParsedExif|null          $exifDocument Resolver exposing EXIF scene metadata.
     * @param QuickTimeLookup          $lookup       QuickTime metadata lookup for scene hints.
     * @param AppleMakerNotes          $apple        Aggregated Apple maker note metadata.
     * @param int|null                 $faceCount    Number of detected face regions.
     * @param XmpFallbackResolver|null $resolver     XMP fallback resolver for enum lookups.
     *
     * @return Scene Scene metadata value object.
     */
    private function buildScene(
        ?ParsedExif $exifDocument,
        QuickTimeLookup $lookup,
        AppleMakerNotes $apple,
        ?int $faceCount,
        ?XmpFallbackResolver $resolver = null,
    ): Scene {
        $appleFlags = $apple->flags;
        $hdrLabel   = $apple->hdr?->imageType;

        if ($hdrLabel === null) {
            $hdrLabel = $lookup->string('HDRImageType');
        }

        $nightMode = $lookup->bool('NightMode');

        if ($nightMode === null) {
            $nightMode = $appleFlags['nightMode'] ?? null;
        }

        $hdrScene = null;

        if (($hdrLabel !== null) && $this->isHdrSceneLabel($hdrLabel)) {
            $hdrScene = true;
        }

        if ($hdrScene === null) {
            $hdrHeadroom = $apple->hdr?->headroom;

            if (($hdrHeadroom !== null) && ($hdrHeadroom > 0.0)) {
                $hdrScene = true;
            } elseif (($appleFlags['hdrEnabled'] ?? null) === true || ($appleFlags['hdrAuto'] ?? null) === true) {
                $hdrScene = true;
            }
        }

        return new Scene(
            type: $exifDocument?->sceneCaptureType() ?? $resolver?->enum(ExifTag::SCENE_CAPTURE_TYPE, SceneCaptureType::class),
            sceneType: $exifDocument?->sceneType() ?? $resolver?->enum(ExifTag::SCENE_TYPE, SceneType::class),
            light: $exifDocument?->lightSource() ?? $resolver?->enum(ExifTag::LIGHT_SOURCE, LightSource::class),
            faceCount: $faceCount,
            hdrScene: $hdrScene,
            nightMode: $nightMode,
            subjectDistanceRange: $exifDocument?->subjectDistanceRange() ?? $resolver?->enum(ExifTag::SUBJECT_DISTANCE_RANGE, SubjectDistanceRange::class),
        );
    }

    /**
     * Determines whether the supplied label denotes an HDR scene mode.
     *
     * Apple devices record the HDR scene state as free-form strings such as
     * "HDR" or "HDR+". The check therefore normalizes the label to uppercase
     * and considers every value that starts with "HDR" as an affirmative
     * indicator.
     */
    private function isHdrSceneLabel(string $label): bool
    {
        $normalized = strtoupper(trim($label));

        return str_starts_with($normalized, 'HDR');
    }
}
