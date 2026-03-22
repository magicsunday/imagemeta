<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Exif\Model;

use DateTimeImmutable;
use MagicSunday\ImageMeta\Exif\Converters\ApexConverter;
use MagicSunday\ImageMeta\Exif\Converters\ComponentsConverter;
use MagicSunday\ImageMeta\Exif\Converters\ConverterFactory;
use MagicSunday\ImageMeta\Exif\Converters\EnumConverter;
use MagicSunday\ImageMeta\Exif\Converters\GpsConverter;
use MagicSunday\ImageMeta\Exif\Converters\GpsCoordinateConverter;
use MagicSunday\ImageMeta\Exif\Converters\GpsDirectionConverter;
use MagicSunday\ImageMeta\Exif\Converters\GpsTimestampConverter;
use MagicSunday\ImageMeta\Exif\Converters\GpsUnitConverter;
use MagicSunday\ImageMeta\Exif\Converters\MatrixConverter;
use MagicSunday\ImageMeta\Exif\Converters\NumericConverter;
use MagicSunday\ImageMeta\Exif\Converters\RationalConverter;
use MagicSunday\ImageMeta\Exif\Converters\StringConverter;
use MagicSunday\ImageMeta\Exif\ExifCapabilities;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\FallbackIfdSet;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Exif\Model\IfdValueReader;
use MagicSunday\ImageMeta\Exif\Model\ParsedExif;
use MagicSunday\ImageMeta\Exif\Reader\CameraLensExifReader;
use MagicSunday\ImageMeta\Exif\Reader\ColorSpaceExifReader;
use MagicSunday\ImageMeta\Exif\Reader\DescriptionExifReader;
use MagicSunday\ImageMeta\Exif\Reader\DngMetadataExifReader;
use MagicSunday\ImageMeta\Exif\Reader\GpsExifReader;
use MagicSunday\ImageMeta\Exif\Reader\ImageStructureExifReader;
use MagicSunday\ImageMeta\Exif\Reader\TemporalExifReader;
use MagicSunday\ImageMeta\Exif\Reader\UserCommentExifReader;
use MagicSunday\ImageMeta\Exif\ValueConverters;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function strlen;

/**
 * Exercises strict DateTime format validation in ParsedExif.
 * It validates that EXIF 3.0 §4.6.5.4.5 / §4.6.6.6.1 / §4.6.6.6.2 format is enforced.
 * The suite covers valid and invalid DateTime string formats.
 * This ensures non-conformant datetime values are rejected.
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
#[UsesClass(ApexConverter::class)]
#[UsesClass(ComponentsConverter::class)]
#[UsesClass(ConverterFactory::class)]
#[UsesClass(EnumConverter::class)]
#[UsesClass(GpsConverter::class)]
#[UsesClass(GpsCoordinateConverter::class)]
#[UsesClass(GpsDirectionConverter::class)]
#[UsesClass(GpsTimestampConverter::class)]
#[UsesClass(GpsUnitConverter::class)]
#[UsesClass(MatrixConverter::class)]
#[UsesClass(NumericConverter::class)]
#[UsesClass(RationalConverter::class)]
#[UsesClass(StringConverter::class)]
#[UsesClass(ExifCapabilities::class)]
#[UsesClass(FallbackIfdSet::class)]
#[UsesClass(Ifd::class)]
#[UsesClass(IfdEntry::class)]
#[UsesClass(IfdValueReader::class)]
#[UsesClass(GpsExifReader::class)]
#[UsesClass(TemporalExifReader::class)]
#[UsesClass(ValueConverters::class)]
final class ParsedExifDateTimeFormatTest extends TestCase
{
    /**
     * Supplies a valid "YYYY:MM:DD HH:MM:SS" DateTimeOriginal without OffsetTimeOriginal.
     * Confirms a local timestamp is emitted without timezone when offset is missing.
     * Pre-EXIF 2.31 files lack OffsetTime* tags but still carry valid date/time values.
     */
    #[Test]
    public function emitsLocalTimestampWhenOffsetMissing(): void
    {
        $parsedExif = $this->parsedExifWithDateTime(
            ExifTag::DATETIME_ORIGINAL,
            "2023:06:15 14:30:00\0",
        );

        $dt = $parsedExif->dateTimeOriginal();
        self::assertInstanceOf(DateTimeImmutable::class, $dt);
        self::assertSame('2023-06-15 14:30:00', $dt->format('Y-m-d H:i:s'));
    }

    /**
     * Supplies a DateTime with dashes instead of colons in the date.
     * Confirms the parser rejects non-conformant date separators.
     */
    #[Test]
    public function rejectsDashSeparatedDate(): void
    {
        $parsedExif = $this->parsedExifWithDateTime(
            ExifTag::DATETIME_ORIGINAL,
            "2023-06-15 14:30:00\0",
        );

        self::assertNull($parsedExif->dateTimeOriginal());
    }

    /**
     * Supplies a DateTime using ISO 8601 T-separator instead of space.
     * Confirms the parser rejects non-conformant separators.
     */
    #[Test]
    public function rejectsIso8601TSeparator(): void
    {
        $parsedExif = $this->parsedExifWithDateTime(
            ExifTag::DATETIME_ORIGINAL,
            "2023:06:15T14:30:00\0",
        );

        self::assertNull($parsedExif->dateTimeOriginal());
    }

    /**
     * Supplies a DateTime that is too short (missing seconds).
     * Confirms the parser rejects truncated datetime strings.
     */
    #[Test]
    public function rejectsTruncatedDateTime(): void
    {
        $parsedExif = $this->parsedExifWithDateTime(
            ExifTag::DATETIME_ORIGINAL,
            "2023:06:15 14:30\0",
        );

        self::assertNull($parsedExif->dateTimeOriginal());
    }

    /**
     * Supplies a DateTime with alphabetic characters in the time.
     * Confirms the parser rejects non-numeric components.
     */
    #[Test]
    public function rejectsAlphabeticComponents(): void
    {
        $parsedExif = $this->parsedExifWithDateTime(
            ExifTag::DATETIME,
            "2023:06:15 ab:cd:ef\0",
        );

        self::assertNull($parsedExif->dateTime());
    }

    private function parsedExifWithDateTime(int $tag, string $value): ParsedExif
    {
        $trimmed = rtrim($value, "\0");

        if ($tag === ExifTag::DATETIME) {
            $ifd0 = new Ifd([
                $tag => new IfdEntry($tag, 2, strlen($value), $trimmed),
            ]);

            return new ParsedExif($ifd0, new Ifd([]), null, null, null);
        }

        $exifIfd = new Ifd([
            $tag => new IfdEntry($tag, 2, strlen($value), $trimmed),
        ]);

        return new ParsedExif(new Ifd([]), $exifIfd, null, null, null);
    }
}
