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
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Model\QuickTime\QuickTimeMeta;
use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;
use MagicSunday\ImageMeta\Model\Xmp\XmpNamespace;
use MagicSunday\ImageMeta\Value\Enum\ColorSpace;
use MagicSunday\ImageMeta\Value\Enum\Orientation;
use MagicSunday\ImageMeta\Value\Image;
use MagicSunday\ImageMeta\Value\UserComment;

use function is_string;
use function strtoupper;

/**
 * Factory for creating Image value objects from EXIF metadata with XMP fallback.
 *
 * Falls back to XMP properties per CIPA DC-X010-2017 Tables 8-9 and 14 when EXIF tags are absent.
 */
final readonly class ImageFactory
{
    /**
     * Creates an Image value object from EXIF metadata with XMP fallback.
     *
     * @param Metadata         $metadata    Metadata container with decoded EXIF, XMP and QuickTime data.
     * @param XmpDocument|null $xmpDocument Parsed XMP document for title/description overrides.
     *
     * @return Image Normalized image metadata aggregate.
     */
    public function create(Metadata $metadata, ?XmpDocument $xmpDocument = null): Image
    {
        $exifDocument    = $metadata->exifDoc;
        $resolverDoc     = $xmpDocument ?? $metadata->xmpDoc ?? $metadata->selectiveXmpDocument();
        $resolver        = $resolverDoc instanceof XmpDocument ? XmpFallbackResolver::fromDocument($resolverDoc) : null;
        $quickTimeLookup = $metadata->quickTimeLookup();

        $width         = $exifDocument?->imageWidth() ?? $metadata->jpegFrameWidth ?? $resolver?->int(ExifTag::PIXEL_X_DIMENSION) ?? $quickTimeLookup->int(QuickTimeMeta::VIDEO_WIDTH_KEY) ?? $metadata->riffAviHeader?->width;
        $height        = $exifDocument?->imageHeight() ?? $metadata->jpegFrameHeight ?? $resolver?->int(ExifTag::PIXEL_Y_DIMENSION) ?? $quickTimeLookup->int(QuickTimeMeta::VIDEO_HEIGHT_KEY) ?? $metadata->riffAviHeader?->height;
        $orientation   = $exifDocument?->orientation() ?? $this->rotationToOrientation($quickTimeLookup->int(QuickTimeMeta::ROTATION_KEY));
        $bitsPerSample = $exifDocument?->bitsPerSample() ?? $metadata->jpegBitsPerSample ?? $quickTimeLookup->int(QuickTimeMeta::VIDEO_BIT_DEPTH_KEY);

        $xmpTitle       = $xmpDocument?->string(XmpNamespace::DC->value, 'title');
        $xmpHeadline    = $xmpDocument?->string(XmpNamespace::PHOTOSHOP->value, 'Headline');
        $xmpDescription = $xmpDocument?->string(XmpNamespace::DC->value, 'description');

        return new Image(
            width: $width,
            height: $height,
            orientation: $orientation,
            bitsPerSample: $bitsPerSample,
            colorSpace: $this->normalizedColorSpace($exifDocument, $resolver),
            imageUniqueId: $exifDocument?->imageUniqueId() ?? $resolver?->string(ExifTag::IMAGE_UNIQUE_ID),
            documentName: $exifDocument?->documentName(),
            description: $xmpDescription ?? $exifDocument?->imageDescription(),
            title: $xmpTitle ?? $xmpHeadline ?? $exifDocument?->imageTitle(),
            componentsConfiguration: $exifDocument?->componentsConfiguration(),
            compressedBitsPerPixel: $exifDocument?->compressedBitsPerPixel(),
            comment: new UserComment(
                value: $exifDocument?->userComment(),
                encoding: $exifDocument?->userCommentEncodingBestEffort(),
            ),
        );
    }

    /**
     * Maps a QuickTime rotation angle in degrees to an EXIF orientation value.
     *
     * Only the four cardinal rotations (0, 90, 180, 270) are mapped.
     * Any other value, including null, returns null.
     *
     * @param int|null $rotation Clockwise rotation in degrees from the QuickTime track header.
     *
     * @return Orientation|null Corresponding EXIF orientation or null when unmapped.
     */
    private function rotationToOrientation(?int $rotation): ?Orientation
    {
        return match ($rotation) {
            0       => Orientation::TopLeft,
            90      => Orientation::RightTop,
            180     => Orientation::BottomRight,
            270     => Orientation::LeftBottom,
            default => null,
        };
    }

    /**
     * Normalizes the colour space based on interoperability metadata hints with XMP fallback.
     *
     * @param ParsedExif|null          $exifDocument EXIF document exposing colour space and interoperability tags.
     * @param XmpFallbackResolver|null $resolver     XMP fallback resolver for colour space lookup.
     *
     * @return ColorSpace|null Normalized colour space enumeration or null when undefined.
     */
    private function normalizedColorSpace(?ParsedExif $exifDocument, ?XmpFallbackResolver $resolver = null): ?ColorSpace
    {
        if (!$exifDocument instanceof ParsedExif) {
            return $resolver?->enum(ExifTag::COLOR_SPACE, ColorSpace::class);
        }

        $colorSpace = $exifDocument->colorSpace();

        if ($colorSpace !== ColorSpace::Uncalibrated) {
            return $colorSpace;
        }

        $interopIndex = $exifDocument->interopIndex();

        if (!is_string($interopIndex)) {
            return $colorSpace;
        }

        $normalizedInteropIndex = strtoupper($interopIndex);

        if ($normalizedInteropIndex === 'R98') {
            return ColorSpace::Srgb;
        }

        return $colorSpace;
    }
}
