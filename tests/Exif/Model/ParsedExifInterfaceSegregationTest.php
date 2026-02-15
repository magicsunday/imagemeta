<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Exif\Model;

use MagicSunday\ImageMeta\Exif\Model\ExifGpsData;
use MagicSunday\ImageMeta\Exif\Model\ExifIfd0Data;
use MagicSunday\ImageMeta\Exif\Model\ExifIfd1Data;
use MagicSunday\ImageMeta\Exif\Model\ExifInteropData;
use MagicSunday\ImageMeta\Exif\Model\ExifSubIfdData;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\ParsedExif;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Verifies interface segregation contracts implemented by ParsedExif.
 *
 * @internal
 */
#[CoversClass(ParsedExif::class)]
final class ParsedExifInterfaceSegregationTest extends TestCase
{
    /**
     * Creates a ParsedExif instance with minimal IFD data.
     * Verifies all EXIF area interfaces are implemented for BC-safe consumption.
     *
     * @return void
     */
    #[Test]
    public function parsedExifImplementsAllSegmentInterfaces(): void
    {
        $parsedExif = new ParsedExif(new Ifd([]), null, null, null, null);
        $interfaces = class_implements($parsedExif);

        self::assertContains(ExifIfd0Data::class, $interfaces);
        self::assertContains(ExifIfd1Data::class, $interfaces);
        self::assertContains(ExifSubIfdData::class, $interfaces);
        self::assertContains(ExifGpsData::class, $interfaces);
        self::assertContains(ExifInteropData::class, $interfaces);
    }
}
