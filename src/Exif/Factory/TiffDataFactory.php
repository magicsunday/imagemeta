<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Exif\Factory;

use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Value\TiffColorRef;
use MagicSunday\ImageMeta\Value\TiffData;
use MagicSunday\ImageMeta\Value\TiffLayout;
use MagicSunday\ImageMeta\Value\TiffStructure;

use function count;

/**
 * Builds TIFF-level metadata from EXIF document and JPEG frame data.
 */
final readonly class TiffDataFactory
{
    /**
     * Creates TIFF-level metadata from the supplied metadata container.
     *
     * @param Metadata $metadata Source metadata container.
     *
     * @return TiffData TIFF data value object.
     */
    public function create(Metadata $metadata): TiffData
    {
        $exifDocument        = $metadata->exifDoc;

        $bitsPerSample       = $exifDocument?->bitsPerSample() ?? $metadata->jpegBitsPerSample;
        $ycbcrSubSampling    = $exifDocument?->ycbcrSubSampling() ?? $metadata->jpegYCbCrSubSampling;

        $referenceBlackWhite = $exifDocument?->referenceBlackWhite();

        if ($referenceBlackWhite !== null) {
            if (count($referenceBlackWhite) === 6) {
                /** @var array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $referenceBlackWhite */
                $referenceBlackWhite = [
                    0 => $referenceBlackWhite[0],
                    1 => $referenceBlackWhite[1],
                    2 => $referenceBlackWhite[2],
                    3 => $referenceBlackWhite[3],
                    4 => $referenceBlackWhite[4],
                    5 => $referenceBlackWhite[5],
                ];
            } else {
                $referenceBlackWhite = null;
            }
        }

        $structure           = new TiffStructure(
            samplesPerPixel: $exifDocument?->samplesPerPixel(),
            bitsPerSample: $bitsPerSample,
            compression: $exifDocument?->compression(),
            photometric: $exifDocument?->photometric(),
            planar: $exifDocument?->planarConfiguration(),
        );

        $color               = new TiffColorRef(
            ycbcrPos: $exifDocument?->ycbcrPositioning(),
            ycbcrSubSampling: $ycbcrSubSampling,
            ycbcrCoefficients: $exifDocument?->ycbcrCoefficients(),
            whitePoint: $exifDocument?->whitePoint(),
            primaryChromaticities: $exifDocument?->primaryChromaticities(),
            referenceBlackWhite: $referenceBlackWhite,
            transferFunction: $exifDocument?->transferFunction(),
        );

        $layout              = new TiffLayout(
            rowsPerStrip: $exifDocument?->rowsPerStrip(),
            stripOffsets: $exifDocument?->stripOffsets(),
            stripByteCounts: $exifDocument?->stripByteCounts(),
            tileWidth: $exifDocument?->tileWidth(),
            tileLength: $exifDocument?->tileLength(),
            tileOffsets: $exifDocument?->tileOffsets(),
            tileByteCounts: $exifDocument?->tileByteCounts(),
            jpegInterchangeFormat: $exifDocument?->jpegInterchangeFormat(),
            jpegInterchangeFormatLength: $exifDocument?->jpegInterchangeFormatLength(),
        );

        return new TiffData(
            structure: $structure,
            color: $color,
            layout: $layout,
            resolutionUnit: $exifDocument?->resolutionUnit(),
            xResolution: $exifDocument?->xResolution(),
            yResolution: $exifDocument?->yResolution(),
            copyright: $exifDocument?->copyright(),
        );
    }
}
