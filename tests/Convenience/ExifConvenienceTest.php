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
use MagicSunday\ImageMeta\Model\Exif\Value\ExifNumericList;
use MagicSunday\ImageMeta\Model\Exif\Value\ExifRational;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MagicSunday\ImageMeta\Convenience\ExifConvenience
 */
#[CoversClass(ExifConvenience::class)]
final class ExifConvenienceTest extends TestCase
{
    /**
     * Provides both modern and legacy ISO sensitivity tags to ensure the helper prefers the
     * EXIF-specific entry and falls back when absent.
     */
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

    /**
     * Ensures the ISO helper returns the first value from array-based entries rather than the raw
     * array.
     */
    #[Test]
    public function isoExtractsFirstEntryFromArray(): void
    {
        $value = new ExifNumericList([100, 200, 400]);
        $ifd0  = new Ifd([
            ExifTag::PHOTOGRAPHIC_SENSITIVITY => new IfdEntry(ExifTag::PHOTOGRAPHIC_SENSITIVITY, 3, count($value), $value),
        ]);

        $doc = new ExifDocument($ifd0, null, null, null, null);

        self::assertSame(100, ExifConvenience::iso($doc));
    }

    /**
     * Ensures ISO values stored as floating point scalars are truncated to integers.
     */
    #[Test]
    public function isoCastsFloatValuesToInteger(): void
    {
        $value = new ExifNumericList([200.0, 400.0]);
        $ifd0  = new Ifd([
            ExifTag::PHOTOGRAPHIC_SENSITIVITY => new IfdEntry(ExifTag::PHOTOGRAPHIC_SENSITIVITY, 11, count($value), $value),
        ]);

        $doc = new ExifDocument($ifd0, null, null, null, null);

        self::assertSame(200, ExifConvenience::iso($doc));
    }

    /**
     * Ensures ISO rational pairs are interpreted via their numerator/denominator representation.
     */
    #[Test]
    public function isoDerivesValueFromRationalPair(): void
    {
        $ifd0 = new Ifd([
            ExifTag::PHOTOGRAPHIC_SENSITIVITY => new IfdEntry(ExifTag::PHOTOGRAPHIC_SENSITIVITY, 5, 1, new ExifRational(320, 1)),
        ]);

        $doc = new ExifDocument($ifd0, null, null, null, null);

        self::assertSame(320, ExifConvenience::iso($doc));
    }

    /**
     * Reads capture timestamp tags and verifies the helper delegates to the document parser to
     * return a timezone-aware DateTime.
     */
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

    /**
     * Confirms null is returned when neither the IFD0 nor EXIF IFD provide capture date
     * information.
     */
    #[Test]
    public function captureDateTimeReturnsNullWhenMissing(): void
    {
        $doc = new ExifDocument(new Ifd([]), new Ifd([]), null, null, null);

        self::assertNull(ExifConvenience::captureDateTime($doc));
    }

    /**
     * Supplies an incomplete timestamp string to ensure the helper refuses values lacking time
     * components.
     */
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
