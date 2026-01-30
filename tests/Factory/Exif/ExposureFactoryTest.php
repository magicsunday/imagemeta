<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Factory\Exif;

use MagicSunday\ImageMeta\Factory\Exif\ExposureFactory;
use MagicSunday\ImageMeta\Model\Exif\ExifTag;
use MagicSunday\ImageMeta\Model\Exif\Ifd;
use MagicSunday\ImageMeta\Model\Exif\IfdEntry;
use MagicSunday\ImageMeta\Model\Exif\ParsedExif;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Value\FlashInfo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExposureFactory::class)]
final class ExposureFactoryTest extends TestCase
{
    #[Test]
    public function createsFromExifMetadata(): void
    {
        $parsedExif = $this->parsedExifWithIsoAndFlash(100, 0x0001);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            exifDoc: $parsedExif,
        );

        $factory  = new ExposureFactory();
        $exposure = $factory->create($metadata);

        self::assertSame(100, $exposure->iso);
        self::assertInstanceOf(FlashInfo::class, $exposure->flash);
        self::assertTrue($exposure->flash->fired);
    }

    #[Test]
    public function createsWithNullExifDoc(): void
    {
        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
        );

        $factory  = new ExposureFactory();
        $exposure = $factory->create($metadata);

        self::assertNull($exposure->iso);
        self::assertInstanceOf(FlashInfo::class, $exposure->flash);
        self::assertFalse($exposure->flash->fired);
    }

    #[Test]
    public function parsesFlashInformation(): void
    {
        $parsedExif = $this->parsedExifWithIsoAndFlash(null, 0x0019);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            exifDoc: $parsedExif,
        );

        $factory  = new ExposureFactory();
        $exposure = $factory->create($metadata);

        self::assertNull($exposure->iso);
        self::assertInstanceOf(FlashInfo::class, $exposure->flash);
        self::assertTrue($exposure->flash->fired);
    }

    private function parsedExifWithIsoAndFlash(?int $iso, ?int $flash): ParsedExif
    {
        $entries = [];

        if ($iso !== null) {
            $entries[ExifTag::PHOTOGRAPHIC_SENSITIVITY] = new IfdEntry(
                ExifTag::PHOTOGRAPHIC_SENSITIVITY,
                3,
                1,
                $iso,
            );
        }

        if ($flash !== null) {
            $entries[ExifTag::FLASH] = new IfdEntry(
                ExifTag::FLASH,
                3,
                1,
                $flash,
            );
        }

        $ifd0    = new Ifd([]);
        $exifIfd = new Ifd($entries);

        return new ParsedExif(
            ifd0: $ifd0,
            exifIfd: $exifIfd,
            gpsIfd: null,
            interopIfd: null,
            ifd1: null,
        );
    }
}
