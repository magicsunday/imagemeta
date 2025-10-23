<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Convenience;

use MagicSunday\ImageMeta\Convenience\ExifConvenience;
use MagicSunday\ImageMeta\Model\Exif\ExifDocument;
use MagicSunday\ImageMeta\Model\Exif\ExifTag;
use MagicSunday\ImageMeta\Model\Exif\Ifd;
use MagicSunday\ImageMeta\Model\Exif\IfdEntry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MagicSunday\ImageMeta\Convenience\ExifConvenience
 */
#[CoversClass(ExifConvenience::class)]
final class ExifConvenienceTest extends TestCase
{
    #[Test]
    public function isoPrefersModernTagAndFallsBackToLegacy(): void
    {
        $ifd0 = new Ifd([
            ExifTag::PHOTOGRAPHIC_SENSITIVITY => new IfdEntry(ExifTag::PHOTOGRAPHIC_SENSITIVITY, 3, 1, 200),
        ]);

        $exifIfd = new Ifd([
            ExifTag::ISO_SPEED => new IfdEntry(ExifTag::ISO_SPEED, 3, 1, 320),
        ]);

        $doc = new ExifDocument($ifd0, $exifIfd, null, null, null);

        self::assertSame(320, ExifConvenience::iso($doc));

        $docWithLegacy = new ExifDocument($ifd0, null, null, null, null);

        self::assertSame(200, ExifConvenience::iso($docWithLegacy));
    }

    #[Test]
    public function isoExtractsFirstEntryFromArray(): void
    {
        $ifd0 = new Ifd([
            ExifTag::PHOTOGRAPHIC_SENSITIVITY => new IfdEntry(ExifTag::PHOTOGRAPHIC_SENSITIVITY, 3, 3, [100, 200, 400]),
        ]);

        $doc = new ExifDocument($ifd0, null, null, null, null);

        self::assertSame(100, ExifConvenience::iso($doc));
    }

    #[Test]
    public function captureDateTimeDelegatesToDocument(): void
    {
        $ifd0 = new Ifd([]);

        $exifIfd = new Ifd([
            ExifTag::DATETIME_ORIGINAL    => new IfdEntry(ExifTag::DATETIME_ORIGINAL, 2, 1, '2024:05:01 12:34:56'),
            ExifTag::OFFSET_TIME_ORIGINAL => new IfdEntry(ExifTag::OFFSET_TIME_ORIGINAL, 2, 1, '+02:00'),
        ]);

        $doc      = new ExifDocument($ifd0, $exifIfd, null, null, null);
        $captured = ExifConvenience::captureDateTime($doc);

        self::assertNotNull($captured);
        self::assertSame('2024-05-01T12:34:56+02:00', $captured->format(DATE_ATOM));
    }

    #[Test]
    public function captureDateTimeReturnsNullWhenMissing(): void
    {
        $doc = new ExifDocument(new Ifd([]), new Ifd([]), null, null, null);

        self::assertNull(ExifConvenience::captureDateTime($doc));
    }

    #[Test]
    public function captureDateTimeReturnsNullForShortTimestamp(): void
    {
        $ifd0 = new Ifd([]);

        $exifIfd = new Ifd([
            ExifTag::DATETIME_ORIGINAL => new IfdEntry(ExifTag::DATETIME_ORIGINAL, 2, 1, '2024:05:01'),
        ]);

        $doc = new ExifDocument($ifd0, $exifIfd, null, null, null);

        self::assertNull(ExifConvenience::captureDateTime($doc));
    }
}
