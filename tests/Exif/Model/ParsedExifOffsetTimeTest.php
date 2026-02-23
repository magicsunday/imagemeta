<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Exif\Model;

use DateTimeInterface;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Exif\Model\ParsedExif;
use MagicSunday\ImageMeta\Exif\Reader\CameraLensExifReader;
use MagicSunday\ImageMeta\Exif\Reader\ColorSpaceExifReader;
use MagicSunday\ImageMeta\Exif\Reader\DescriptionExifReader;
use MagicSunday\ImageMeta\Exif\Reader\DngMetadataExifReader;
use MagicSunday\ImageMeta\Exif\Reader\ImageStructureExifReader;
use MagicSunday\ImageMeta\Exif\Reader\UserCommentExifReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function strlen;

/**
 * Exercises OffsetTime tag validation in ParsedExif.
 * It ensures only conformant "±HH:MM" strings are accepted per EXIF 3.0 §4.6.6.6.3–§4.6.6.6.5.
 * The suite covers valid offsets, non-conformant formats, and absent tags.
 * This keeps timezone offset parsing strict and spec-compliant.
 *
 * @internal
 */
#[CoversClass(ParsedExif::class)]
#[UsesClass(CameraLensExifReader::class)]
#[UsesClass(ColorSpaceExifReader::class)]
#[UsesClass(DescriptionExifReader::class)]
#[UsesClass(DngMetadataExifReader::class)]
#[UsesClass(ImageStructureExifReader::class)]
#[UsesClass(UserCommentExifReader::class)]
final class ParsedExifOffsetTimeTest extends TestCase
{
    /**
     * Supplies a valid "+09:00" OffsetTime tag.
     * Confirms offsetTime() returns the canonical "±HH:MM" string.
     *
     * @return void
     */
    #[Test]
    public function acceptsValidPositiveOffset(): void
    {
        $parsedExif = $this->parsedExifWithOffset(
            ExifTag::OFFSET_TIME,
            "+09:00\0",
        );

        self::assertSame('+09:00', $parsedExif->offsetTime());
    }

    /**
     * Supplies a valid "-05:30" OffsetTimeOriginal tag.
     * Confirms offsetTimeOriginal() returns the canonical "±HH:MM" string.
     *
     * @return void
     */
    #[Test]
    public function acceptsValidNegativeOffsetOriginal(): void
    {
        $parsedExif = $this->parsedExifWithOffset(
            ExifTag::OFFSET_TIME_ORIGINAL,
            "-05:30\0",
        );

        self::assertSame('-05:30', $parsedExif->offsetTimeOriginal());
    }

    /**
     * Supplies a valid "+00:00" OffsetTimeDigitized tag for UTC.
     * Confirms offsetTimeDigitized() returns "+00:00".
     *
     * @return void
     */
    #[Test]
    public function acceptsUtcOffsetDigitized(): void
    {
        $parsedExif = $this->parsedExifWithOffset(
            ExifTag::OFFSET_TIME_DIGITIZED,
            "+00:00\0",
        );

        self::assertSame('+00:00', $parsedExif->offsetTimeDigitized());
    }

    /**
     * Supplies "Z" as an OffsetTime value, which is not the spec format.
     * Verifies that non-conformant single-character encodings are rejected.
     *
     * @return void
     */
    #[Test]
    public function rejectsZuluShorthand(): void
    {
        $parsedExif = $this->parsedExifWithOffset(
            ExifTag::OFFSET_TIME,
            "Z\0",
        );

        self::assertNull($parsedExif->offsetTime());
    }

    /**
     * Supplies "UTC-4" as an OffsetTime value.
     * Verifies that named timezone abbreviations are rejected.
     *
     * @return void
     */
    #[Test]
    public function rejectsNamedTimezoneAbbreviation(): void
    {
        $parsedExif = $this->parsedExifWithOffset(
            ExifTag::OFFSET_TIME,
            "UTC-4\0",
        );

        self::assertNull($parsedExif->offsetTime());
    }

    /**
     * Supplies "GMT+9" as an OffsetTime value.
     * Verifies that GMT-prefixed offsets are rejected.
     *
     * @return void
     */
    #[Test]
    public function rejectsGmtPrefixedOffset(): void
    {
        $parsedExif = $this->parsedExifWithOffset(
            ExifTag::OFFSET_TIME_ORIGINAL,
            "GMT+9\0",
        );

        self::assertNull($parsedExif->offsetTimeOriginal());
    }

    /**
     * Supplies "+9:00" with a single-digit hour, which violates the "±HH:MM" format.
     * Verifies that incomplete hour encoding is rejected.
     *
     * @return void
     */
    #[Test]
    public function rejectsSingleDigitHour(): void
    {
        $parsedExif = $this->parsedExifWithOffset(
            ExifTag::OFFSET_TIME_DIGITIZED,
            "+9:00\0",
        );

        self::assertNull($parsedExif->offsetTimeDigitized());
    }

    /**
     * Supplies an empty string as an OffsetTime value.
     * Verifies that blank offsets are rejected.
     *
     * @return void
     */
    #[Test]
    public function rejectsEmptyString(): void
    {
        $parsedExif = $this->parsedExifWithOffset(
            ExifTag::OFFSET_TIME,
            "\0",
        );

        self::assertNull($parsedExif->offsetTime());
    }

    /**
     * Supplies DateTimeOriginal with a canonical OffsetTimeOriginal.
     * Confirms datetime parsing keeps the EXIF offset when it is spec-conformant.
     *
     * @return void
     */
    #[Test]
    public function acceptsCanonicalOffsetForDateTimeParsing(): void
    {
        $parsedExif = $this->parsedExifWithDateTimeAndOffset("2024:06:01 12:34:56\0", "+01:00\0");

        self::assertSame('2024-06-01T12:34:56+01:00', $parsedExif->dateTimeOriginal()?->format(DateTimeInterface::ATOM));
    }

    /**
     * Supplies DateTimeOriginal with a timezone identifier in OffsetTimeOriginal.
     * Verifies identifier-based offsets are rejected in the datetime parsing path.
     *
     * @return void
     */
    #[Test]
    public function rejectsTimezoneIdentifierForDateTimeParsing(): void
    {
        $parsedExif = $this->parsedExifWithDateTimeAndOffset("2024:06:01 12:34:56\0", "Europe/Berlin\0");

        self::assertNull($parsedExif->dateTimeOriginal());
    }

    /**
     * Supplies DateTimeOriginal with malformed OffsetTimeOriginal syntax.
     * Verifies malformed offsets do not bypass EXIF datetime offset validation.
     *
     * @return void
     */
    #[Test]
    public function rejectsMalformedOffsetForDateTimeParsing(): void
    {
        $parsedExif = $this->parsedExifWithDateTimeAndOffset("2024:06:01 12:34:56\0", "+0100\0");

        self::assertNull($parsedExif->dateTimeOriginal());
    }

    /**
     * Supplies a boundary-valid DateTimeOriginal with canonical offset.
     * Confirms semantic boundary values are parsed without normalization.
     *
     * @return void
     */
    #[Test]
    public function acceptsBoundaryDateTimeWithOffset(): void
    {
        $parsedExif = $this->parsedExifWithDateTimeAndOffset("2024:12:31 23:59:59\0", "+00:00\0");

        self::assertSame('2024-12-31T23:59:59+00:00', $parsedExif->dateTimeOriginal()?->format(DateTimeInterface::ATOM));
    }

    /**
     * Supplies calendar/time-overflow values with canonical offset.
     * Verifies invalid datetime combinations are rejected rather than normalized.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('provideInvalidDateTimeOriginalValues')]
    public function rejectsDateTimeOverflowValues(string $dateTime): void
    {
        $parsedExif = $this->parsedExifWithDateTimeAndOffset($dateTime . "\0", "+00:00\0");

        self::assertNull($parsedExif->dateTimeOriginal());
    }

    /**
     * @return iterable<string, array{0:string}>
     */
    public static function provideInvalidDateTimeOriginalValues(): iterable
    {
        yield 'month overflow' => ['2024:13:01 12:00:00'];
        yield 'day overflow' => ['2024:04:31 12:00:00'];
        yield 'hour overflow' => ['2024:01:01 24:00:00'];
    }

    private function parsedExifWithOffset(int $tag, string $value): ParsedExif
    {
        $exifIfd = new Ifd([
            $tag => new IfdEntry(
                $tag,
                2,
                strlen($value),
                rtrim($value, "\0"),
            ),
        ]);

        return new ParsedExif(new Ifd([]), $exifIfd, null, null, null);
    }

    private function parsedExifWithDateTimeAndOffset(string $dateTime, string $offset): ParsedExif
    {
        $exifIfd = new Ifd([
            ExifTag::DATETIME_ORIGINAL => new IfdEntry(
                ExifTag::DATETIME_ORIGINAL,
                2,
                strlen($dateTime),
                rtrim($dateTime, "\0"),
            ),
            ExifTag::OFFSET_TIME_ORIGINAL => new IfdEntry(
                ExifTag::OFFSET_TIME_ORIGINAL,
                2,
                strlen($offset),
                rtrim($offset, "\0"),
            ),
        ]);

        return new ParsedExif(new Ifd([]), $exifIfd, null, null, null);
    }
}
