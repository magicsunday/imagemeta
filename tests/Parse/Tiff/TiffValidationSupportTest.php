<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Parse\Tiff;

use MagicSunday\ImageMeta\Core\MemoryBuffer;
use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Exif\Model\ExifNumericList;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Model\Tiff\TiffTag;
use MagicSunday\ImageMeta\Parse\Tiff\TiffConst;
use MagicSunday\ImageMeta\Parse\Tiff\TiffValidationSupport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(TiffValidationSupport::class)]
final class TiffValidationSupportTest extends TestCase
{
    #[Test]
    public function countedImageDataTagNameResolvesKnownStripAndTileTags(): void
    {
        self::assertSame('StripOffsets', TiffValidationSupport::countedImageDataTagName(ExifTag::STRIP_OFFSETS));
        self::assertSame('StripByteCounts', TiffValidationSupport::countedImageDataTagName(ExifTag::STRIP_BYTE_COUNTS));
        self::assertSame('TileOffsets', TiffValidationSupport::countedImageDataTagName(TiffTag::TILE_OFFSETS));
        self::assertSame('TileByteCounts', TiffValidationSupport::countedImageDataTagName(TiffTag::TILE_BYTE_COUNTS));
    }

    #[Test]
    public function countedImageDataTagNameFallsBackToHexTagLabel(): void
    {
        self::assertSame('IFD tag 0x1234', TiffValidationSupport::countedImageDataTagName(0x1234));
    }

    #[Test]
    public function resolveUniformBitsPerSampleReturnsUniformValue(): void
    {
        $support = new TiffValidationSupport(new MemoryBuffer("\0"));
        $ifd     = new Ifd([
            ExifTag::BITS_PER_SAMPLE => new IfdEntry(
                ExifTag::BITS_PER_SAMPLE,
                TiffConst::TYPE_SHORT,
                3,
                new ExifNumericList([12, 12, 12]),
            ),
        ]);

        self::assertSame(12, $support->resolveUniformBitsPerSample($ifd, 'UnitTest', 3000));
    }

    #[Test]
    public function resolveUniformBitsPerSampleRejectsNonIntegerComponents(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(3001);

        $support = new TiffValidationSupport(new MemoryBuffer("\0"));
        $ifd     = new Ifd([
            ExifTag::BITS_PER_SAMPLE => new IfdEntry(
                ExifTag::BITS_PER_SAMPLE,
                TiffConst::TYPE_SHORT,
                2,
                new ExifNumericList([8, 8.5]),
            ),
        ]);

        $support->resolveUniformBitsPerSample($ifd, 'UnitTest', 3000);
    }

    #[Test]
    public function requiresSingleBitsPerSampleIntegerComponentErrorTemplateInSource(): void
    {
        $source = \file_get_contents(__DIR__ . '/../../../src/Parse/Tiff/TiffValidationSupport.php');

        self::assertNotFalse($source);
        self::assertSame(
            1,
            \substr_count($source, 'BitsPerSample must decode to integer components for %s.'),
            'Expected duplicated BitsPerSample integer-component error template to be consolidated.',
        );
    }
}
