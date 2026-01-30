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
 * Tests for the TiffData value object.
 */
#[CoversClass(TiffData::class)]
final class TiffDataTest extends TestCase
{
    /**
     * Verifies that $tiff->samplesPerPixel equals 3.
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
     * Verifies that $tiff->tileWidth equals 256.
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
     * Verifies that $tiff->samplesPerPixel is null.
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
