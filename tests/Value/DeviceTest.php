<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Value;

use MagicSunday\ImageMeta\Value\Device;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the Device value object for software and processing metadata.
 * It verifies software, raw-developing, and editing tool fields are preserved.
 * The suite checks multiple software sources can be stored together.
 * This ensures device/software provenance remains consistent in outputs.
 */
#[CoversClass(Device::class)]
final class DeviceTest extends TestCase
{
    /**
     * Stores device software fields when provided.
     * It confirms the object preserves the supplied metadata.
     *
     * @return void
     */
    #[Test]
    public function constructsWithSoftwareVersion(): void
    {
        $device = new Device(
            software: 'Adobe Photoshop 2024',
            rawDevelopingSoftware: null,
            imageEditingSoftware: null,
            metadataEditingSoftware: null,
        );

        self::assertSame('Adobe Photoshop 2024', $device->software);
    }

    /**
     * Stores raw and editing software metadata together.
     * It confirms the object preserves the supplied metadata.
     *
     * @return void
     */
    #[Test]
    public function constructsWithAllDeviceInfo(): void
    {
        $device = new Device(
            software: 'Capture One 23',
            rawDevelopingSoftware: 'Adobe Camera Raw 15',
            imageEditingSoftware: 'Photoshop 2024',
            metadataEditingSoftware: 'ExifTool 12.50',
        );

        self::assertSame('Capture One 23', $device->software);
        self::assertSame('Adobe Camera Raw 15', $device->rawDevelopingSoftware);
        self::assertSame('Photoshop 2024', $device->imageEditingSoftware);
        self::assertSame('ExifTool 12.50', $device->metadataEditingSoftware);
    }

    /**
     * Accepts null software metadata values.
     * It ensures missing or invalid inputs yield no value.
     *
     * @return void
     */
    #[Test]
    public function allowsNullValues(): void
    {
        $device = new Device(
            software: null,
            rawDevelopingSoftware: null,
            imageEditingSoftware: null,
            metadataEditingSoftware: null,
        );

        self::assertNull($device->software);
        self::assertNull($device->rawDevelopingSoftware);
        self::assertNull($device->imageEditingSoftware);
        self::assertNull($device->metadataEditingSoftware);
    }
}
