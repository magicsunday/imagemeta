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

    #[Test]
    public function isoUsesSensitivityTypePriorities(): void
    {
        $exifIfd = new Ifd([
            ExifTag::SENSITIVITY_TYPE           => new IfdEntry(ExifTag::SENSITIVITY_TYPE, 3, 1, '6'),
            ExifTag::RECOMMENDED_EXPOSURE_INDEX => new IfdEntry(ExifTag::RECOMMENDED_EXPOSURE_INDEX, 3, 1, 200),
            ExifTag::ISO_SPEED                  => new IfdEntry(ExifTag::ISO_SPEED, 3, 1, 400),
        ]);

        $parsedExif = new ParsedExif(new Ifd([]), $exifIfd, null, null, null);

        self::assertSame(200, $parsedExif->iso());
    }
}
