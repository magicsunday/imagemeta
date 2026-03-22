<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Factory\Structured;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\ImageMeta\Core\Util\DateTimeUtil;
use MagicSunday\ImageMeta\Exif\Converters\ApexConverter;
use MagicSunday\ImageMeta\Exif\Converters\ComponentsConverter;
use MagicSunday\ImageMeta\Exif\Converters\ConverterFactory;
use MagicSunday\ImageMeta\Exif\Converters\DateTimeConverter;
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
use MagicSunday\ImageMeta\Exif\Reader\GpsExifReader;
use MagicSunday\ImageMeta\Exif\Reader\TemporalExifReader;
use MagicSunday\ImageMeta\Exif\ValueConverters;
use MagicSunday\ImageMeta\Factory\Structured\TemporalFactory;
use MagicSunday\ImageMeta\MakerNotes\Apple\Support\QuickTimeLookup;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Model\QuickTime\QuickTimeMeta;
use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;
use MagicSunday\ImageMeta\Value\Temporal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

use function array_map;
use function date_default_timezone_get;
use function date_default_timezone_set;
use function strlen;

/**
 * Exercises TemporalFactory for assembling Temporal values from EXIF/XMP/QuickTime tags.
 * It verifies create/modify/original timestamps and offset time components are preserved.
 * The suite covers sub-second fields and fallback precedence across metadata sources.
 * This ensures temporal metadata is normalized consistently for structured output.
 *
 * @internal
 */
#[CoversClass(TemporalFactory::class)]
#[UsesClass(XmpDocument::class)]
#[UsesClass(DateTimeUtil::class)]
#[UsesClass(ApexConverter::class)]
#[UsesClass(ComponentsConverter::class)]
#[UsesClass(ConverterFactory::class)]
#[UsesClass(DateTimeConverter::class)]
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
#[UsesClass(ParsedExif::class)]
#[UsesClass(GpsExifReader::class)]
#[UsesClass(TemporalExifReader::class)]
#[UsesClass(ValueConverters::class)]
#[UsesClass(QuickTimeLookup::class)]
#[UsesClass(Metadata::class)]
#[UsesClass(QuickTimeMeta::class)]
#[UsesClass(Temporal::class)]
final class TemporalFactoryTest extends TestCase
{
    #[Test]
    public function usesDedicatedFallbackDateHelper(): void
    {
        $reflection = new ReflectionClass(TemporalFactory::class);
        $methods    = array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            $reflection->getMethods(ReflectionMethod::IS_PRIVATE),
        );

        self::assertContains('parseFirstAvailableDate', $methods);
    }

    /**
     * Supplies EXIF date/time tags with offsets and sub-second components.
     * Verifies TemporalFactory produces DateTime values and preserves sub-second strings.
     */
    #[Test]
    public function createsFromExifMetadata(): void
    {
        $temporal = $this->createTemporal(
            exifDoc: $this->parsedExif(
                create: new DateTimeImmutable('2023-06-15T14:30:00+02:00'),
                modify: new DateTimeImmutable('2023-06-16T10:00:00+02:00'),
                original: new DateTimeImmutable('2023-06-15T14:00:00+02:00'),
                offsetTime: '+02:00',
                offsetTimeOriginal: '+02:00',
                offsetTimeDigitized: '+02:00',
                subSecTime: '500',
                subSecTimeOriginal: '500',
                subSecTimeDigitized: '500',
            ),
        );

        self::assertInstanceOf(DateTimeImmutable::class, $temporal->create);
        self::assertInstanceOf(DateTimeImmutable::class, $temporal->modify);
        self::assertInstanceOf(DateTimeImmutable::class, $temporal->original);
        self::assertNotNull($temporal->tz);
        self::assertSame('500', $temporal->subSecTime);
        self::assertSame('500', $temporal->subSecTimeOriginal);
        self::assertSame('500', $temporal->subSecTimeDigitized);
    }

    /**
     * Omits EXIF timestamps while providing XMP CreateDate and ModifyDate.
     * Ensures the factory falls back to XMP timestamps when EXIF is missing.
     */
    #[Test]
    public function fallsBackToXmpTimestamps(): void
    {
        $temporal = $this->createTemporal(
            exifDoc: $this->parsedExif(),
            xmpDoc: new XmpDocument([
                '{http://ns.adobe.com/exif/1.0/}CreateDate' => '2023-06-15T14:30:00+02:00',
                '{http://ns.adobe.com/exif/1.0/}ModifyDate' => '2023-06-16T10:00:00+02:00',
            ]),
        );

        self::assertInstanceOf(DateTimeImmutable::class, $temporal->create);
        self::assertInstanceOf(DateTimeImmutable::class, $temporal->modify);
    }

    /**
     * Omits EXIF timestamps while providing QuickTime CreationDate and ModifyDate.
     * Confirms QuickTime metadata is used as the fallback source.
     */
    #[Test]
    public function fallsBackToQuickTimeTimestamps(): void
    {
        $temporal = $this->createTemporal(
            exifDoc: $this->parsedExif(),
            quickTime: new QuickTimeMeta([
                'CreationDate' => '2023-06-15T14:30:00+02:00',
                'ModifyDate'   => '2023-06-16T10:00:00+02:00',
            ]),
        );

        self::assertInstanceOf(DateTimeImmutable::class, $temporal->create);
        self::assertInstanceOf(DateTimeImmutable::class, $temporal->modify);
    }

    /**
     * Provides sub-second strings of varying length in EXIF tags.
     * Verifies the factory right-pads each value to three digits.
     *
     * EXIF 3.0 §4.6.6.6.6 — digits are aligned with the start of the area
     * (fractional seconds: "9" = 0.9s, "50" = 0.50s, "5000" truncated to "500" = 0.500s).
     */
    #[Test]
    public function sanitizesSubSeconds(): void
    {
        $temporal = $this->createTemporal(
            exifDoc: $this->parsedExif(
                subSecTime: '9',
                subSecTimeOriginal: '12',
                subSecTimeDigitized: '123',
            ),
        );

        // "9" = 0.9s → right-padded to "900"
        self::assertSame('900', $temporal->subSecTime);
        // "12" = 0.12s → right-padded to "120"
        self::assertSame('120', $temporal->subSecTimeOriginal);
        // "123" = 0.123s → unchanged
        self::assertSame('123', $temporal->subSecTimeDigitized);
    }

    /**
     * Verifies truncation of sub-second strings longer than 3 digits.
     *
     * EXIF 3.0 §4.6.6.6.6 — only the first three fractional digits are retained.
     */
    #[Test]
    public function truncatesSubSecondsLongerThanThreeDigits(): void
    {
        $temporal = $this->createTemporal(
            exifDoc: $this->parsedExif(
                subSecTime: '5000',
            ),
        );

        self::assertSame('500', $temporal->subSecTime);
    }

    /**
     * Creates Metadata without EXIF, XMP, or QuickTime timestamps.
     * Ensures all temporal fields remain null when no sources are available.
     */
    #[Test]
    public function createsWithNullMetadata(): void
    {
        $temporal = $this->createTemporal();

        self::assertNull($temporal->create);
        self::assertNull($temporal->modify);
        self::assertNull($temporal->original);
        self::assertNull($temporal->tz);
    }

    /**
     * Provides a non-ISO free-form date string in XMP CreateDate.
     * Expects the factory to reject it and return null for the create field.
     */
    #[Test]
    public function xmpNonIsoDateStringIsRejected(): void
    {
        $temporal = $this->createTemporal(
            xmpDoc: new XmpDocument([
                '{http://ns.adobe.com/xap/1.0/}CreateDate' => 'June 15, 2023 2:30 PM',
            ]),
        );

        self::assertNull($temporal->create);
    }

    /**
     * Provides a valid ISO 8601 date string in XMP CreateDate.
     * Confirms it is accepted and parsed correctly.
     */
    #[Test]
    public function xmpValidIsoDateStringIsAccepted(): void
    {
        $temporal = $this->createTemporal(
            xmpDoc: new XmpDocument([
                '{http://ns.adobe.com/xap/1.0/}CreateDate' => '2023-06-15T14:30:00+02:00',
            ]),
        );

        self::assertInstanceOf(DateTimeImmutable::class, $temporal->create);
        self::assertSame('2023-06-15', $temporal->create->format('Y-m-d'));
    }

    /**
     * Sets the process timezone to America/New_York and provides a timezone-less XMP date.
     * Expects the factory to treat it as UTC, not local time.
     */
    #[Test]
    public function xmpTimezoneLessDateIsTreatedAsUtc(): void
    {
        $previous = date_default_timezone_get();

        try {
            date_default_timezone_set('America/New_York');

            $temporal = $this->createTemporal(
                xmpDoc: new XmpDocument([
                    '{http://ns.adobe.com/xap/1.0/}CreateDate' => '2023-06-15T14:30:00',
                ]),
            );

            self::assertInstanceOf(DateTimeImmutable::class, $temporal->create);
            self::assertSame('14:30:00', $temporal->create->setTimezone(new DateTimeZone('UTC'))->format('H:i:s'));
        } finally {
            date_default_timezone_set($previous);
        }
    }

    /**
     * Supplies a sub-second value containing non-numeric characters.
     * Verifies the factory strips non-digits and normalizes the result.
     */
    #[Test]
    public function sanitizesNonNumericSubSeconds(): void
    {
        $temporal = $this->createTemporal(
            exifDoc: $this->parsedExif(
                subSecTime: 'abc',
            ),
        );

        // Non-numeric sub-seconds should be stripped to null
        self::assertNull($temporal->subSecTime);
    }

    /**
     * Supplies an EXIF date/time value that is all zeros (0000:00:00 00:00:00).
     * Verifies the factory treats it as missing rather than returning an epoch date.
     */
    #[Test]
    public function handlesAllZerosDateTimeAsNull(): void
    {
        $temporal = $this->createTemporal(
            exifDoc: $this->parsedExifFromEntries(
                ifd0Entries: [
                    ExifTag::DATETIME => new IfdEntry(
                        ExifTag::DATETIME,
                        2,
                        20,
                        '0000:00:00 00:00:00',
                    ),
                ],
            ),
        );

        // An all-zeros date should not produce a valid modify timestamp
        self::assertNull($temporal->modify);
    }

    /**
     * Omits all EXIF, XMP, and QuickTime string timestamps while providing
     * only mvhd Mac-epoch integers in QuickTimeMeta.
     * Verifies the factory converts Mac-epoch seconds to DateTimeImmutable as a last-resort fallback.
     */
    #[Test]
    public function fallsBackToMvhdMacEpochTimestamps(): void
    {
        $temporal = $this->createTemporal(
            quickTime: new QuickTimeMeta([
                QuickTimeMeta::CREATE_DATE_KEY => 3_692_304_000,
                QuickTimeMeta::MODIFY_DATE_KEY => 3_692_390_400,
            ]),
        );

        self::assertInstanceOf(DateTimeImmutable::class, $temporal->create);
        self::assertSame('2021-01-01', $temporal->create->format('Y-m-d'));
        self::assertInstanceOf(DateTimeImmutable::class, $temporal->modify);
        self::assertSame('2021-01-02', $temporal->modify->format('Y-m-d'));
    }

    /**
     * Omits all EXIF timestamps while providing only mvhd Mac-epoch integers.
     * Verifies the factory uses the resolved create date as fallback for the original field.
     */
    #[Test]
    public function originalFallsBackToQuickTimeCreateDate(): void
    {
        $temporal = $this->createTemporal(
            quickTime: new QuickTimeMeta([
                QuickTimeMeta::CREATE_DATE_KEY => 3_692_304_000,
            ]),
        );

        self::assertInstanceOf(DateTimeImmutable::class, $temporal->original);
        self::assertSame('2021-01-01', $temporal->original->format('Y-m-d'));
    }

    /**
     * Omits mvhd timestamps while providing tkhd Mac-epoch integers.
     * Verifies the factory falls back to tkhd timestamps when mvhd is absent.
     */
    #[Test]
    public function fallsBackToTkhdMacEpochTimestamps(): void
    {
        $temporal = $this->createTemporal(
            quickTime: new QuickTimeMeta([
                QuickTimeMeta::TRACK_CREATE_DATE_KEY => 3_692_304_000,
                QuickTimeMeta::TRACK_MODIFY_DATE_KEY => 3_692_390_400,
            ]),
        );

        self::assertInstanceOf(DateTimeImmutable::class, $temporal->create);
        self::assertSame('2021-01-01', $temporal->create->format('Y-m-d'));
        self::assertInstanceOf(DateTimeImmutable::class, $temporal->modify);
        self::assertSame('2021-01-02', $temporal->modify->format('Y-m-d'));
    }

    /**
     * Omits mvhd and tkhd timestamps while providing mdhd Mac-epoch integers.
     * Verifies the factory falls back to mdhd timestamps as last resort.
     */
    #[Test]
    public function fallsBackToMdhdMacEpochTimestamps(): void
    {
        $temporal = $this->createTemporal(
            quickTime: new QuickTimeMeta([
                QuickTimeMeta::MEDIA_CREATE_DATE_KEY => 3_692_304_000,
                QuickTimeMeta::MEDIA_MODIFY_DATE_KEY => 3_692_390_400,
            ]),
        );

        self::assertInstanceOf(DateTimeImmutable::class, $temporal->create);
        self::assertSame('2021-01-01', $temporal->create->format('Y-m-d'));
        self::assertInstanceOf(DateTimeImmutable::class, $temporal->modify);
        self::assertSame('2021-01-02', $temporal->modify->format('Y-m-d'));
    }

    /**
     * Provides mvhd, tkhd and mdhd Mac-epoch integers with distinct dates.
     * Verifies mvhd takes precedence over tkhd and mdhd.
     */
    #[Test]
    public function mvhdTakesPrecedenceOverTkhdAndMdhd(): void
    {
        $temporal = $this->createTemporal(
            quickTime: new QuickTimeMeta([
                QuickTimeMeta::CREATE_DATE_KEY       => 3_692_304_000, // 2021-01-01
                QuickTimeMeta::TRACK_CREATE_DATE_KEY => 3_723_840_000, // 2022-01-01
                QuickTimeMeta::MEDIA_CREATE_DATE_KEY => 3_755_376_000, // 2023-01-01
                QuickTimeMeta::MODIFY_DATE_KEY       => 3_692_390_400, // 2021-01-02
                QuickTimeMeta::TRACK_MODIFY_DATE_KEY => 3_723_926_400, // 2022-01-02
                QuickTimeMeta::MEDIA_MODIFY_DATE_KEY => 3_755_462_400, // 2023-01-02
            ]),
        );

        self::assertInstanceOf(DateTimeImmutable::class, $temporal->create);
        self::assertSame('2021-01-01', $temporal->create->format('Y-m-d'));
        self::assertInstanceOf(DateTimeImmutable::class, $temporal->modify);
        self::assertSame('2021-01-02', $temporal->modify->format('Y-m-d'));
    }

    /**
     * Provides zero mvhd timestamps alongside valid tkhd Mac-epoch integers.
     * Verifies tkhd is used when mvhd is uninitialised.
     */
    #[Test]
    public function tkhdUsedWhenMvhdIsZero(): void
    {
        $temporal = $this->createTemporal(
            quickTime: new QuickTimeMeta([
                QuickTimeMeta::CREATE_DATE_KEY       => 0,
                QuickTimeMeta::MODIFY_DATE_KEY       => 0,
                QuickTimeMeta::TRACK_CREATE_DATE_KEY => 3_692_304_000, // 2021-01-01
                QuickTimeMeta::TRACK_MODIFY_DATE_KEY => 3_692_390_400, // 2021-01-02
            ]),
        );

        self::assertInstanceOf(DateTimeImmutable::class, $temporal->create);
        self::assertSame('2021-01-01', $temporal->create->format('Y-m-d'));
        self::assertInstanceOf(DateTimeImmutable::class, $temporal->modify);
        self::assertSame('2021-01-02', $temporal->modify->format('Y-m-d'));
    }

    /**
     * Provides mvhd Mac-epoch timestamps set to zero (uninitialised mvhd fields).
     * Verifies the factory treats zero timestamps as missing and returns null.
     */
    #[Test]
    public function mvhdZeroTimestampsAreIgnored(): void
    {
        $temporal = $this->createTemporal(
            quickTime: new QuickTimeMeta([
                QuickTimeMeta::CREATE_DATE_KEY => 0,
                QuickTimeMeta::MODIFY_DATE_KEY => 0,
            ]),
        );

        self::assertNull($temporal->create);
        self::assertNull($temporal->modify);
    }

    /**
     * Provides a QuickTime Keys CreationDate with timezone offset in compact format (+0200).
     * Verifies the factory parses the compact timezone and uses it for create and tz fields.
     */
    #[Test]
    public function extractsTimezoneFromQuickTimeKeysCreationDate(): void
    {
        $temporal = $this->createTemporal(
            quickTime: new QuickTimeMeta([
                'com.apple.quicktime.creationdate' => '2025-06-05T17:53:44+0200',
            ]),
        );

        self::assertInstanceOf(DateTimeImmutable::class, $temporal->create);
        self::assertSame('2025-06-05T17:53:44+02:00', $temporal->create->format('c'));
        self::assertInstanceOf(DateTimeZone::class, $temporal->tz);
        self::assertSame('+02:00', $temporal->tz->getName());
        self::assertSame('KeysCreationDate', $temporal->tzSource);
    }

    /**
     * Provides a QuickTime Keys CreationDate with colon-separated timezone offset (+02:00).
     * Verifies the factory handles the standard ISO 8601 timezone format from Keys metadata.
     */
    #[Test]
    public function extractsTimezoneFromQuickTimeKeysCreationDateWithColon(): void
    {
        $temporal = $this->createTemporal(
            quickTime: new QuickTimeMeta([
                'com.apple.quicktime.creationdate' => '2025-06-05T17:53:44+02:00',
            ]),
        );

        self::assertInstanceOf(DateTimeImmutable::class, $temporal->create);
        self::assertSame('2025-06-05T17:53:44+02:00', $temporal->create->format('c'));
        self::assertInstanceOf(DateTimeZone::class, $temporal->tz);
        self::assertSame('+02:00', $temporal->tz->getName());
    }

    /**
     * Provides both EXIF OffsetTimeOriginal and QuickTime Keys CreationDate with timezone.
     * Verifies the EXIF timezone takes precedence over the QuickTime Keys timezone.
     */
    #[Test]
    public function exifTimezoneTakesPrecedenceOverQuickTimeKeys(): void
    {
        $temporal = $this->createTemporal(
            exifDoc: $this->parsedExif(
                original: new DateTimeImmutable('2025-06-05T17:53:44+02:00'),
                offsetTimeOriginal: '+02:00',
            ),
            quickTime: new QuickTimeMeta([
                'com.apple.quicktime.creationdate' => '2025-06-05T17:53:44+0300',
            ]),
        );

        self::assertInstanceOf(DateTimeZone::class, $temporal->tz);
        self::assertSame('+02:00', $temporal->tz->getName());
        self::assertSame('OffsetTimeOriginal', $temporal->tzSource);
    }

    /**
     * Provides QuickTime Keys CreationDate with timezone alongside mvhd Mac-epoch timestamp.
     * Verifies the Keys value (with timezone) is preferred over the mvhd UTC fallback.
     */
    #[Test]
    public function keysCreationDatePreferredOverMvhdFallback(): void
    {
        $temporal = $this->createTemporal(
            quickTime: new QuickTimeMeta([
                'com.apple.quicktime.creationdate' => '2025-06-05T17:53:44+0200',
                QuickTimeMeta::CREATE_DATE_KEY     => 3_831_983_624,
            ]),
        );

        self::assertInstanceOf(DateTimeImmutable::class, $temporal->create);
        self::assertSame('2025-06-05T17:53:44+02:00', $temporal->create->format('c'));
    }

    /**
     * Provides a QuickTime Keys CreationDate with UTC timezone (Z suffix).
     * Verifies the factory does not extract UTC as a meaningful timezone.
     */
    #[Test]
    public function quickTimeKeysUtcTimezoneDoesNotSetTz(): void
    {
        $temporal = $this->createTemporal(
            quickTime: new QuickTimeMeta([
                'com.apple.quicktime.creationdate' => '2025-06-05T15:53:44Z',
            ]),
        );

        self::assertInstanceOf(DateTimeImmutable::class, $temporal->create);
        self::assertNull($temporal->tz);
    }

    /**
     * Supplies an empty offset time string in the EXIF IFD.
     * Verifies the factory handles an empty offset without crashing.
     */
    #[Test]
    public function handlesEmptyOffsetTimeGracefully(): void
    {
        $temporal = $this->createTemporal(
            exifDoc: $this->parsedExifFromEntries(
                exifEntries: [
                    ExifTag::OFFSET_TIME => new IfdEntry(
                        ExifTag::OFFSET_TIME,
                        2,
                        1,
                        '',
                    ),
                ],
            ),
        );

        // Empty offset should not produce a timezone
        self::assertNull($temporal->tz);
    }

    private function createTemporal(
        ?ParsedExif $exifDoc = null,
        ?QuickTimeMeta $quickTime = null,
        ?XmpDocument $xmpDoc = null,
    ): Temporal {
        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: $quickTime,
            exifDoc: $exifDoc,
            xmpDoc: $xmpDoc,
        );

        return new TemporalFactory()->create($metadata);
    }

    /**
     * @param array<int, IfdEntry> $ifd0Entries
     * @param array<int, IfdEntry> $exifEntries
     */
    private function parsedExifFromEntries(array $ifd0Entries = [], array $exifEntries = []): ParsedExif
    {
        return new ParsedExif(
            ifd0: new Ifd($ifd0Entries),
            exifIfd: new Ifd($exifEntries),
            gpsIfd: null,
            interopIfd: null,
            ifd1: null,
        );
    }

    private function parsedExif(
        ?DateTimeImmutable $create = null,
        ?DateTimeImmutable $modify = null,
        ?DateTimeImmutable $original = null,
        ?string $offsetTime = null,
        ?string $offsetTimeOriginal = null,
        ?string $offsetTimeDigitized = null,
        ?string $subSecTime = null,
        ?string $subSecTimeOriginal = null,
        ?string $subSecTimeDigitized = null,
    ): ParsedExif {
        $ifd0Entries = [];
        $exifEntries = [];

        if ($modify instanceof DateTimeImmutable) {
            $ifd0Entries[ExifTag::DATETIME] = new IfdEntry(
                ExifTag::DATETIME,
                2,
                20,
                $modify->format('Y:m:d H:i:s'),
            );
        }

        if ($create instanceof DateTimeImmutable) {
            $exifEntries[ExifTag::DATETIME_DIGITIZED] = new IfdEntry(
                ExifTag::DATETIME_DIGITIZED,
                2,
                20,
                $create->format('Y:m:d H:i:s'),
            );
        }

        if ($original instanceof DateTimeImmutable) {
            $exifEntries[ExifTag::DATETIME_ORIGINAL] = new IfdEntry(
                ExifTag::DATETIME_ORIGINAL,
                2,
                20,
                $original->format('Y:m:d H:i:s'),
            );
        }

        if ($offsetTime !== null) {
            $exifEntries[ExifTag::OFFSET_TIME] = new IfdEntry(
                ExifTag::OFFSET_TIME,
                2,
                strlen($offsetTime),
                $offsetTime,
            );
        }

        if ($offsetTimeOriginal !== null) {
            $exifEntries[ExifTag::OFFSET_TIME_ORIGINAL] = new IfdEntry(
                ExifTag::OFFSET_TIME_ORIGINAL,
                2,
                strlen($offsetTimeOriginal),
                $offsetTimeOriginal,
            );
        }

        if ($offsetTimeDigitized !== null) {
            $exifEntries[ExifTag::OFFSET_TIME_DIGITIZED] = new IfdEntry(
                ExifTag::OFFSET_TIME_DIGITIZED,
                2,
                strlen($offsetTimeDigitized),
                $offsetTimeDigitized,
            );
        }

        if ($subSecTime !== null) {
            $exifEntries[ExifTag::SUB_SEC_TIME] = new IfdEntry(
                ExifTag::SUB_SEC_TIME,
                2,
                strlen($subSecTime),
                $subSecTime,
            );
        }

        if ($subSecTimeOriginal !== null) {
            $exifEntries[ExifTag::SUB_SEC_TIME_ORIGINAL] = new IfdEntry(
                ExifTag::SUB_SEC_TIME_ORIGINAL,
                2,
                strlen($subSecTimeOriginal),
                $subSecTimeOriginal,
            );
        }

        if ($subSecTimeDigitized !== null) {
            $exifEntries[ExifTag::SUB_SEC_TIME_DIGITIZED] = new IfdEntry(
                ExifTag::SUB_SEC_TIME_DIGITIZED,
                2,
                strlen($subSecTimeDigitized),
                $subSecTimeDigitized,
            );
        }

        return $this->parsedExifFromEntries($ifd0Entries, $exifEntries);
    }
}
