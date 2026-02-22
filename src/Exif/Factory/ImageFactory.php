<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Exif\Factory;

use MagicSunday\ImageMeta\Exif\Model\ParsedExif;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;
use MagicSunday\ImageMeta\Model\Xmp\XmpNamespace;
use MagicSunday\ImageMeta\Value\Enum\ColorSpace;
use MagicSunday\ImageMeta\Value\Image;

use function is_string;
use function strtoupper;

/**
 * Factory for creating Image value objects from EXIF metadata.
 */
final readonly class ImageFactory
{
    /**
     * Creates an Image value object from EXIF metadata.
     *
     * @param Metadata         $metadata    Metadata container with decoded EXIF, XMP and QuickTime data.
     * @param XmpDocument|null $xmpDocument Parsed XMP document for title/description overrides.
     *
     * @return Image Normalised image metadata aggregate.
     */
    public function create(Metadata $metadata, ?XmpDocument $xmpDocument = null): Image
    {
        $exifDocument = $metadata->exifDoc;

        $width         = $exifDocument?->imageWidth() ?? $metadata->jpegFrameWidth;
        $height        = $exifDocument?->imageHeight() ?? $metadata->jpegFrameHeight;
        $orientation   = $exifDocument?->orientation();
        $bitsPerSample = $exifDocument?->bitsPerSample() ?? $metadata->jpegBitsPerSample;

        $xmpTitle       = $xmpDocument?->string(XmpNamespace::DC->value, 'title');
        $xmpHeadline    = $xmpDocument?->string(XmpNamespace::PHOTOSHOP->value, 'Headline');
        $xmpDescription = $xmpDocument?->string(XmpNamespace::DC->value, 'description');

        return new Image(
            width: $width,
            height: $height,
            orientation: $orientation,
            bitsPerSample: $bitsPerSample,
            colorSpace: $this->normalizedColorSpace($exifDocument),
            imageUniqueId: $exifDocument?->imageUniqueId(),
            documentName: $exifDocument?->documentName(),
            description: $xmpDescription ?? $exifDocument?->imageDescription(),
            title: $xmpTitle ?? $xmpHeadline ?? $exifDocument?->imageTitle(),
            componentsConfiguration: $exifDocument?->componentsConfiguration(),
            compressedBitsPerPixel: $exifDocument?->compressedBitsPerPixel(),
            userComment: $exifDocument?->userComment(),
            userCommentEncoding: $exifDocument?->userCommentEncodingBestEffort(),
        );
    }

    /**
     * Normalises the colour space based on interoperability metadata hints.
     *
     * @param ParsedExif|null $exifDocument EXIF document exposing colour space and interoperability tags.
     *
     * @return ColorSpace|null Normalised colour space enumeration or null when undefined.
     */
    private function normalizedColorSpace(?ParsedExif $exifDocument): ?ColorSpace
    {
        if (!$exifDocument instanceof ParsedExif) {
            return null;
        }

        $colorSpace = $exifDocument->colorSpace();

        if ($colorSpace !== ColorSpace::UNCALIBRATED) {
            return $colorSpace;
        }

        $interopIndex = $exifDocument->interopIndex();

        if (!is_string($interopIndex)) {
            return $colorSpace;
        }

        $normalizedInteropIndex = strtoupper($interopIndex);

        if ($normalizedInteropIndex === 'R98') {
            return ColorSpace::SRGB;
        }

        return $colorSpace;
    }
}
