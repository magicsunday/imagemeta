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
     * @param int|null                                                    $samplesPerPixel             Number of samples per pixel.
     * @param int|null                                                    $bitsPerSample               Bits per sample reported for the image.
     * @param int|null                                                    $rowsPerStrip                Number of rows per TIFF strip.
     * @param int|null                                                    $tileWidth                   Width of an individual tile when tiling is used.
     * @param int|null                                                    $tileLength                  Length of an individual tile when tiling is used.
     * @param Compression|null                                            $compression                 Compression method used for pixel data.
     * @param Photometric|null                                            $photometric                 Photometric interpretation of the samples.
     * @param PlanarConfiguration|null                                    $planar                      Planar configuration for multi-sample data.
     * @param ResolutionUnit|null                                         $resolutionUnit              Resolution unit for X/Y values.
     * @param float|null                                                  $xResolution                 Horizontal resolution in the reported unit.
     * @param float|null                                                  $yResolution                 Vertical resolution in the reported unit.
     * @param YCbCrPositioning|null                                       $ycbcrPos                    Chroma positioning relative to luma samples.
     * @param array{0:int,1:int}|null                                     $ycbcrSubSampling            Horizontal/vertical chroma subsampling factors.
     * @param array{0:float,1:float,2:float}|null                         $ycbcrCoefficients           Luma coefficients for YCbCr conversions.
     * @param array{0:float,1:float}|null                                 $whitePoint                  Normalised white point (x, y).
     * @param array{0:float,1:float,2:float,3:float,4:float,5:float}|null $primaryChromaticities       Primary chromaticities ordered as R,G,B.
     * @param list<int>|null                                              $stripOffsets                File offsets for TIFF strips.
     * @param list<int>|null                                              $stripByteCounts             Byte counts for each TIFF strip.
     * @param list<int>|null                                              $tileOffsets                 File offsets for TIFF tiles.
     * @param list<int>|null                                              $tileByteCounts              Byte counts for each TIFF tile.
     * @param list<int>|null                                              $transferFunction            Transfer function lookup table.
     * @param int|null                                                    $jpegInterchangeFormat       Offset to the JPEG interchange stream.
     * @param int|null                                                    $jpegInterchangeFormatLength Byte length of the JPEG interchange stream.
     * @param array{0:float,1:float,2:float,3:float,4:float,5:float}|null $referenceBlackWhite         Reference black and white point values.
     * @param string|null                                                 $copyright                   Copyright notice embedded in EXIF.
     */
    public function __construct(
        public readonly ?int $samplesPerPixel,
        public readonly ?int $bitsPerSample,
        public readonly ?int $rowsPerStrip,
        public readonly ?int $tileWidth,
        public readonly ?int $tileLength,
        public readonly ?Compression $compression,
        public readonly ?Photometric $photometric,
        public readonly ?PlanarConfiguration $planar,
        public readonly ?ResolutionUnit $resolutionUnit,
        public readonly ?float $xResolution,
        public readonly ?float $yResolution,
        public readonly ?YCbCrPositioning $ycbcrPos,
        public readonly ?array $ycbcrSubSampling,
        public readonly ?array $ycbcrCoefficients,
        public readonly ?array $whitePoint,
        public readonly ?array $primaryChromaticities,
        public readonly ?array $stripOffsets,
        public readonly ?array $stripByteCounts,
        public readonly ?array $tileOffsets,
        public readonly ?array $tileByteCounts,
        public readonly ?array $transferFunction,
        public readonly ?int $jpegInterchangeFormat,
        public readonly ?int $jpegInterchangeFormatLength,
        public readonly ?array $referenceBlackWhite,
        public readonly ?string $copyright,
    ) {
    }
}
