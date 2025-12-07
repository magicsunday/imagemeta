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
     * @param array<int, int> $tagValues
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
     * @return iterable<string, array{sensitivityType: SensitivityType, tagValues: array<int, int>, expected: int}>
     */
    public static function isoSensitivityPriorityProvider(): iterable
    {
        yield 'standard output sensitivity' => [
            'sensitivityType' => SensitivityType::STANDARD_OUTPUT_SENSITIVITY,
            'tagValues'       => [
                ExifTag::STANDARD_OUTPUT_SENSITIVITY => 100,
                ExifTag::RECOMMENDED_EXPOSURE_INDEX  => 200,
                ExifTag::ISO_SPEED                   => 300,
            ],
            'expected' => 100,
        ];

        yield 'recommended exposure index' => [
            'sensitivityType' => SensitivityType::RECOMMENDED_EXPOSURE_INDEX,
            'tagValues'       => [
                ExifTag::STANDARD_OUTPUT_SENSITIVITY => 100,
                ExifTag::RECOMMENDED_EXPOSURE_INDEX  => 200,
                ExifTag::ISO_SPEED                   => 300,
                ExifTag::EXPOSURE_INDEX              => 250,
            ],
            'expected' => 200,
        ];

        yield 'iso speed' => [
            'sensitivityType' => SensitivityType::ISO_SPEED,
            'tagValues'       => [
                ExifTag::STANDARD_OUTPUT_SENSITIVITY => 100,
                ExifTag::RECOMMENDED_EXPOSURE_INDEX  => 200,
                ExifTag::ISO_SPEED                   => 300,
                ExifTag::PHOTOGRAPHIC_SENSITIVITY    => 320,
            ],
            'expected' => 320,
        ];

        yield 'sos and rei' => [
            'sensitivityType' => SensitivityType::SOS_AND_REI,
            'tagValues'       => [
                ExifTag::STANDARD_OUTPUT_SENSITIVITY => 100,
                ExifTag::RECOMMENDED_EXPOSURE_INDEX  => 200,
                ExifTag::ISO_SPEED                   => 300,
                ExifTag::EXPOSURE_INDEX              => 250,
            ],
            'expected' => 100,
        ];

        yield 'sos and iso' => [
            'sensitivityType' => SensitivityType::SOS_AND_ISO,
            'tagValues'       => [
                ExifTag::STANDARD_OUTPUT_SENSITIVITY => 100,
                ExifTag::RECOMMENDED_EXPOSURE_INDEX  => 200,
                ExifTag::ISO_SPEED                   => 300,
                ExifTag::PHOTOGRAPHIC_SENSITIVITY    => 320,
            ],
            'expected' => 100,
        ];

        yield 'rei and iso' => [
            'sensitivityType' => SensitivityType::REI_AND_ISO,
            'tagValues'       => [
                ExifTag::STANDARD_OUTPUT_SENSITIVITY => 100,
                ExifTag::RECOMMENDED_EXPOSURE_INDEX  => 200,
                ExifTag::ISO_SPEED                   => 300,
                ExifTag::PHOTOGRAPHIC_SENSITIVITY    => 320,
                ExifTag::EXPOSURE_INDEX              => 250,
            ],
            'expected' => 200,
        ];

        yield 'sos and rei and iso' => [
            'sensitivityType' => SensitivityType::SOS_AND_REI_AND_ISO,
            'tagValues'       => [
                ExifTag::STANDARD_OUTPUT_SENSITIVITY => 100,
                ExifTag::RECOMMENDED_EXPOSURE_INDEX  => 200,
                ExifTag::ISO_SPEED                   => 300,
                ExifTag::PHOTOGRAPHIC_SENSITIVITY    => 320,
                ExifTag::EXPOSURE_INDEX              => 250,
            ],
            'expected' => 100,
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
