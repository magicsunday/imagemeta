<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Curate\Exif\SubFactory;

use MagicSunday\ImageMeta\Curate\Exif\SubFactory\DeviceFactory;
use MagicSunday\ImageMeta\Model\Exif\ParsedExif;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Model\QuickTimeMeta;
use MagicSunday\ImageMeta\Value\Device;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DeviceFactory::class)]
final class DeviceFactoryTest extends TestCase
{
    #[Test]
    public function createsFromExifMetadata(): void
    {
        $exifDoc = $this->createMock(ParsedExif::class);
        $exifDoc->method('hostComputer')->willReturn('MacBook Pro');
        $exifDoc->method('rawDevelopingSoftware')->willReturn('Adobe Camera Raw 15.0');
        $exifDoc->method('imageEditingSoftware')->willReturn('Adobe Photoshop 24.0');
        $exifDoc->method('metadataEditingSoftware')->willReturn('Lightroom Classic 12.0');

        $metadata       = new Metadata();
        $metadata->exifDoc = $exifDoc;

        $factory = new DeviceFactory();
        $device  = $factory->create($metadata);

        self::assertInstanceOf(Device::class, $device);
        self::assertSame('MacBook Pro', $device->software);
        self::assertSame('Adobe Camera Raw 15.0', $device->rawDevelopingSoftware);
        self::assertSame('Adobe Photoshop 24.0', $device->imageEditingSoftware);
        self::assertSame('Lightroom Classic 12.0', $device->metadataEditingSoftware);
    }

    #[Test]
    public function fallsBackToQuickTimeSoftware(): void
    {
        $exifDoc = $this->createMock(ParsedExif::class);
        $exifDoc->method('hostComputer')->willReturn(null);
        $exifDoc->method('rawDevelopingSoftware')->willReturn(null);
        $exifDoc->method('imageEditingSoftware')->willReturn(null);
        $exifDoc->method('metadataEditingSoftware')->willReturn(null);

        $quickTime = new QuickTimeMeta();
        $quickTime->metadata['com.apple.quicktime.software'] = 'iPhone OS 16.0';

        $metadata           = new Metadata();
        $metadata->exifDoc     = $exifDoc;
        $metadata->quickTime   = $quickTime;

        $factory = new DeviceFactory();
        $device  = $factory->create($metadata);

        self::assertInstanceOf(Device::class, $device);
        self::assertSame('iPhone OS 16.0', $device->software);
    }

    #[Test]
    public function prefersExifOverQuickTime(): void
    {
        $exifDoc = $this->createMock(ParsedExif::class);
        $exifDoc->method('hostComputer')->willReturn('Desktop PC');
        $exifDoc->method('rawDevelopingSoftware')->willReturn(null);
        $exifDoc->method('imageEditingSoftware')->willReturn(null);
        $exifDoc->method('metadataEditingSoftware')->willReturn(null);

        $quickTime = new QuickTimeMeta();
        $quickTime->metadata['com.apple.quicktime.software'] = 'iPhone OS 16.0';

        $metadata           = new Metadata();
        $metadata->exifDoc     = $exifDoc;
        $metadata->quickTime   = $quickTime;

        $factory = new DeviceFactory();
        $device  = $factory->create($metadata);

        self::assertInstanceOf(Device::class, $device);
        self::assertSame('Desktop PC', $device->software);
    }

    #[Test]
    public function createsWithNullMetadata(): void
    {
        $metadata = new Metadata();

        $factory = new DeviceFactory();
        $device  = $factory->create($metadata);

        self::assertInstanceOf(Device::class, $device);
        self::assertNull($device->software);
        self::assertNull($device->rawDevelopingSoftware);
        self::assertNull($device->imageEditingSoftware);
        self::assertNull($device->metadataEditingSoftware);
    }
}
