<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Exif\Model;

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
use MagicSunday\ImageMeta\Value\Enum\SensitivityType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Exercises sensitivity-related EXIF tags and their enum conversions.
 * It verifies numeric-string values map to SensitivityType cases correctly.
 * The suite checks standard/recommended exposure indices and related fields.
 * This keeps ISO sensitivity reporting stable across tag variants.
 *
 * @psalm-suppress PropertyNotSetInConstructor
 */
#[CoversClass(ParsedExif::class)]
#[UsesClass(CameraLensExifReader::class)]
#[UsesClass(ColorSpaceExifReader::class)]
#[UsesClass(DescriptionExifReader::class)]
#[UsesClass(DngMetadataExifReader::class)]
#[UsesClass(ImageStructureExifReader::class)]
#[UsesClass(UserCommentExifReader::class)]
final class ParsedExifSensitivityTest extends TestCase
{
    /**
     * Provides the SENSITIVITY_TYPE tag as a numeric string value.
     * Ensures the parser coerces it and returns the matching SensitivityType enum.
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
     * Supplies a STANDARD_OUTPUT_SENSITIVITY tag with a concrete value.
     * Confirms standardOutputSensitivity() returns that integer unchanged.
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
     * Supplies a RECOMMENDED_EXPOSURE_INDEX tag with a numeric value.
     * Ensures recommendedExposureIndex() returns the provided value.
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
     * Provides an ISO_SPEED tag representing the base ISO value.
     * Confirms isoSpeedValue() returns the integer as stored.
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
     * Supplies ISO speed latitude tags without the required ISO_SPEED entry.
     * Ensures isoSpeedLatitudeYyy() returns null until ISO_SPEED is present.
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
     * Uses a data provider to feed sensitivity types with competing tag values.
     * Verifies iso() selects the correct source based on the sensitivity type rules.
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
     * Sets SENSITIVITY_TYPE to an unknown value alongside ISO_SPEED.
     * Confirms iso() falls back to ISO_SPEED when the type is not recognized.
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
     * Provides ISO_SPEED together with both ISO speed latitude tags.
     * Ensures isoSpeedLatitude* and isoLatitude* accessors return the stored values.
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
