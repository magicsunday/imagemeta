<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Exif\Factory;

use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\ParsedExif;
use MagicSunday\ImageMeta\Exif\Reconciliation\XmpFallbackResolver;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleMakerNotes;
use MagicSunday\ImageMeta\MakerNotes\Apple\Support\QuickTimeLookup;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Model\QuickTime\QuickTimeMeta;
use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;
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
     * @param Metadata $metadata  Metadata container with decoded EXIF, XMP and QuickTime data.
     * @param int|null $faceCount Optional number of detected face regions.
     *
     * @return Scene Scene metadata value object.
     */
    public function create(Metadata $metadata, ?int $faceCount = null): Scene
    {
        $exif           = $metadata->exifDoc;
        $quickTime      = $metadata->quickTime;
        $appleMakerNote = $metadata->makerNotes?->apple;
        $xmpDocument    = $metadata->xmpDoc ?? $metadata->selectiveXmpDocument();
        $resolver       = $xmpDocument instanceof XmpDocument ? XmpFallbackResolver::fromDocument($xmpDocument) : null;

        if (!$appleMakerNote instanceof AppleMakerNotes) {
            $appleMakerNote = AppleMakerNotes::empty();
        }

        return $this->buildScene($exif, $quickTime, $appleMakerNote, $faceCount, $resolver);
    }

    /**
     * Builds the scene metadata aggregate using EXIF, QuickTime and Apple sources.
     *
     * @param ParsedExif|null          $exif      Resolver exposing EXIF scene metadata.
     * @param QuickTimeMeta|null       $quickTime QuickTime metadata providing scene hints.
     * @param AppleMakerNotes          $apple     Aggregated Apple maker note metadata.
     * @param int|null                 $faceCount Number of detected face regions.
     * @param XmpFallbackResolver|null $resolver  XMP fallback resolver for enum lookups.
     *
     * @return Scene Scene metadata value object.
     */
    private function buildScene(
        ?ParsedExif $exif,
        ?QuickTimeMeta $quickTime,
        AppleMakerNotes $apple,
        ?int $faceCount,
        ?XmpFallbackResolver $resolver = null,
    ): Scene {
        $appleFlags = $apple->flags;
        $hdrLabel   = $apple->hdr?->imageType;
        $nightMode  = null;

        if ($quickTime instanceof QuickTimeMeta) {
            $lookup = new QuickTimeLookup($quickTime);

            if ($hdrLabel === null) {
                $hdrLabel = $lookup->string('HDRImageType');
            }

            $nightMode = $lookup->bool('NightMode');
        }

        if ($nightMode === null) {
            $nightMode = $this->appleFlag($appleFlags, 'nightMode');
        }

        $hdrScene = null;

        if (($hdrLabel !== null) && $this->isHdrSceneLabel($hdrLabel)) {
            $hdrScene = true;
        }

        if ($hdrScene === null) {
            $hdrHeadroom = $apple->hdr?->headroom;

            if (($hdrHeadroom !== null) && ($hdrHeadroom > 0.0)) {
                $hdrScene = true;
            } elseif ($this->appleFlag($appleFlags, 'hdrEnabled') === true || $this->appleFlag($appleFlags, 'hdrAuto') === true) {
                $hdrScene = true;
            }
        }

        return new Scene(
            type: $exif?->sceneCaptureType() ?? SceneCaptureType::tryFrom($resolver?->int(ExifTag::SCENE_CAPTURE_TYPE) ?? -1),
            sceneType: $exif?->sceneType() ?? SceneType::tryFrom($resolver?->int(ExifTag::SCENE_TYPE) ?? -1),
            light: $exif?->lightSource() ?? LightSource::tryFrom($resolver?->int(ExifTag::LIGHT_SOURCE) ?? -1),
            faceCount: $faceCount,
            hdrScene: $hdrScene,
            nightMode: $nightMode,
            subjectDistanceRange: $exif?->subjectDistanceRange() ?? SubjectDistanceRange::tryFrom($resolver?->int(ExifTag::SUBJECT_DISTANCE_RANGE) ?? -1),
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

    /**
     * Extracts a boolean flag from the Apple maker note flag map.
     *
     * @param array<string, bool> $flags Normalized Apple maker note flag map.
     * @param string              $key   Name of the flag to resolve.
     *
     * @return bool|null Resolved boolean flag or null when the flag is absent.
     */
    private function appleFlag(array $flags, string $key): ?bool
    {
        return $flags[$key] ?? null;
    }
}
