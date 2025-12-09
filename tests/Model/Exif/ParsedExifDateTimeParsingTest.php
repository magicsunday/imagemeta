<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Model\Exif;

use DateTimeInterface;
use MagicSunday\ImageMeta\Model\Exif\ExifTag;
use MagicSunday\ImageMeta\Model\Exif\Ifd;
use MagicSunday\ImageMeta\Model\Exif\IfdEntry;
use MagicSunday\ImageMeta\Model\Exif\ParsedExif;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ParsedExif::class)]
final class ParsedExifDateTimeParsingTest extends TestCase
{
    #[Test]
    public function ignoresBlankDateTimeOriginalFields(): void
    {
        $unknownTimestamp = strtr('0000:00:00 00:00:00', ['0' => ' ']) . "\0";

        $exifIfd = new Ifd([
            ExifTag::DATETIME_ORIGINAL => new IfdEntry(
                ExifTag::DATETIME_ORIGINAL,
                2,
                20,
                $unknownTimestamp,
            ),
        ]);

        $parsedExif = new ParsedExif(new Ifd([]), $exifIfd, null, null, null);

        self::assertNull($parsedExif->dateTimeOriginal());
    }

    #[Test]
    public function parsesDateTimeOriginalWithTrailingNull(): void
    {
        $exifIfd = new Ifd([
            ExifTag::DATETIME_ORIGINAL => new IfdEntry(
                ExifTag::DATETIME_ORIGINAL,
                2,
                20,
                '2015:11:10 20:18:59' . "\0",
            ),
        ]);

        $parsedExif = new ParsedExif(new Ifd([]), $exifIfd, null, null, null);

        self::assertSame(
            '2015-11-10T20:18:59+00:00',
            $parsedExif->dateTimeOriginal()?->format(DateTimeInterface::ATOM),
        );
    }
}
