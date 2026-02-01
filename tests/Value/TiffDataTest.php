<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Value;

use MagicSunday\ImageMeta\Value\Enum\Compression;
use MagicSunday\ImageMeta\Value\Enum\Photometric;
use MagicSunday\ImageMeta\Value\Enum\ResolutionUnit;
use MagicSunday\ImageMeta\Value\TiffData;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the TiffData value object for TIFF image structure fields.
 * It verifies strip/tile dimensions, compression, and photometric enums are preserved.
 * The suite covers resolution units and X/Y resolution values.
 * This ensures TIFF-related metadata remains consistent for downstream usage.
 */
#[CoversClass(TiffData::class)]
final class TiffDataTest extends TestCase
{
    /**
     * Constructs a TiffData instance using strip-based image fields and resolution values.
     * Verifies the value object preserves the supplied image structure properties.
     *
     * @return void
     */
    #[Test]
    public function constructsWithBasicImageStructure(): void
    {
        $tiff = new TiffData(
            samplesPerPixel: 3,
            bitsPerSample: 8,
            rowsPerStrip: 64,
            tileWidth: null,
            tileLength: null,
            compression: Compression::UNCOMPRESSED,
            photometric: Photometric::RGB,
            planar: null,
            resolutionUnit: ResolutionUnit::INCHES,
            xResolution: 300.0,
            yResolution: 300.0,
            ycbcrPos: null,
            ycbcrSubSampling: null,
            ycbcrCoefficients: null,
            whitePoint: null,
            primaryChromaticities: null,
            stripOffsets: null,
            stripByteCounts: null,
            tileOffsets: null,
            tileByteCounts: null,
            transferFunction: null,
            jpegInterchangeFormat: null,
            jpegInterchangeFormatLength: null,
            referenceBlackWhite: null,
            copyright: null,
        );

        self::assertSame(3, $tiff->samplesPerPixel);
        self::assertSame(8, $tiff->bitsPerSample);
        self::assertSame(64, $tiff->rowsPerStrip);
        self::assertSame(Compression::UNCOMPRESSED, $tiff->compression);
        self::assertSame(Photometric::RGB, $tiff->photometric);
        self::assertSame(ResolutionUnit::INCHES, $tiff->resolutionUnit);
        self::assertSame(300.0, $tiff->xResolution);
        self::assertSame(300.0, $tiff->yResolution);
    }

    /**
     * Constructs a TiffData instance using tile-based fields and JPEG compression.
     * Ensures tile dimensions, offsets, and subsampling are stored as provided.
     *
     * @return void
     */
    #[Test]
    public function constructsWithTiledImage(): void
    {
        $tiff = new TiffData(
            samplesPerPixel: 3,
            bitsPerSample: 8,
            rowsPerStrip: null,
            tileWidth: 256,
            tileLength: 256,
            compression: Compression::JPEG,
            photometric: Photometric::YCBCR,
            planar: null,
            resolutionUnit: null,
            xResolution: null,
            yResolution: null,
            ycbcrPos: null,
            ycbcrSubSampling: [2, 2],
            ycbcrCoefficients: null,
            whitePoint: null,
            primaryChromaticities: null,
            stripOffsets: null,
            stripByteCounts: null,
            tileOffsets: [1024, 2048],
            tileByteCounts: [512, 512],
            transferFunction: null,
            jpegInterchangeFormat: null,
            jpegInterchangeFormatLength: null,
            referenceBlackWhite: null,
            copyright: null,
        );

        self::assertSame(256, $tiff->tileWidth);
        self::assertSame(256, $tiff->tileLength);
        self::assertSame(Compression::JPEG, $tiff->compression);
        self::assertSame([2, 2], $tiff->ycbcrSubSampling);
        self::assertSame([1024, 2048], $tiff->tileOffsets);
    }

    /**
     * Builds a TiffData instance with all nullable fields set to null.
     * Confirms the value object preserves nulls without coercion.
     *
     * @return void
     */
    #[Test]
    public function allowsNullValues(): void
    {
        $tiff = new TiffData(
            samplesPerPixel: null,
            bitsPerSample: null,
            rowsPerStrip: null,
            tileWidth: null,
            tileLength: null,
            compression: null,
            photometric: null,
            planar: null,
            resolutionUnit: null,
            xResolution: null,
            yResolution: null,
            ycbcrPos: null,
            ycbcrSubSampling: null,
            ycbcrCoefficients: null,
            whitePoint: null,
            primaryChromaticities: null,
            stripOffsets: null,
            stripByteCounts: null,
            tileOffsets: null,
            tileByteCounts: null,
            transferFunction: null,
            jpegInterchangeFormat: null,
            jpegInterchangeFormatLength: null,
            referenceBlackWhite: null,
            copyright: null,
        );

        self::assertNull($tiff->samplesPerPixel);
        self::assertNull($tiff->compression);
        self::assertNull($tiff->photometric);
    }
}
