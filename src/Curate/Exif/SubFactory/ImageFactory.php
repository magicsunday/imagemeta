<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Curate\Exif\SubFactory;

use MagicSunday\ImageMeta\Model\Exif\ParsedExif;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Value\Enum\ColorSpace;
use MagicSunday\ImageMeta\Value\Image;

use function is_string;
use function strtoupper;

/**
 * Factory for creating Image value objects from EXIF metadata.
 */
final readonly class ImageFactory implements SubFactoryInterface
{
    /**
     * Creates an Image value object from EXIF metadata.
     *
     * @param Metadata $metadata Metadata container with decoded EXIF, XMP and QuickTime data.
     *
     * @return Image Normalised image metadata aggregate.
     */
    public function create(Metadata $metadata): Image
    {
        $exifDocument = $metadata->exifDoc;

        $width  = $exifDocument?->imageWidth() ?? $metadata->jpegFrameWidth;
        $height = $exifDocument?->imageHeight() ?? $metadata->jpegFrameHeight;

        $orientation = $exifDocument?->orientation();

        $bitsPerSample = $exifDocument?->bitsPerSample();
        if ($bitsPerSample === null) {
            $bitsPerSample = $metadata->jpegBitsPerSample;
        }

        return new Image(
            width: $width,
            height: $height,
            orientation: $orientation,
            bitsPerSample: $bitsPerSample,
            colorSpace: $this->normalizedColorSpace($exifDocument),
            imageUniqueId: $exifDocument?->imageUniqueId(),
            documentName: $exifDocument?->documentName(),
            description: $exifDocument?->imageDescription(),
            title: $exifDocument?->imageTitle(),
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
        if ($exifDocument === null) {
            return null;
        }

        $colorSpace = $exifDocument->colorSpace();

        if ($colorSpace === ColorSpace::UNCALIBRATED) {
            $interopIndex = $exifDocument->interopIndex();
            if (is_string($interopIndex)) {
                $normalizedInteropIndex = strtoupper($interopIndex);
                if ($normalizedInteropIndex === 'R03') {
                    return ColorSpace::ADOBE_RGB;
                }

                if ($normalizedInteropIndex === 'R98') {
                    return ColorSpace::SRGB;
                }
            }
        }

        return $colorSpace;
    }
}
