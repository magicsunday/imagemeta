<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Exif\Reader;

use MagicSunday\ImageMeta\Exif\Model\ExifRational;
use MagicSunday\ImageMeta\Exif\Model\ExifRationalList;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Exif\Model\IfdValueReader;
use MagicSunday\ImageMeta\Exif\Reader\CameraLensExifReader;
use MagicSunday\ImageMeta\Exif\ValueConverters;
use MagicSunday\ImageMeta\Model\Dng\DngTag;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Exercises CameraLensExifReader for reading camera make/model, lens identification,
 * serial numbers, owner name, and DNG camera model fields from synthetic IFD entries.
 *
 * @internal
 */
#[CoversClass(CameraLensExifReader::class)]
#[UsesClass(Ifd::class)]
#[UsesClass(IfdEntry::class)]
#[UsesClass(IfdValueReader::class)]
#[UsesClass(ValueConverters::class)]
#[UsesClass(ExifRational::class)]
#[UsesClass(ExifRationalList::class)]
final class CameraLensExifReaderTest extends TestCase
{
    /**
     * Supplies IFD0 and ExifIFD entries with camera and lens metadata.
     * Verifies all fields are returned correctly.
     */
    #[Test]
    public function readsAllCameraLensFields(): void
    {
        $ifd0Entries = [
            ExifTag::MAKE  => new IfdEntry(ExifTag::MAKE, 2, 1, 'Canon'),
            ExifTag::MODEL => new IfdEntry(ExifTag::MODEL, 2, 1, 'EOS R5'),
        ];

        $exifEntries = [
            ExifTag::LENS_MODEL         => new IfdEntry(ExifTag::LENS_MODEL, 2, 1, 'RF 24-70mm F2.8L'),
            ExifTag::LENS_MAKE          => new IfdEntry(ExifTag::LENS_MAKE, 2, 1, 'Canon'),
            ExifTag::CAMERA_OWNER_NAME  => new IfdEntry(ExifTag::CAMERA_OWNER_NAME, 2, 1, 'John Doe'),
            ExifTag::BODY_SERIAL_NUMBER => new IfdEntry(ExifTag::BODY_SERIAL_NUMBER, 2, 1, 'SN12345'),
            ExifTag::LENS_SERIAL_NUMBER => new IfdEntry(ExifTag::LENS_SERIAL_NUMBER, 2, 1, 'LS67890'),
            ExifTag::LENS_SPECIFICATION => new IfdEntry(ExifTag::LENS_SPECIFICATION, 5, 4, [
                [24, 1],
                [70, 1],
                [28, 10],
                [28, 10],
            ]),
        ];

        $reader = $this->createReader($ifd0Entries, $exifEntries);

        self::assertSame('Canon', $reader->cameraMake());
        self::assertSame('EOS R5', $reader->cameraModel());
        self::assertSame('RF 24-70mm F2.8L', $reader->lensModel());
        self::assertSame('Canon', $reader->lensMake());
        self::assertSame('John Doe', $reader->ownerName());
        self::assertSame('SN12345', $reader->bodySerialNumber());
        self::assertSame('LS67890', $reader->lensSerialNumber());

        $spec = $reader->lensSpecification();
        self::assertNotNull($spec);
        self::assertSame(24.0, $spec[0]);
        self::assertSame(70.0, $spec[1]);
        self::assertSame(2.8, $spec[2]);
        self::assertSame(2.8, $spec[3]);
    }

    /**
     * Verifies all fields return null when no IFD entries are present.
     */
    #[Test]
    public function returnsNullWhenNoEntriesPresent(): void
    {
        $reader = $this->createReader([], []);

        self::assertNull($reader->cameraMake());
        self::assertNull($reader->cameraModel());
        self::assertNull($reader->lensModel());
        self::assertNull($reader->lensMake());
        self::assertNull($reader->ownerName());
        self::assertNull($reader->bodySerialNumber());
        self::assertNull($reader->lensSerialNumber());
        self::assertNull($reader->lensSpecification());
    }

    /**
     * Supplies a DNG UniqueCameraModel entry in IFD0.
     * Verifies DNG camera identification fields are returned.
     */
    #[Test]
    public function readsDngCameraFields(): void
    {
        $ifd0Entries = [
            DngTag::UNIQUE_CAMERA_MODEL    => new IfdEntry(DngTag::UNIQUE_CAMERA_MODEL, 2, 1, 'Leica M10-R'),
            DngTag::LOCALIZED_CAMERA_MODEL => new IfdEntry(DngTag::LOCALIZED_CAMERA_MODEL, 2, 1, 'Leica M10-R DE'),
            DngTag::CAMERA_SERIAL_NUMBER   => new IfdEntry(DngTag::CAMERA_SERIAL_NUMBER, 2, 1, 'DNG-SN-001'),
        ];

        $reader = $this->createReader($ifd0Entries, []);

        self::assertSame('Leica M10-R', $reader->uniqueCameraModel());
        self::assertSame('Leica M10-R DE', $reader->localizedCameraModel());
        self::assertSame('DNG-SN-001', $reader->cameraSerialNumber());
    }

    /**
     * Verifies localizedCameraModel() falls back to uniqueCameraModel() when the
     * localized tag is absent.
     */
    #[Test]
    public function localizedCameraModelFallsBackToUnique(): void
    {
        $ifd0Entries = [
            DngTag::UNIQUE_CAMERA_MODEL => new IfdEntry(DngTag::UNIQUE_CAMERA_MODEL, 2, 1, 'Nikon Z9'),
        ];

        $reader = $this->createReader($ifd0Entries, []);

        self::assertSame('Nikon Z9', $reader->localizedCameraModel());
    }

    /**
     * Supplies a lens specification with fewer than 4 rational values.
     * Verifies the reader returns null for malformed specifications.
     */
    #[Test]
    public function returnsNullForMalformedLensSpecification(): void
    {
        $exifEntries = [
            ExifTag::LENS_SPECIFICATION => new IfdEntry(ExifTag::LENS_SPECIFICATION, 5, 2, [
                [24, 1],
                [70, 1],
            ]),
        ];

        $reader = $this->createReader([], $exifEntries);

        self::assertNull($reader->lensSpecification());
    }

    /**
     * @param array<int, IfdEntry> $ifd0Entries
     * @param array<int, IfdEntry> $exifEntries
     */
    private function createReader(array $ifd0Entries, array $exifEntries): CameraLensExifReader
    {
        $ifd0    = new Ifd($ifd0Entries);
        $exifIfd = $exifEntries !== [] ? new Ifd($exifEntries) : null;

        return new CameraLensExifReader(
            new IfdValueReader(new ValueConverters()),
            $ifd0,
            $exifIfd,
        );
    }
}
