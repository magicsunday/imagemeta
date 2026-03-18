<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Exif\Reader;

use DateTimeImmutable;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\FallbackIfdSet;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Exif\Model\IfdValueReader;
use MagicSunday\ImageMeta\Exif\Reader\GpsExifReader;
use MagicSunday\ImageMeta\Exif\Reader\TemporalExifReader;
use MagicSunday\ImageMeta\Exif\ValueConverters;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Exercises TemporalExifReader::dateTime() to verify EXIF datetime parsing
 * with timezone offsets and subsecond precision.
 *
 * @internal
 */
#[CoversClass(TemporalExifReader::class)]
#[UsesClass(Ifd::class)]
#[UsesClass(IfdEntry::class)]
#[UsesClass(IfdValueReader::class)]
#[UsesClass(ValueConverters::class)]
#[UsesClass(FallbackIfdSet::class)]
#[UsesClass(GpsExifReader::class)]
final class TemporalExifReaderTest extends TestCase
{
    /**
     * Parses a plain EXIF datetime without offset or subseconds.
     */
    #[Test]
    public function parsesPlainDateTime(): void
    {
        $reader = $this->createReader(
            ifd0Entries: [
                ExifTag::DATETIME => new IfdEntry(ExifTag::DATETIME, 2, 20, "2024:01:15 10:30:00\0"),
            ],
        );

        $dt     = $reader->dateTime();

        self::assertInstanceOf(DateTimeImmutable::class, $dt);
        self::assertSame('2024-01-15 10:30:00', $dt->format('Y-m-d H:i:s'));
    }

    /**
     * Parses an EXIF datetime with a timezone offset.
     */
    #[Test]
    public function parsesDateTimeWithOffset(): void
    {
        $reader = $this->createReader(
            ifd0Entries: [
                ExifTag::DATETIME => new IfdEntry(ExifTag::DATETIME, 2, 20, "2024:01:15 10:30:00\0"),
            ],
            exifEntries: [
                ExifTag::OFFSET_TIME => new IfdEntry(ExifTag::OFFSET_TIME, 2, 7, "+05:30\0"),
            ],
        );

        $dt     = $reader->dateTime();

        self::assertInstanceOf(DateTimeImmutable::class, $dt);
        self::assertSame('2024-01-15 10:30:00', $dt->format('Y-m-d H:i:s'));
        self::assertSame('+05:30', $dt->format('P'));
    }

    /**
     * Parses an EXIF datetime with subsecond precision.
     */
    #[Test]
    public function parsesDateTimeWithSubSeconds(): void
    {
        $reader = $this->createReader(
            ifd0Entries: [
                ExifTag::DATETIME => new IfdEntry(ExifTag::DATETIME, 2, 20, "2024:01:15 10:30:00\0"),
            ],
            exifEntries: [
                ExifTag::SUB_SEC_TIME => new IfdEntry(ExifTag::SUB_SEC_TIME, 2, 4, "123\0"),
            ],
        );

        $dt     = $reader->dateTime();

        self::assertInstanceOf(DateTimeImmutable::class, $dt);
        self::assertSame('123000', $dt->format('u'));
    }

    /**
     * Parses an EXIF datetime with both offset and subseconds.
     */
    #[Test]
    public function parsesDateTimeWithOffsetAndSubSeconds(): void
    {
        $reader = $this->createReader(
            ifd0Entries: [
                ExifTag::DATETIME => new IfdEntry(ExifTag::DATETIME, 2, 20, "2024:01:15 10:30:00\0"),
            ],
            exifEntries: [
                ExifTag::OFFSET_TIME  => new IfdEntry(ExifTag::OFFSET_TIME, 2, 7, "-08:00\0"),
                ExifTag::SUB_SEC_TIME => new IfdEntry(ExifTag::SUB_SEC_TIME, 2, 7, "456789\0"),
            ],
        );

        $dt     = $reader->dateTime();

        self::assertInstanceOf(DateTimeImmutable::class, $dt);
        self::assertSame('2024-01-15 10:30:00', $dt->format('Y-m-d H:i:s'));
        self::assertSame('-08:00', $dt->format('P'));
        self::assertSame('456789', $dt->format('u'));
    }

    /**
     * Returns null for an empty datetime string.
     */
    #[Test]
    public function returnsNullForEmptyDateTime(): void
    {
        $reader = $this->createReader(
            ifd0Entries: [],
        );

        self::assertNull($reader->dateTime());
    }

    /**
     * Returns null for a malformed datetime string.
     */
    #[Test]
    public function returnsNullForMalformedDateTime(): void
    {
        $reader = $this->createReader(
            ifd0Entries: [
                ExifTag::DATETIME => new IfdEntry(ExifTag::DATETIME, 2, 20, "not-a-datetime-val\0"),
            ],
        );

        self::assertNull($reader->dateTime());
    }

    /**
     * @param array<int, IfdEntry> $ifd0Entries
     * @param array<int, IfdEntry> $exifEntries
     */
    private function createReader(array $ifd0Entries, array $exifEntries = []): TemporalExifReader
    {
        $converters = new ValueConverters();
        $ifd0       = new Ifd($ifd0Entries);
        $exifIfd    = $exifEntries !== [] ? new Ifd($exifEntries) : null;

        return new TemporalExifReader(
            new IfdValueReader($converters),
            $converters,
            $exifIfd,
            $ifd0,
            new FallbackIfdSet(null, [], [], $ifd0),
            new GpsExifReader($converters, null),
        );
    }
}
