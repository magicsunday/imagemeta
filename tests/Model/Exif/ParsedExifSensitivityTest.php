<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Model\Exif;

use MagicSunday\ImageMeta\Model\Exif\ExifTag;
use MagicSunday\ImageMeta\Model\Exif\Ifd;
use MagicSunday\ImageMeta\Model\Exif\IfdEntry;
use MagicSunday\ImageMeta\Model\Exif\ParsedExif;
use MagicSunday\ImageMeta\Value\Enum\SensitivityType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @psalm-suppress PropertyNotSetInConstructor
 */
#[CoversClass(ParsedExif::class)]
final class ParsedExifSensitivityTest extends TestCase
{
    /**
     * Verifies that $parsedExif->sensitivityType() equals SensitivityType::SOS_AND_REI.
     *
     * @return void
     */
    #[Test]
    public function sensitivityTypeReturnsEnumForNumericStrings(): void
    {
        $exifIfd = new Ifd([
            ExifTag::SENSITIVITY_TYPE => new IfdEntry(ExifTag::SENSITIVITY_TYPE, 3, 1, '4'),
        ]);

        $parsedExif = new ParsedExif(new Ifd([]), $exifIfd, null, null, null);

        self::assertSame(SensitivityType::SOS_AND_REI, $parsedExif->sensitivityType());
    }

    /**
     * Verifies that $parsedExif->standardOutputSensitivity() equals 80.
     *
     * @return void
     */
    #[Test]
    public function standardOutputSensitivityReturnsValue(): void
    {
        $exifIfd = new Ifd([
            ExifTag::STANDARD_OUTPUT_SENSITIVITY => new IfdEntry(ExifTag::STANDARD_OUTPUT_SENSITIVITY, 4, 1, 80),
        ]);

        $parsedExif = new ParsedExif(new Ifd([]), $exifIfd, null, null, null);

        self::assertSame(80, $parsedExif->standardOutputSensitivity());
    }

    /**
     * Verifies that $parsedExif->recommendedExposureIndex() equals 160.
     *
     * @return void
     */
    #[Test]
    public function recommendedExposureIndexReturnsValue(): void
    {
        $exifIfd = new Ifd([
            ExifTag::RECOMMENDED_EXPOSURE_INDEX => new IfdEntry(ExifTag::RECOMMENDED_EXPOSURE_INDEX, 4, 1, 160),
        ]);

        $parsedExif = new ParsedExif(new Ifd([]), $exifIfd, null, null, null);

        self::assertSame(160, $parsedExif->recommendedExposureIndex());
    }

    /**
     * Verifies that $parsedExif->isoSpeedValue() equals 400.
     *
     * @return void
     */
    #[Test]
    public function isoSpeedValueReturnsValue(): void
    {
        $exifIfd = new Ifd([
            ExifTag::ISO_SPEED => new IfdEntry(ExifTag::ISO_SPEED, 4, 1, 400),
        ]);

        $parsedExif = new ParsedExif(new Ifd([]), $exifIfd, null, null, null);

        self::assertSame(400, $parsedExif->isoSpeedValue());
    }

    /**
     * Verifies that $parsedExif->isoSpeedLatitudeYyy() is null.
     *
     * @return void
     */
    #[Test]
    public function isoSpeedLatitudeYyyRequiresRelatedTags(): void
    {
        $missingIsoIfd = new Ifd([
            ExifTag::ISO_SPEED_LATITUDE_YYY => new IfdEntry(ExifTag::ISO_SPEED_LATITUDE_YYY, 4, 1, 20),
            ExifTag::ISO_SPEED_LATITUDE_ZZZ => new IfdEntry(ExifTag::ISO_SPEED_LATITUDE_ZZZ, 4, 1, 30),
        ]);

        $parsedExif = new ParsedExif(new Ifd([]), $missingIsoIfd, null, null, null);

        self::assertNull($parsedExif->isoSpeedLatitudeYyy());

        $completeIfd = new Ifd([
            ExifTag::ISO_SPEED              => new IfdEntry(ExifTag::ISO_SPEED, 4, 1, 200),
            ExifTag::ISO_SPEED_LATITUDE_YYY => new IfdEntry(ExifTag::ISO_SPEED_LATITUDE_YYY, 4, 1, 20),
            ExifTag::ISO_SPEED_LATITUDE_ZZZ => new IfdEntry(ExifTag::ISO_SPEED_LATITUDE_ZZZ, 4, 1, 30),
        ]);

        $parsedWithIso = new ParsedExif(new Ifd([]), $completeIfd, null, null, null);

        self::assertSame(20, $parsedWithIso->isoSpeedLatitudeYyy());
    }

    /**
     * Verifies that $parsedExif->iso() equals $expected.
     *
     * @param array<int, int> $tagValues
     *
     * @return void
     */
    #[Test]
    #[DataProvider('isoSensitivityPriorityProvider')]
    public function isoUsesSensitivityTypePriorities(
        SensitivityType $sensitivityType,
        array $tagValues,
        int $expected,
    ): void {
        $entries = [
            ExifTag::SENSITIVITY_TYPE => new IfdEntry(ExifTag::SENSITIVITY_TYPE, 3, 1, $sensitivityType->value),
        ];

        foreach ($tagValues as $tag => $value) {
            $entries[$tag] = new IfdEntry($tag, 3, 1, $value);
        }

        $exifIfd = new Ifd($entries);

        $parsedExif = new ParsedExif(new Ifd([]), $exifIfd, null, null, null);

        self::assertSame($expected, $parsedExif->iso());
    }

    /**
     * Verifies that $parsedExif->iso() equals 1600.
     *
     * @return void
     */
    #[Test]
    public function isoFallsBackWhenSensitivityTypeValueIsUnknown(): void
    {
        $exifIfd = new Ifd([
            ExifTag::SENSITIVITY_TYPE => new IfdEntry(ExifTag::SENSITIVITY_TYPE, 3, 1, 99),
            ExifTag::ISO_SPEED        => new IfdEntry(ExifTag::ISO_SPEED, 3, 1, 1600),
        ]);

        $parsedExif = new ParsedExif(new Ifd([]), $exifIfd, null, null, null);

        self::assertSame(1600, $parsedExif->iso());
    }

    /**
     * Verifies that $parsedExif->isoSpeedLatitudeYyy() equals 90.
     *
     * @return void
     */
    #[Test]
    public function returnsIsoLatitudeValues(): void
    {
        $exifIfd = new Ifd([
            ExifTag::ISO_SPEED              => new IfdEntry(ExifTag::ISO_SPEED, 4, 1, 200),
            ExifTag::ISO_SPEED_LATITUDE_YYY => new IfdEntry(ExifTag::ISO_SPEED_LATITUDE_YYY, 4, 1, 90),
            ExifTag::ISO_SPEED_LATITUDE_ZZZ => new IfdEntry(ExifTag::ISO_SPEED_LATITUDE_ZZZ, 4, 1, 100),
        ]);

        $parsedExif = new ParsedExif(new Ifd([]), $exifIfd, null, null, null);

        self::assertSame(90, $parsedExif->isoSpeedLatitudeYyy());
        self::assertSame(100, $parsedExif->isoSpeedLatitudeZzz());
        self::assertSame(90, $parsedExif->isoLatitudeYyy());
        self::assertSame(100, $parsedExif->isoLatitudeZzz());
    }

    /**
     * @return iterable<string, array{sensitivityType: SensitivityType, tagValues: array<int, int>, expected: int}>
     */
    public static function isoSensitivityPriorityProvider(): iterable
    {
        yield 'standard output sensitivity' => [
            'sensitivityType' => SensitivityType::STANDARD_OUTPUT_SENSITIVITY,
            'tagValues'       => [
                ExifTag::PHOTOGRAPHIC_SENSITIVITY    => 640,
                ExifTag::STANDARD_OUTPUT_SENSITIVITY => 100,
                ExifTag::RECOMMENDED_EXPOSURE_INDEX  => 200,
                ExifTag::ISO_SPEED                   => 300,
            ],
            'expected' => 640,
        ];

        yield 'recommended exposure index' => [
            'sensitivityType' => SensitivityType::RECOMMENDED_EXPOSURE_INDEX,
            'tagValues'       => [
                ExifTag::PHOTOGRAPHIC_SENSITIVITY    => 125,
                ExifTag::STANDARD_OUTPUT_SENSITIVITY => 100,
                ExifTag::RECOMMENDED_EXPOSURE_INDEX  => 200,
                ExifTag::ISO_SPEED                   => 300,
                ExifTag::EXPOSURE_INDEX              => 250,
            ],
            'expected' => 125,
        ];

        yield 'iso speed' => [
            'sensitivityType' => SensitivityType::ISO_SPEED,
            'tagValues'       => [
                ExifTag::PHOTOGRAPHIC_SENSITIVITY    => 320,
                ExifTag::STANDARD_OUTPUT_SENSITIVITY => 100,
                ExifTag::RECOMMENDED_EXPOSURE_INDEX  => 200,
                ExifTag::ISO_SPEED                   => 300,
            ],
            'expected' => 320,
        ];

        yield 'sos and rei' => [
            'sensitivityType' => SensitivityType::SOS_AND_REI,
            'tagValues'       => [
                ExifTag::PHOTOGRAPHIC_SENSITIVITY    => 80,
                ExifTag::STANDARD_OUTPUT_SENSITIVITY => 100,
                ExifTag::RECOMMENDED_EXPOSURE_INDEX  => 200,
                ExifTag::ISO_SPEED                   => 300,
                ExifTag::EXPOSURE_INDEX              => 250,
            ],
            'expected' => 80,
        ];

        yield 'sos and iso' => [
            'sensitivityType' => SensitivityType::SOS_AND_ISO,
            'tagValues'       => [
                ExifTag::PHOTOGRAPHIC_SENSITIVITY    => 400,
                ExifTag::STANDARD_OUTPUT_SENSITIVITY => 100,
                ExifTag::RECOMMENDED_EXPOSURE_INDEX  => 200,
                ExifTag::ISO_SPEED                   => 300,
            ],
            'expected' => 400,
        ];

        yield 'rei and iso' => [
            'sensitivityType' => SensitivityType::REI_AND_ISO,
            'tagValues'       => [
                ExifTag::PHOTOGRAPHIC_SENSITIVITY    => 250,
                ExifTag::STANDARD_OUTPUT_SENSITIVITY => 100,
                ExifTag::RECOMMENDED_EXPOSURE_INDEX  => 200,
                ExifTag::ISO_SPEED                   => 300,
                ExifTag::EXPOSURE_INDEX              => 250,
            ],
            'expected' => 250,
        ];

        yield 'sos and rei and iso' => [
            'sensitivityType' => SensitivityType::SOS_AND_REI_AND_ISO,
            'tagValues'       => [
                ExifTag::PHOTOGRAPHIC_SENSITIVITY    => 160,
                ExifTag::STANDARD_OUTPUT_SENSITIVITY => 100,
                ExifTag::RECOMMENDED_EXPOSURE_INDEX  => 200,
                ExifTag::ISO_SPEED                   => 300,
                ExifTag::EXPOSURE_INDEX              => 250,
            ],
            'expected' => 160,
        ];

        yield 'unknown sensitivity type' => [
            'sensitivityType' => SensitivityType::UNKNOWN,
            'tagValues'       => [
                ExifTag::ISO_SPEED                => 400,
                ExifTag::PHOTOGRAPHIC_SENSITIVITY => 500,
            ],
            'expected' => 500,
        ];
    }
}
