<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Exif\Reader;

use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\FallbackIfdSet;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Exif\Model\IfdValueReader;
use MagicSunday\ImageMeta\Exif\Reader\IsoSensitivityReader;
use MagicSunday\ImageMeta\Exif\ValueConverters;
use MagicSunday\ImageMeta\Value\Enum\SensitivityType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Exercises IsoSensitivityReader for reading ISO speed, photographic sensitivity,
 * sensitivity type, and related metadata from synthetic IFD entries.
 *
 * @internal
 */
#[CoversClass(IsoSensitivityReader::class)]
#[UsesClass(Ifd::class)]
#[UsesClass(IfdEntry::class)]
#[UsesClass(IfdValueReader::class)]
#[UsesClass(ValueConverters::class)]
#[UsesClass(FallbackIfdSet::class)]
final class IsoSensitivityReaderTest extends TestCase
{
    /**
     * Supplies PhotographicSensitivity (ISO) tag in ExifIFD.
     * Verifies the ISO value is read correctly.
     */
    #[Test]
    public function readsIsoFromPhotographicSensitivity(): void
    {
        $exifEntries = [
            ExifTag::PHOTOGRAPHIC_SENSITIVITY => new IfdEntry(ExifTag::PHOTOGRAPHIC_SENSITIVITY, 3, 1, 400),
        ];

        $reader = $this->createReader([], $exifEntries);

        self::assertSame(400, $reader->iso());
    }

    /**
     * Supplies SensitivityType and StandardOutputSensitivity tags.
     * Verifies the reader uses the sensitivity type to prioritize tags.
     */
    #[Test]
    public function readsIsoWithSensitivityType(): void
    {
        $exifEntries = [
            ExifTag::SENSITIVITY_TYPE            => new IfdEntry(ExifTag::SENSITIVITY_TYPE, 3, 1, SensitivityType::StandardOutputSensitivity->value),
            ExifTag::PHOTOGRAPHIC_SENSITIVITY    => new IfdEntry(ExifTag::PHOTOGRAPHIC_SENSITIVITY, 3, 1, 200),
            ExifTag::STANDARD_OUTPUT_SENSITIVITY => new IfdEntry(ExifTag::STANDARD_OUTPUT_SENSITIVITY, 4, 1, 250),
        ];

        $reader = $this->createReader([], $exifEntries);

        self::assertSame(SensitivityType::StandardOutputSensitivity, $reader->sensitivityType());
        // With SensitivityType=SOS, PhotographicSensitivity is prioritized first
        self::assertSame(200, $reader->iso());
    }

    /**
     * Supplies ISOSpeed and SpectralSensitivity tags in ExifIFD.
     * Verifies both values are read correctly.
     */
    #[Test]
    public function readsIsoSpeedAndSpectralSensitivity(): void
    {
        $exifEntries = [
            ExifTag::ISO_SPEED            => new IfdEntry(ExifTag::ISO_SPEED, 4, 1, 800),
            ExifTag::SPECTRAL_SENSITIVITY => new IfdEntry(ExifTag::SPECTRAL_SENSITIVITY, 2, 1, '400-700nm'),
        ];

        $reader = $this->createReader([], $exifEntries);

        self::assertSame(800, $reader->isoSpeedValue());
        self::assertSame('400-700nm', $reader->spectralSensitivity());
    }

    /**
     * Verifies null is returned for all fields when no entries are present.
     */
    #[Test]
    public function returnsNullWhenNoEntriesPresent(): void
    {
        $reader = $this->createReader([], []);

        self::assertNull($reader->iso());
        self::assertNull($reader->sensitivityType());
        self::assertNull($reader->standardOutputSensitivity());
        self::assertNull($reader->recommendedExposureIndex());
        self::assertNull($reader->isoSpeedValue());
        self::assertNull($reader->spectralSensitivity());
        self::assertNull($reader->isoSpeedLatitudeYyy());
        self::assertNull($reader->isoSpeedLatitudeZzz());
    }

    /**
     * Supplies ISOSpeedLatitudeyyy without ISOSpeed, verifying it returns null
     * per the spec requirement that all three tags must be present.
     */
    #[Test]
    public function returnsNullLatitudeYyyWhenIsoSpeedAbsent(): void
    {
        $exifEntries = [
            ExifTag::ISO_SPEED_LATITUDE_YYY => new IfdEntry(ExifTag::ISO_SPEED_LATITUDE_YYY, 4, 1, 500),
            ExifTag::ISO_SPEED_LATITUDE_ZZZ => new IfdEntry(ExifTag::ISO_SPEED_LATITUDE_ZZZ, 4, 1, 1000),
        ];

        $reader = $this->createReader([], $exifEntries);

        self::assertNull($reader->isoSpeedLatitudeYyy());
    }

    /**
     * @param array<int, IfdEntry> $ifd0Entries
     * @param array<int, IfdEntry> $exifEntries
     */
    private function createReader(array $ifd0Entries, array $exifEntries): IsoSensitivityReader
    {
        $ifd0    = new Ifd($ifd0Entries);
        $exifIfd = $exifEntries !== [] ? new Ifd($exifEntries) : null;

        return new IsoSensitivityReader(
            new IfdValueReader(new ValueConverters()),
            $ifd0,
            $exifIfd,
            new FallbackIfdSet(null, [], [], $ifd0),
        );
    }
}
