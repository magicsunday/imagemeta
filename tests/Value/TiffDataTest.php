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
use MagicSunday\ImageMeta\Value\TiffColorRef;
use MagicSunday\ImageMeta\Value\TiffData;
use MagicSunday\ImageMeta\Value\TiffLayout;
use MagicSunday\ImageMeta\Value\TiffStructure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the TiffData value object for TIFF image structure fields.
 * It verifies strip/tile dimensions, compression, and photometric enums are preserved.
 * The suite covers resolution units and X/Y resolution values.
 * This ensures TIFF-related metadata remains consistent for downstream usage.
 */
#[CoversClass(TiffData::class)]
#[UsesClass(TiffStructure::class)]
#[UsesClass(TiffColorRef::class)]
#[UsesClass(TiffLayout::class)]
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
        $structure = new TiffStructure(
            samplesPerPixel: 3,
            bitsPerSample: 8,
            compression: Compression::UNCOMPRESSED,
            photometric: Photometric::RGB,
        );

        $layout = new TiffLayout(
            rowsPerStrip: 64,
        );

        $tiff = new TiffData(
            structure: $structure,
            layout: $layout,
            resolutionUnit: ResolutionUnit::INCHES,
            xResolution: 300.0,
            yResolution: 300.0,
        );

        self::assertNotNull($tiff->structure);
        self::assertNotNull($tiff->layout);

        self::assertSame(3, $tiff->structure->samplesPerPixel);
        self::assertSame(8, $tiff->structure->bitsPerSample);
        self::assertSame(64, $tiff->layout->rowsPerStrip);
        self::assertSame(Compression::UNCOMPRESSED, $tiff->structure->compression);
        self::assertSame(Photometric::RGB, $tiff->structure->photometric);
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
        $structure = new TiffStructure(
            samplesPerPixel: 3,
            bitsPerSample: 8,
            compression: Compression::JPEG,
            photometric: Photometric::YCBCR,
        );

        $color = new TiffColorRef(
            ycbcrSubSampling: [2, 2],
        );

        $layout = new TiffLayout(
            tileWidth: 256,
            tileLength: 256,
            tileOffsets: [1024, 2048],
            tileByteCounts: [512, 512],
        );

        $tiff = new TiffData(
            structure: $structure,
            color: $color,
            layout: $layout,
        );

        self::assertNotNull($tiff->layout);
        self::assertNotNull($tiff->structure);
        self::assertNotNull($tiff->color);

        self::assertSame(256, $tiff->layout->tileWidth);
        self::assertSame(256, $tiff->layout->tileLength);
        self::assertSame(Compression::JPEG, $tiff->structure->compression);
        self::assertSame([2, 2], $tiff->color->ycbcrSubSampling);
        self::assertSame([1024, 2048], $tiff->layout->tileOffsets);
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
        $tiff = new TiffData();

        self::assertNull($tiff->structure);
        self::assertNull($tiff->color);
        self::assertNull($tiff->layout);
    }
}
