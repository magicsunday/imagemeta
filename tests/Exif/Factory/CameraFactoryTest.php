<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Exif\Factory;

use MagicSunday\ImageMeta\Exif\Factory\CameraFactory;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Exif\Model\ParsedExif;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Value\Enum\FileSource;
use MagicSunday\ImageMeta\Value\Enum\SensingMethod;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Exercises CameraFactory for building Camera value objects from ParsedExif tags.
 * It verifies make, model, owner name, and firmware values are mapped correctly.
 * The suite covers enum-backed fields like FileSource and SensingMethod.
 * This ensures camera metadata is normalized consistently for structured output.
 *
 * @internal
 */
#[CoversClass(CameraFactory::class)]
final class CameraFactoryTest extends TestCase
{
    /**
     * Builds ParsedExif data with camera-related tags and feeds it into CameraFactory.
     * Verifies the resulting Camera value object contains the expected fields.
     *
     * @return void
     */
    #[Test]
    public function createsFromExifMetadata(): void
    {
        $parsedExif = $this->parsedExif(
            make: 'Canon',
            model: 'EOS R6',
            ownerName: 'Test Owner',
            firmware: '1.0.0',
            fileSource: FileSource::DigitalCamera,
            sensingMethod: SensingMethod::OneChipColorArea,
        );

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            exifDoc: $parsedExif,
        );

        $factory = new CameraFactory();
        $camera  = $factory->create($metadata);

        self::assertSame('Canon', $camera->make);
        self::assertSame('EOS R6', $camera->model);
        self::assertSame('Test Owner', $camera->ownerName);
        self::assertSame('1.0.0', $camera->firmware);
        self::assertSame(FileSource::DigitalCamera, $camera->fileSource);
        self::assertSame(SensingMethod::OneChipColorArea, $camera->sensingMethod);
    }

    /**
     * Creates Metadata without an EXIF document.
     * Ensures CameraFactory returns a camera object with all fields set to null.
     *
     * @return void
     */
    #[Test]
    public function createsWithNullExifDoc(): void
    {
        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
        );

        $factory = new CameraFactory();
        $camera  = $factory->create($metadata);

        self::assertNull($camera->make);
        self::assertNull($camera->model);
        self::assertNull($camera->ownerName);
        self::assertNull($camera->firmware);
        self::assertNull($camera->fileSource);
        self::assertNull($camera->sensingMethod);
    }

    /**
     * Supplies ParsedExif data with only the make tag populated.
     * Confirms the factory preserves the make and leaves other fields unset.
     *
     * @return void
     */
    #[Test]
    public function createsWithPartialExifData(): void
    {
        $parsedExif = $this->parsedExif(
            make: 'Nikon',
            model: null,
            ownerName: null,
            firmware: null,
            fileSource: null,
            sensingMethod: null,
        );

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            exifDoc: $parsedExif,
        );

        $factory = new CameraFactory();
        $camera  = $factory->create($metadata);

        self::assertSame('Nikon', $camera->make);
        self::assertNull($camera->model);
        self::assertNull($camera->ownerName);
        self::assertNull($camera->firmware);
        self::assertSame(FileSource::DigitalCamera, $camera->fileSource);
        self::assertNull($camera->sensingMethod);
    }

    private function parsedExif(
        ?string $make,
        ?string $model,
        ?string $ownerName,
        ?string $firmware,
        ?FileSource $fileSource,
        ?SensingMethod $sensingMethod,
    ): ParsedExif {
        $ifd0Entries = [];
        $exifEntries = [];

        if ($make !== null) {
            $ifd0Entries[ExifTag::MAKE] = new IfdEntry(ExifTag::MAKE, 2, 1, $make);
        }

        if ($model !== null) {
            $ifd0Entries[ExifTag::MODEL] = new IfdEntry(ExifTag::MODEL, 2, 1, $model);
        }

        if ($ownerName !== null) {
            $exifEntries[ExifTag::CAMERA_OWNER_NAME] = new IfdEntry(ExifTag::CAMERA_OWNER_NAME, 2, 1, $ownerName);
        }

        if ($firmware !== null) {
            $exifEntries[ExifTag::CAMERA_FIRMWARE] = new IfdEntry(ExifTag::CAMERA_FIRMWARE, 2, 1, $firmware);
        }

        if ($fileSource instanceof FileSource) {
            $ifd0Entries[ExifTag::FILE_SOURCE] = new IfdEntry(ExifTag::FILE_SOURCE, 7, 1, $fileSource->value);
        }

        if ($sensingMethod instanceof SensingMethod) {
            $exifEntries[ExifTag::SENSING_METHOD] = new IfdEntry(ExifTag::SENSING_METHOD, 3, 1, $sensingMethod->value);
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
