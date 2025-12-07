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
use MagicSunday\ImageMeta\Model\Exif\ExifTag;
use MagicSunday\ImageMeta\Model\Exif\Ifd;
use MagicSunday\ImageMeta\Model\Exif\IfdEntry;
use MagicSunday\ImageMeta\Model\Exif\ParsedExif;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Model\QuickTimeMeta;
use MagicSunday\ImageMeta\Model\Tiff\TiffTag;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function strlen;

#[CoversClass(DeviceFactory::class)]
final class DeviceFactoryTest extends TestCase
{
    #[Test]
    public function createsFromExifMetadata(): void
    {
        $parsedExif = $this->parsedExif(
            hostComputer: 'MacBook Pro',
            rawDevelopingSoftware: 'Adobe Camera Raw 15.0',
            imageEditingSoftware: 'Adobe Photoshop 24.0',
            metadataEditingSoftware: 'Lightroom Classic 12.0',
        );

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            exifDoc: $parsedExif,
        );

        $factory = new DeviceFactory();
        $device  = $factory->create($metadata);

        self::assertSame('MacBook Pro', $device->software);
        self::assertSame('Adobe Camera Raw 15.0', $device->rawDevelopingSoftware);
        self::assertSame('Adobe Photoshop 24.0', $device->imageEditingSoftware);
        self::assertSame('Lightroom Classic 12.0', $device->metadataEditingSoftware);
    }

    #[Test]
    public function fallsBackToQuickTimeSoftware(): void
    {
        $parsedExif = $this->parsedExif(
            hostComputer: null,
            rawDevelopingSoftware: null,
            imageEditingSoftware: null,
            metadataEditingSoftware: null,
        );

        $quickTime = new QuickTimeMeta([
            'com.apple.quicktime.software' => 'iPhone OS 16.0',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: $quickTime,
            exifDoc: $parsedExif,
        );

        $factory = new DeviceFactory();
        $device  = $factory->create($metadata);

        self::assertSame('iPhone OS 16.0', $device->software);
        self::assertNull($device->rawDevelopingSoftware);
        self::assertNull($device->imageEditingSoftware);
        self::assertNull($device->metadataEditingSoftware);
    }

    #[Test]
    public function prefersExifOverQuickTime(): void
    {
        $parsedExif = $this->parsedExif(
            hostComputer: 'Desktop PC',
            rawDevelopingSoftware: null,
            imageEditingSoftware: null,
            metadataEditingSoftware: null,
        );

        $quickTime = new QuickTimeMeta([
            'com.apple.quicktime.software' => 'iPhone OS 16.0',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: $quickTime,
            exifDoc: $parsedExif,
        );

        $factory = new DeviceFactory();
        $device  = $factory->create($metadata);

        self::assertSame('Desktop PC', $device->software);
    }

    #[Test]
    public function createsWithNullMetadata(): void
    {
        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
        );

        $factory = new DeviceFactory();
        $device  = $factory->create($metadata);

        self::assertNull($device->software);
        self::assertNull($device->rawDevelopingSoftware);
        self::assertNull($device->imageEditingSoftware);
        self::assertNull($device->metadataEditingSoftware);
    }

    private function parsedExif(
        ?string $hostComputer,
        ?string $rawDevelopingSoftware,
        ?string $imageEditingSoftware,
        ?string $metadataEditingSoftware,
    ): ParsedExif {
        $ifd0Entries = [];
        $exifEntries = [];

        if ($hostComputer !== null) {
            $ifd0Entries[TiffTag::HOST_COMPUTER] = new IfdEntry(
                TiffTag::HOST_COMPUTER,
                2,
                strlen($hostComputer),
                $hostComputer,
            );
        }

        if ($rawDevelopingSoftware !== null) {
            $exifEntries[ExifTag::RAW_DEVELOPING_SOFTWARE] = new IfdEntry(
                ExifTag::RAW_DEVELOPING_SOFTWARE,
                2,
                strlen($rawDevelopingSoftware),
                $rawDevelopingSoftware,
            );
        }

        if ($imageEditingSoftware !== null) {
            $exifEntries[ExifTag::IMAGE_EDITING_SOFTWARE] = new IfdEntry(
                ExifTag::IMAGE_EDITING_SOFTWARE,
                2,
                strlen($imageEditingSoftware),
                $imageEditingSoftware,
            );
        }

        if ($metadataEditingSoftware !== null) {
            $exifEntries[ExifTag::METADATA_EDITING_SOFTWARE] = new IfdEntry(
                ExifTag::METADATA_EDITING_SOFTWARE,
                2,
                strlen($metadataEditingSoftware),
                $metadataEditingSoftware,
            );
        }

        $ifd0    = new Ifd($ifd0Entries);
        $exifIfd = new Ifd($exifEntries);

        return new ParsedExif(
            ifd0: $ifd0,
            exifIfd: $exifIfd,
            gpsIfd: null,
            interopIfd: null,
            ifd1: null,
        );
    }
}
