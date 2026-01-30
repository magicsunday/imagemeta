<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Model\Exif;

use MagicSunday\ImageMeta\Model\Exif\ExifRational;
use MagicSunday\ImageMeta\Model\Exif\ExifTag;
use MagicSunday\ImageMeta\Model\Exif\Ifd;
use MagicSunday\ImageMeta\Model\Exif\IfdEntry;
use MagicSunday\ImageMeta\Model\Exif\ParsedExif;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ParsedExif::class)]
final class ParsedExifSubjectDistanceTest extends TestCase
{
    /**
     * Verifies that $parsedExif->subjectDistance() equals 2.0.
     *
     * @return void
     */
    #[Test]
    public function returnsSubjectDistanceFromSpecExample(): void
    {
        $exifIfd = new Ifd([
            ExifTag::SUBJECT_DISTANCE => new IfdEntry(
                ExifTag::SUBJECT_DISTANCE,
                5,
                1,
                new ExifRational(20, 10),
            ),
        ]);

        $parsedExif = new ParsedExif(new Ifd([]), $exifIfd, null, null, null);

        self::assertSame(2.0, $parsedExif->subjectDistance());
    }

    /**
     * Verifies that $parsedExif->subjectDistance() equals INF.
     *
     * @return void
     */
    #[Test]
    public function returnsInfinityWhenSubjectDistanceRecordsInfinity(): void
    {
        $exifIfd = new Ifd([
            ExifTag::SUBJECT_DISTANCE => new IfdEntry(
                ExifTag::SUBJECT_DISTANCE,
                5,
                1,
                new ExifRational(0xFFFFFFFF, 1),
            ),
        ]);

        $parsedExif = new ParsedExif(new Ifd([]), $exifIfd, null, null, null);

        self::assertSame(INF, $parsedExif->subjectDistance());
    }

    /**
     * Verifies that $parsedExif->subjectDistance() is null.
     *
     * @return void
     */
    #[Test]
    public function returnsNullWhenSubjectDistanceIsUnknown(): void
    {
        $exifIfd = new Ifd([
            ExifTag::SUBJECT_DISTANCE => new IfdEntry(
                ExifTag::SUBJECT_DISTANCE,
                5,
                1,
                new ExifRational(0, 10),
            ),
        ]);

        $parsedExif = new ParsedExif(new Ifd([]), $exifIfd, null, null, null);

        self::assertNull($parsedExif->subjectDistance());
    }
}
