<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Exif\Factory;

use MagicSunday\ImageMeta\Exif\Factory\ExposureFactory;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Exif\Model\ParsedExif;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Value\FlashInfo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Exercises ExposureFactory for mapping EXIF exposure tags to Exposure values.
 * It verifies ISO, flash state, and exposure parameters are derived correctly.
 * The suite checks FlashInfo construction and proper handling of missing fields.
 * This ensures exposure metadata is normalized consistently from EXIF input.
 *
 * @internal
 */
#[CoversClass(ExposureFactory::class)]
final class ExposureFactoryTest extends TestCase
{
    /**
     * Builds ParsedExif data with ISO and flash tags and passes it through ExposureFactory.
     * Verifies ISO is set and FlashInfo indicates the flash fired.
     */
    #[Test]
    public function createsFromExifMetadata(): void
    {
        $parsedExif = $this->parsedExifWithIsoAndFlash(100, 0x0001);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            exifDoc: $parsedExif,
        );

        $factory  = new ExposureFactory();
        $exposure = $factory->create($metadata);

        self::assertNotNull($exposure->settings);
        self::assertSame(100, $exposure->settings->iso);
        self::assertInstanceOf(FlashInfo::class, $exposure->flash);
        self::assertTrue($exposure->flash->fired);
    }

    /**
     * Creates Metadata without an EXIF document attached.
     * Ensures ISO is null while FlashInfo is still instantiated with defaults.
     */
    #[Test]
    public function createsWithNullExifDoc(): void
    {
        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
        );

        $factory  = new ExposureFactory();
        $exposure = $factory->create($metadata);

        self::assertNotNull($exposure->settings);
        self::assertNull($exposure->settings->iso);
        self::assertInstanceOf(FlashInfo::class, $exposure->flash);
        self::assertFalse($exposure->flash->fired);
    }

    /**
     * Supplies only flash information without ISO data in the EXIF IFD.
     * Confirms flash details are parsed even when ISO is missing.
     */
    #[Test]
    public function parsesFlashInformation(): void
    {
        $parsedExif = $this->parsedExifWithIsoAndFlash(null, 0x0019);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            exifDoc: $parsedExif,
        );

        $factory  = new ExposureFactory();
        $exposure = $factory->create($metadata);

        self::assertNotNull($exposure->settings);
        self::assertNull($exposure->settings->iso);
        self::assertInstanceOf(FlashInfo::class, $exposure->flash);
        self::assertTrue($exposure->flash->fired);
    }

    /**
     * Supplies an IFD entry with a wrong TIFF type for ISO (ASCII instead of SHORT).
     * Verifies the factory degrades gracefully and returns null ISO.
     */
    #[Test]
    public function returnsNullIsoWhenTagHasWrongType(): void
    {
        $entries = [
            ExifTag::PHOTOGRAPHIC_SENSITIVITY => new IfdEntry(
                ExifTag::PHOTOGRAPHIC_SENSITIVITY,
                2,
                3,
                'abc',
            ),
        ];

        $ifd0    = new Ifd([]);
        $exifIfd = new Ifd($entries);

        $parsedExif = new ParsedExif(
            ifd0: $ifd0,
            exifIfd: $exifIfd,
            gpsIfd: null,
            interopIfd: null,
            ifd1: null,
        );

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            exifDoc: $parsedExif,
        );

        $factory  = new ExposureFactory();
        $exposure = $factory->create($metadata);

        self::assertNotNull($exposure->settings);
        self::assertNull($exposure->settings->iso);
    }

    /**
     * Supplies IFD entries with empty EXIF IFD (no flash, no ISO).
     * Confirms flash defaults to not-fired and ISO stays null.
     */
    #[Test]
    public function emptyExifIfdYieldsDefaultFlashAndNullIso(): void
    {
        $ifd0    = new Ifd([]);
        $exifIfd = new Ifd([]);

        $parsedExif = new ParsedExif(
            ifd0: $ifd0,
            exifIfd: $exifIfd,
            gpsIfd: null,
            interopIfd: null,
            ifd1: null,
        );

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            exifDoc: $parsedExif,
        );

        $factory  = new ExposureFactory();
        $exposure = $factory->create($metadata);

        self::assertNotNull($exposure->settings);
        self::assertNull($exposure->settings->iso);
        self::assertInstanceOf(FlashInfo::class, $exposure->flash);
        self::assertFalse($exposure->flash->fired);
    }

    private function parsedExifWithIsoAndFlash(?int $iso, ?int $flash): ParsedExif
    {
        $entries = [];

        if ($iso !== null) {
            $entries[ExifTag::PHOTOGRAPHIC_SENSITIVITY] = new IfdEntry(
                ExifTag::PHOTOGRAPHIC_SENSITIVITY,
                3,
                1,
                $iso,
            );
        }

        if ($flash !== null) {
            $entries[ExifTag::FLASH] = new IfdEntry(
                ExifTag::FLASH,
                3,
                1,
                $flash,
            );
        }

        $ifd0    = new Ifd([]);
        $exifIfd = new Ifd($entries);

        return new ParsedExif(
            ifd0: $ifd0,
            exifIfd: $exifIfd,
            gpsIfd: null,
            interopIfd: null,
            ifd1: null,
        );
    }
}
