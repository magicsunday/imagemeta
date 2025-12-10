<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Model\Exif;

use MagicSunday\ImageMeta\Model\Exif\ExifTag;
use MagicSunday\ImageMeta\Model\Exif\Ifd;
use MagicSunday\ImageMeta\Model\Exif\IfdEntry;
use MagicSunday\ImageMeta\Model\Exif\ParsedExif;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ParsedExif::class)]
final class ParsedExifOtherTagsTest extends TestCase
{
    #[Test]
    public function imageUniqueIdReturnsHexUuidString(): void
    {
        $exifIfd = new Ifd([
            ExifTag::IMAGE_UNIQUE_ID => new IfdEntry(
                ExifTag::IMAGE_UNIQUE_ID,
                2,
                33,
                '00112233445566778899aabbccddeeff',
            ),
        ]);

        $parsedExif = new ParsedExif(new Ifd([]), $exifIfd, null, null, null);

        self::assertSame('00112233445566778899aabbccddeeff', $parsedExif->imageUniqueId());
    }

    #[Test]
    public function hardwareAttributionTagsReturnExifStrings(): void
    {
        $exifIfd = new Ifd([
            ExifTag::CAMERA_OWNER_NAME  => new IfdEntry(ExifTag::CAMERA_OWNER_NAME, 2, 1, 'Owner'),
            ExifTag::BODY_SERIAL_NUMBER => new IfdEntry(ExifTag::BODY_SERIAL_NUMBER, 2, 1, '123456789'),
            ExifTag::LENS_MAKE          => new IfdEntry(ExifTag::LENS_MAKE, 2, 1, 'LensMaker'),
            ExifTag::LENS_MODEL         => new IfdEntry(ExifTag::LENS_MODEL, 2, 1, 'Lens Model 12-35mm'),
            ExifTag::LENS_SERIAL_NUMBER => new IfdEntry(ExifTag::LENS_SERIAL_NUMBER, 2, 1, 'LN987654321'),
        ]);

        $parsedExif = new ParsedExif(new Ifd([]), $exifIfd, null, null, null);

        self::assertSame('Owner', $parsedExif->ownerName());
        self::assertSame('123456789', $parsedExif->bodySerialNumber());
        self::assertSame('LensMaker', $parsedExif->lensMake());
        self::assertSame('Lens Model 12-35mm', $parsedExif->lensModel());
        self::assertSame('LN987654321', $parsedExif->lensSerialNumber());
    }

    #[Test]
    public function lensSpecificationParsesFourRationals(): void
    {
        $exifIfd = new Ifd([
            ExifTag::LENS_SPECIFICATION => new IfdEntry(
                ExifTag::LENS_SPECIFICATION,
                5,
                4,
                [
                    [24, 1],
                    [70, 1],
                    [28, 10],
                    [28, 10],
                ],
            ),
        ]);

        $parsedExif = new ParsedExif(new Ifd([]), $exifIfd, null, null, null);

        self::assertSame([24.0, 70.0, 2.8, 2.8], $parsedExif->lensSpecification());
    }

    #[Test]
    public function softwarePipelineTagsReturnExifStrings(): void
    {
        $exifIfd = new Ifd([
            ExifTag::CAMERA_FIRMWARE           => new IfdEntry(ExifTag::CAMERA_FIRMWARE, 2, 1, 'Firmware 1.2.3'),
            ExifTag::RAW_DEVELOPING_SOFTWARE   => new IfdEntry(ExifTag::RAW_DEVELOPING_SOFTWARE, 2, 1, 'RAW Developer 5.0'),
            ExifTag::IMAGE_EDITING_SOFTWARE    => new IfdEntry(ExifTag::IMAGE_EDITING_SOFTWARE, 2, 1, 'Editor 2.0'),
            ExifTag::METADATA_EDITING_SOFTWARE => new IfdEntry(ExifTag::METADATA_EDITING_SOFTWARE, 2, 1, 'Metadata Tool 3.1'),
        ]);

        $parsedExif = new ParsedExif(new Ifd([]), $exifIfd, null, null, null);

        self::assertSame('Firmware 1.2.3', $parsedExif->cameraFirmware());
        self::assertSame('RAW Developer 5.0', $parsedExif->rawDevelopingSoftware());
        self::assertSame('Editor 2.0', $parsedExif->imageEditingSoftware());
        self::assertSame('Metadata Tool 3.1', $parsedExif->metadataEditingSoftware());
    }
}
