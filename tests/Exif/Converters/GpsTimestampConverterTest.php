<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Exif\Converters;

use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Exif\Converters\GpsTimestampConverter;
use MagicSunday\ImageMeta\Exif\Converters\NumericConverter;
use MagicSunday\ImageMeta\Exif\Converters\RationalConverter;
use MagicSunday\ImageMeta\Exif\Converters\StringConverter;
use MagicSunday\ImageMeta\Exif\Model\ExifRational;
use MagicSunday\ImageMeta\Exif\Model\ExifRationalList;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

use function array_map;

/**
 * Exercises GpsTimestampConverter extraction of GPS date, time and timestamp fields.
 * It verifies valid date/time combinations, fractional seconds, empty IFDs,
 * invalid dates and out-of-range time components per EXIF 3.0 §4.6.7.
 *
 * @internal
 */
#[CoversClass(GpsTimestampConverter::class)]
#[UsesClass(RationalConverter::class)]
#[UsesClass(NumericConverter::class)]
#[UsesClass(StringConverter::class)]
#[UsesClass(ExifRational::class)]
#[UsesClass(ExifRationalList::class)]
#[UsesClass(Ifd::class)]
#[UsesClass(IfdEntry::class)]
#[UsesClass(ParseError::class)]
final class GpsTimestampConverterTest extends TestCase
{
    private GpsTimestampConverter $converter;

    protected function setUp(): void
    {
        parent::setUp();

        $numericConverter  = new NumericConverter();
        $rationalConverter = new RationalConverter($numericConverter);
        $stringConverter   = new StringConverter();

        $this->converter = new GpsTimestampConverter($rationalConverter, $stringConverter);
    }

    /**
     * Guards duplicate-reduction refactors by requiring a dedicated invalid-date result helper.
     */
    #[Test]
    public function normalizeDateUsesDedicatedInvalidResultHelper(): void
    {
        $reflection = new ReflectionClass(GpsTimestampConverter::class);
        $methods    = array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            $reflection->getMethods(ReflectionMethod::IS_PRIVATE),
        );

        self::assertContains('invalidDateResult', $methods);
    }

    /**
     * Verifies that a valid date and integer time produce correct date, time and timestamp.
     */
    #[Test]
    public function extractsValidDateAndTime(): void
    {
        $result = $this->converter->extractFromIfd(
            $this->buildIfd('2025:03:15', [[10, 1], [30, 1], [45, 1]]),
        );

        self::assertSame('2025-03-15', $result['date']);
        self::assertSame('2025:03:15', $result['date_raw']);
        self::assertSame('10:30:45', $result['time']);
        self::assertSame('2025-03-15T10:30:45+00:00', $result['timestamp']?->format('c'));
    }

    /**
     * Verifies that fractional seconds are formatted with trailing-zero-trimmed decimals.
     */
    #[Test]
    public function extractsFractionalSeconds(): void
    {
        $result = $this->converter->extractFromIfd(
            $this->buildIfd('2025:03:15', [[12, 1], [0, 1], [30500, 1000]]),
        );

        self::assertSame('12:00:30.5', $result['time']);
    }

    /**
     * Verifies that an empty IFD produces all-null results.
     */
    #[Test]
    public function returnsAllNullsForEmptyIfd(): void
    {
        $result = $this->converter->extractFromIfd(new Ifd([]));

        self::assertNull($result['date']);
        self::assertNull($result['date_raw']);
        self::assertNull($result['time']);
        self::assertNull($result['timestamp']);
        self::assertNull($result['processing_method']);
        self::assertNull($result['area_information']);
    }

    /**
     * Tolerates a date with wrong separators (returns null timestamp).
     */
    #[Test]
    public function toleratesInvalidDateFormat(): void
    {
        $result = $this->converter->extractFromIfd(
            $this->buildIfd('2025-03-15', [[12, 1], [0, 1], [0, 1]]),
        );

        self::assertNull($result['date']);
        self::assertNull($result['timestamp']);
    }

    /**
     * Tolerates a date that does not exist on the calendar (returns null timestamp).
     */
    #[Test]
    public function toleratesInvalidCalendarDate(): void
    {
        $result = $this->converter->extractFromIfd(
            $this->buildIfd('2025:02:30', [[12, 1], [0, 1], [0, 1]]),
        );

        self::assertNull($result['date']);
        self::assertNull($result['timestamp']);
    }

    /**
     * Tolerates out-of-range GPS time components (returns null time/timestamp).
     *
     * @param list<array{0:int,1:int}> $timeRationals
     */
    #[Test]
    #[DataProvider('provideOutOfRangeTimeComponents')]
    public function toleratesOutOfRangeTimeComponents(array $timeRationals): void
    {
        $result = $this->converter->extractFromIfd(
            $this->buildIfd('2025:03:15', $timeRationals),
        );

        self::assertNull($result['time']);
        self::assertNull($result['timestamp']);
    }

    /**
     * @return iterable<string, array{0: list<array{0:int,1:int}>}>
     */
    public static function provideOutOfRangeTimeComponents(): iterable
    {
        yield 'hours above 23' => [[[24, 1], [0, 1], [0, 1]]];
        yield 'minutes above 59' => [[[12, 1], [60, 1], [0, 1]]];
        yield 'seconds equal 60' => [[[12, 1], [30, 1], [60, 1]]];
    }

    /**
     * Verifies that a date-only IFD (no time entry) populates date but leaves time null.
     */
    #[Test]
    public function extractsDateOnlyWithoutTimeEntry(): void
    {
        $gps = new Ifd([
            ExifTag::GPS_DATE_STAMP => new IfdEntry(ExifTag::GPS_DATE_STAMP, 2, 11, '2025:03:15'),
        ]);

        $result = $this->converter->extractFromIfd($gps);

        self::assertSame('2025-03-15', $result['date']);
        self::assertSame('2025:03:15', $result['date_raw']);
        self::assertNull($result['time']);
        self::assertNull($result['timestamp']);
    }

    /**
     * @param list<array{0:int,1:int}> $timeRationals
     */
    private function buildIfd(string $date, array $timeRationals): Ifd
    {
        $timeValues = [];

        foreach ($timeRationals as [$num, $den]) {
            $timeValues[] = new ExifRational($num, $den);
        }

        return new Ifd([
            ExifTag::GPS_DATE_STAMP => new IfdEntry(ExifTag::GPS_DATE_STAMP, 2, 11, $date),
            ExifTag::GPS_TIME_STAMP => new IfdEntry(
                ExifTag::GPS_TIME_STAMP,
                5,
                3,
                new ExifRationalList($timeValues),
            ),
        ]);
    }
}
