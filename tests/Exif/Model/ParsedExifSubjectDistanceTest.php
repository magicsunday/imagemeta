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
 * Exercises subject distance decoding from EXIF rational values.
 * It verifies conversion to meters and handling of the infinity sentinel.
 * The suite ensures invalid or missing values return null rather than bogus distances.
 * This keeps subject distance metadata safe for downstream calculations.
 *
 * @internal
 */
#[CoversClass(ParsedExif::class)]
final class ParsedExifSubjectDistanceTest extends TestCase
{
    /**
     * Converts subject distance rationals to meters.
     * It exercises the scenario described by the test name.
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
     * Returns infinity when the EXIF sentinel represents an infinite distance.
     * It exercises the scenario described by the test name.
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
     * Treats zero distance values as unknown.
     * It ensures missing or invalid inputs yield no value.
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
