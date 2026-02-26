<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Exif\Reader;

use MagicSunday\ImageMeta\Exif\ExifConst;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Exif\Model\IfdValueReader;
use MagicSunday\ImageMeta\Exif\Reader\FocalReader;
use MagicSunday\ImageMeta\Exif\ValueConverters;
use MagicSunday\ImageMeta\Value\Enum\FileSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Exercises FocalReader for reading focal length, focal plane resolution,
 * CFA pattern, file source, and interoperability metadata from synthetic IFD entries.
 *
 * @internal
 */
#[CoversClass(FocalReader::class)]
#[UsesClass(Ifd::class)]
#[UsesClass(IfdEntry::class)]
#[UsesClass(IfdValueReader::class)]
#[UsesClass(ValueConverters::class)]
final class FocalReaderTest extends TestCase
{
    /**
     * Supplies ExifIFD entries for focal length in mm and 35mm equivalent.
     * Verifies both values are read correctly.
     */
    #[Test]
    public function readsFocalLength(): void
    {
        $exifEntries = [
            ExifTag::FOCAL_LENGTH              => new IfdEntry(ExifTag::FOCAL_LENGTH, 5, 1, [50, 1]),
            ExifTag::FOCAL_LENGTH_IN_35MM_FILM => new IfdEntry(ExifTag::FOCAL_LENGTH_IN_35MM_FILM, 3, 1, 75),
        ];

        $reader = $this->createReader($exifEntries, []);

        self::assertEqualsWithDelta(50.0, $reader->focalLengthMm(), 0.001);
        self::assertSame(75, $reader->focalLength35Mm());
        self::assertSame(75, $reader->focalLengthIn35mmFilm());
    }

    /**
     * Supplies focal plane X/Y resolution and resolution unit.
     * Verifies the values are read correctly.
     */
    #[Test]
    public function readsFocalPlaneResolution(): void
    {
        $exifEntries = [
            ExifTag::FOCAL_PLANE_X_RESOLUTION  => new IfdEntry(ExifTag::FOCAL_PLANE_X_RESOLUTION, 5, 1, [8000, 1]),
            ExifTag::FOCAL_PLANE_Y_RESOLUTION  => new IfdEntry(ExifTag::FOCAL_PLANE_Y_RESOLUTION, 5, 1, [8000, 1]),
            ExifTag::FOCAL_PLANE_RESOLUTION_UNIT => new IfdEntry(ExifTag::FOCAL_PLANE_RESOLUTION_UNIT, 3, 1, 3),
        ];

        $reader = $this->createReader($exifEntries, []);

        self::assertEqualsWithDelta(8000.0, $reader->focalPlaneXResolution(), 0.001);
        self::assertEqualsWithDelta(8000.0, $reader->focalPlaneYResolution(), 0.001);
        self::assertSame(3, $reader->focalPlaneResolutionUnit());
    }

    /**
     * Verifies the default focal plane resolution unit is 2 (inches).
     */
    #[Test]
    public function returnsDefaultFocalPlaneResolutionUnit(): void
    {
        $reader = $this->createReader([], []);

        self::assertSame(2, $reader->focalPlaneResolutionUnit());
    }

    /**
     * Verifies FileSource defaults to DSC (3) when no tag is present.
     */
    #[Test]
    public function returnsDefaultFileSource(): void
    {
        $reader = $this->createReader([], []);

        self::assertSame(FileSource::DigitalCamera, $reader->fileSource());
    }

    /**
     * Supplies a FileSource tag with integer value 1 (film scanner).
     * Verifies the enum is resolved correctly.
     */
    #[Test]
    public function readsFileSourceFromExifIfd(): void
    {
        $exifEntries = [
            ExifTag::FILE_SOURCE => new IfdEntry(ExifTag::FILE_SOURCE, 7, 1, FileSource::FilmScanner->value),
        ];

        $reader = $this->createReader($exifEntries, []);

        self::assertSame(FileSource::FilmScanner, $reader->fileSource());
    }

    /**
     * Supplies an interoperability IFD with the InteroperabilityIndex tag.
     * Verifies the index string is read correctly.
     */
    #[Test]
    public function readsInteropIndex(): void
    {
        $interopEntries = [
            ExifTag::INTEROPERABILITY_INDEX => new IfdEntry(ExifTag::INTEROPERABILITY_INDEX, ExifConst::TYPE_ASCII, 4, 'R98'),
        ];

        $reader = $this->createReader([], [], $interopEntries);

        self::assertSame('R98', $reader->interopIndex());
    }

    /**
     * Verifies null is returned for all focal-related fields when no entries are present.
     */
    #[Test]
    public function returnsNullWhenNoEntriesPresent(): void
    {
        $reader = $this->createReader([], []);

        self::assertNull($reader->focalLengthMm());
        self::assertNull($reader->focalLength35Mm());
        self::assertNull($reader->focalPlaneXResolution());
        self::assertNull($reader->focalPlaneYResolution());
        self::assertNull($reader->cfaPattern());
        self::assertNull($reader->cfaPatternColors());
        self::assertNull($reader->interopIndex());
    }

    /**
     * @param array<int, IfdEntry> $exifEntries
     * @param array<int, IfdEntry> $ifd0Entries
     * @param array<int, IfdEntry> $interopEntries
     */
    private function createReader(
        array $exifEntries,
        array $ifd0Entries,
        array $interopEntries = [],
    ): FocalReader {
        $exifIfd    = $exifEntries !== [] ? new Ifd($exifEntries) : null;
        $ifd0       = new Ifd($ifd0Entries);
        $interopIfd = $interopEntries !== [] ? new Ifd($interopEntries) : null;

        return new FocalReader(
            new IfdValueReader(new ValueConverters()),
            $exifIfd,
            $ifd0,
            $interopIfd,
        );
    }
}
