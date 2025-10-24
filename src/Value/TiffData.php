<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value;

use MagicSunday\ImageMeta\Value\Enum\Compression;
use MagicSunday\ImageMeta\Value\Enum\Photometric;
use MagicSunday\ImageMeta\Value\Enum\PlanarConfiguration;
use MagicSunday\ImageMeta\Value\Enum\ResolutionUnit;
use MagicSunday\ImageMeta\Value\Enum\YCbCrPositioning;

/**
 * Captures low level TIFF image structure characteristics.
 */
final readonly class TiffData
{
    /**
     * @param int|null                                                    $samplesPerPixel       Number of samples per pixel.
     * @param int|null                                                    $rowsPerStrip          Number of rows per TIFF strip.
     * @param Compression|null                                            $compression           Compression method used for pixel data.
     * @param Photometric|null                                            $photometric           Photometric interpretation of the samples.
     * @param PlanarConfiguration|null                                    $planar                Planar configuration for multi-sample data.
     * @param ResolutionUnit|null                                         $resolutionUnit        Resolution unit for X/Y values.
     * @param float|null                                                  $xResolution           Horizontal resolution in the reported unit.
     * @param float|null                                                  $yResolution           Vertical resolution in the reported unit.
     * @param YCbCrPositioning|null                                       $ycbcrPos              Chroma positioning relative to luma samples.
     * @param array{0:int,1:int}|null                                     $ycbcrSubSampling      Horizontal/vertical chroma subsampling factors.
     * @param array{0:float,1:float,2:float}|null                         $ycbcrCoefficients     Luma coefficients for YCbCr conversions.
     * @param array{0:float,1:float}|null                                 $whitePoint            Normalised white point (x, y).
     * @param array{0:float,1:float,2:float,3:float,4:float,5:float}|null $primaryChromaticities Primary chromaticities ordered as R,G,B.
     */
    public function __construct(
        public ?int $samplesPerPixel,
        public ?int $rowsPerStrip,
        public ?Compression $compression,
        public ?Photometric $photometric,
        public ?PlanarConfiguration $planar,
        public ?ResolutionUnit $resolutionUnit,
        public ?float $xResolution,
        public ?float $yResolution,
        public ?YCbCrPositioning $ycbcrPos,
        public ?array $ycbcrSubSampling,
        public ?array $ycbcrCoefficients,
        public ?array $whitePoint,
        public ?array $primaryChromaticities,
    ) {
    }
}
