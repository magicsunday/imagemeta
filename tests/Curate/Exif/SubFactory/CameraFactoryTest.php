<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Curate\Exif\SubFactory;

use MagicSunday\ImageMeta\Curate\Exif\SubFactory\CameraFactory;
use MagicSunday\ImageMeta\Model\Exif\ParsedExif;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Value\Camera;
use MagicSunday\ImageMeta\Value\Enum\FileSource;
use MagicSunday\ImageMeta\Value\Enum\SensingMethod;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CameraFactory::class)]
final class CameraFactoryTest extends TestCase
{
    #[Test]
    public function createsFromExifMetadata(): void
    {
        $exifDoc = $this->createMock(ParsedExif::class);
        $exifDoc->method('cameraMake')->willReturn('Canon');
        $exifDoc->method('cameraModel')->willReturn('EOS R6');
        $exifDoc->method('ownerName')->willReturn('Test Owner');
        $exifDoc->method('cameraFirmware')->willReturn('1.0.0');
        $exifDoc->method('fileSource')->willReturn(FileSource::DIGITAL_CAMERA);
        $exifDoc->method('sensingMethod')->willReturn(SensingMethod::ONE_CHIP_COLOR_AREA);

        $metadata          = new Metadata();
        $metadata->exifDoc = $exifDoc;

        $factory = new CameraFactory();
        $camera  = $factory->create($metadata);

        self::assertInstanceOf(Camera::class, $camera);
        self::assertSame('Canon', $camera->make);
        self::assertSame('EOS R6', $camera->model);
        self::assertSame('Test Owner', $camera->ownerName);
        self::assertSame('1.0.0', $camera->firmware);
        self::assertSame(FileSource::DIGITAL_CAMERA, $camera->fileSource);
        self::assertSame(SensingMethod::ONE_CHIP_COLOR_AREA, $camera->sensingMethod);
    }

    #[Test]
    public function createsWithNullExifDoc(): void
    {
        $metadata = new Metadata();

        $factory = new CameraFactory();
        $camera  = $factory->create($metadata);

        self::assertInstanceOf(Camera::class, $camera);
        self::assertNull($camera->make);
        self::assertNull($camera->model);
        self::assertNull($camera->ownerName);
        self::assertNull($camera->firmware);
        self::assertNull($camera->fileSource);
        self::assertNull($camera->sensingMethod);
    }

    #[Test]
    public function createsWithPartialExifData(): void
    {
        $exifDoc = $this->createMock(ParsedExif::class);
        $exifDoc->method('cameraMake')->willReturn('Nikon');
        $exifDoc->method('cameraModel')->willReturn(null);
        $exifDoc->method('ownerName')->willReturn(null);
        $exifDoc->method('cameraFirmware')->willReturn(null);
        $exifDoc->method('fileSource')->willReturn(null);
        $exifDoc->method('sensingMethod')->willReturn(null);

        $metadata          = new Metadata();
        $metadata->exifDoc = $exifDoc;

        $factory = new CameraFactory();
        $camera  = $factory->create($metadata);

        self::assertInstanceOf(Camera::class, $camera);
        self::assertSame('Nikon', $camera->make);
        self::assertNull($camera->model);
        self::assertNull($camera->ownerName);
        self::assertNull($camera->firmware);
        self::assertNull($camera->fileSource);
        self::assertNull($camera->sensingMethod);
    }
}
