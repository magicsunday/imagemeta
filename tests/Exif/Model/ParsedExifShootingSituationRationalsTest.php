<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Exif\Model;

use MagicSunday\ImageMeta\Exif\Model\ExifRational;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Exif\Model\ParsedExif;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Verifies EXIF shooting-situation rational sentinel handling in ParsedExif.
 *
 * @internal
 */
#[CoversClass(ParsedExif::class)]
final class ParsedExifShootingSituationRationalsTest extends TestCase
{
    /**
     * Ensures Temperature uses EXIF unknown-denominator semantics.
     */
    #[Test]
    public function returnsNullForTemperatureWithUnsignedUnknownDenominator(): void
    {
        $exifIfd = new Ifd([
            ExifTag::TEMPERATURE => new IfdEntry(
                ExifTag::TEMPERATURE,
                10,
                1,
                new ExifRational(250, 0xFFFFFFFF),
            ),
        ]);

        $parsedExif = new ParsedExif(new Ifd([]), $exifIfd, null, null, null);

        self::assertNull($parsedExif->temperatureCelsius());
    }

    /**
     * Ensures Temperature treats signed -1 denominator as unknown.
     */
    #[Test]
    public function returnsNullForTemperatureWithSignedUnknownDenominator(): void
    {
        $exifIfd = new Ifd([
            ExifTag::TEMPERATURE => new IfdEntry(
                ExifTag::TEMPERATURE,
                10,
                1,
                new ExifRational(250, -1),
            ),
        ]);

        $parsedExif = new ParsedExif(new Ifd([]), $exifIfd, null, null, null);

        self::assertNull($parsedExif->temperatureCelsius());
    }
}
