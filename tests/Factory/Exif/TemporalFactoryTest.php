<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Factory\Exif;

use DateTimeImmutable;
use MagicSunday\ImageMeta\Factory\Exif\TemporalFactory;
use MagicSunday\ImageMeta\Model\Exif\ExifTag;
use MagicSunday\ImageMeta\Model\Exif\Ifd;
use MagicSunday\ImageMeta\Model\Exif\IfdEntry;
use MagicSunday\ImageMeta\Model\Exif\ParsedExif;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Model\QuickTimeMeta;
use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function strlen;

#[CoversClass(TemporalFactory::class)]
final class TemporalFactoryTest extends TestCase
{
    /**
     * Verifies that $temporal->create is instance of DateTimeImmutable::class.
     *
     * @return void
     */
    #[Test]
    public function createsFromExifMetadata(): void
    {
        $parsedExif = $this->parsedExif(
            create: new DateTimeImmutable('2023-06-15T14:30:00+02:00'),
            modify: new DateTimeImmutable('2023-06-16T10:00:00+02:00'),
            original: new DateTimeImmutable('2023-06-15T14:00:00+02:00'),
            offsetTime: '+02:00',
            offsetTimeOriginal: '+02:00',
            offsetTimeDigitized: '+02:00',
            subSecTime: '500',
            subSecTimeOriginal: '500',
            subSecTimeDigitized: '500',
        );

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            exifDoc: $parsedExif,
        );

        $factory  = new TemporalFactory();
        $temporal = $factory->create($metadata);

        self::assertInstanceOf(DateTimeImmutable::class, $temporal->create);
        self::assertInstanceOf(DateTimeImmutable::class, $temporal->modify);
        self::assertInstanceOf(DateTimeImmutable::class, $temporal->original);
        self::assertNotNull($temporal->tz);
        self::assertSame('500', $temporal->subSecTime);
        self::assertSame('500', $temporal->subSecTimeOriginal);
        self::assertSame('500', $temporal->subSecTimeDigitized);
    }

    /**
     * Verifies that $temporal->create is instance of DateTimeImmutable::class.
     *
     * @return void
     */
    #[Test]
    public function fallsBackToXmpTimestamps(): void
    {
        $parsedExif = $this->parsedExif();

        $xmp = new XmpDocument([
            '{http://ns.adobe.com/exif/1.0/}CreateDate' => '2023-06-15T14:30:00+02:00',
            '{http://ns.adobe.com/exif/1.0/}ModifyDate' => '2023-06-16T10:00:00+02:00',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            exifDoc: $parsedExif,
            xmpDoc: $xmp,
        );

        $factory  = new TemporalFactory();
        $temporal = $factory->create($metadata);

        self::assertInstanceOf(DateTimeImmutable::class, $temporal->create);
        self::assertInstanceOf(DateTimeImmutable::class, $temporal->modify);
    }

    /**
     * Verifies that $temporal->create is instance of DateTimeImmutable::class.
     *
     * @return void
     */
    #[Test]
    public function fallsBackToQuickTimeTimestamps(): void
    {
        $parsedExif = $this->parsedExif();

        $quickTime = new QuickTimeMeta([
            'CreationDate' => '2023-06-15T14:30:00+02:00',
            'ModifyDate'   => '2023-06-16T10:00:00+02:00',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: $quickTime,
            exifDoc: $parsedExif,
        );

        $factory  = new TemporalFactory();
        $temporal = $factory->create($metadata);

        self::assertInstanceOf(DateTimeImmutable::class, $temporal->create);
        self::assertInstanceOf(DateTimeImmutable::class, $temporal->modify);
    }

    /**
     * Verifies that $temporal->subSecTime equals '005'.
     *
     * @return void
     */
    #[Test]
    public function sanitizesSubSeconds(): void
    {
        $parsedExif = $this->parsedExif(
            subSecTime: '5',
            subSecTimeOriginal: '50',
            subSecTimeDigitized: '5000',
        );

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            exifDoc: $parsedExif,
        );

        $factory  = new TemporalFactory();
        $temporal = $factory->create($metadata);

        self::assertSame('005', $temporal->subSecTime);
        self::assertSame('050', $temporal->subSecTimeOriginal);
        self::assertSame('500', $temporal->subSecTimeDigitized);
    }

    /**
     * Verifies that $temporal->create is null.
     *
     * @return void
     */
    #[Test]
    public function createsWithNullMetadata(): void
    {
        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
        );

        $factory  = new TemporalFactory();
        $temporal = $factory->create($metadata);

        self::assertNull($temporal->create);
        self::assertNull($temporal->modify);
        self::assertNull($temporal->original);
        self::assertNull($temporal->tz);
    }

    private function parsedExif(
        ?DateTimeImmutable $create = null,
        ?DateTimeImmutable $modify = null,
        ?DateTimeImmutable $original = null,
        ?string $offsetTime = null,
        ?string $offsetTimeOriginal = null,
        ?string $offsetTimeDigitized = null,
        ?string $subSecTime = null,
        ?string $subSecTimeOriginal = null,
        ?string $subSecTimeDigitized = null,
    ): ParsedExif {
        $ifd0Entries   = [];
        $exifEntries   = [];
        $hasAnyEntries = false;

        if ($modify instanceof DateTimeImmutable) {
            $ifd0Entries[ExifTag::DATETIME] = new IfdEntry(
                ExifTag::DATETIME,
                2,
                20,
                $modify->format('Y:m:d H:i:s'),
            );
            $hasAnyEntries = true;
        }

        if ($create instanceof DateTimeImmutable) {
            $exifEntries[ExifTag::DATETIME_DIGITIZED] = new IfdEntry(
                ExifTag::DATETIME_DIGITIZED,
                2,
                20,
                $create->format('Y:m:d H:i:s'),
            );
            $hasAnyEntries = true;
        }

        if ($original instanceof DateTimeImmutable) {
            $exifEntries[ExifTag::DATETIME_ORIGINAL] = new IfdEntry(
                ExifTag::DATETIME_ORIGINAL,
                2,
                20,
                $original->format('Y:m:d H:i:s'),
            );
            $hasAnyEntries = true;
        }

        if ($offsetTime !== null) {
            $exifEntries[ExifTag::OFFSET_TIME] = new IfdEntry(
                ExifTag::OFFSET_TIME,
                2,
                strlen($offsetTime),
                $offsetTime,
            );
            $hasAnyEntries = true;
        }

        if ($offsetTimeOriginal !== null) {
            $exifEntries[ExifTag::OFFSET_TIME_ORIGINAL] = new IfdEntry(
                ExifTag::OFFSET_TIME_ORIGINAL,
                2,
                strlen($offsetTimeOriginal),
                $offsetTimeOriginal,
            );
            $hasAnyEntries = true;
        }

        if ($offsetTimeDigitized !== null) {
            $exifEntries[ExifTag::OFFSET_TIME_DIGITIZED] = new IfdEntry(
                ExifTag::OFFSET_TIME_DIGITIZED,
                2,
                strlen($offsetTimeDigitized),
                $offsetTimeDigitized,
            );
            $hasAnyEntries = true;
        }

        if ($subSecTime !== null) {
            $exifEntries[ExifTag::SUB_SEC_TIME] = new IfdEntry(
                ExifTag::SUB_SEC_TIME,
                2,
                strlen($subSecTime),
                $subSecTime,
            );
            $hasAnyEntries = true;
        }

        if ($subSecTimeOriginal !== null) {
            $exifEntries[ExifTag::SUB_SEC_TIME_ORIGINAL] = new IfdEntry(
                ExifTag::SUB_SEC_TIME_ORIGINAL,
                2,
                strlen($subSecTimeOriginal),
                $subSecTimeOriginal,
            );
            $hasAnyEntries = true;
        }

        if ($subSecTimeDigitized !== null) {
            $exifEntries[ExifTag::SUB_SEC_TIME_DIGITIZED] = new IfdEntry(
                ExifTag::SUB_SEC_TIME_DIGITIZED,
                2,
                strlen($subSecTimeDigitized),
                $subSecTimeDigitized,
            );
            $hasAnyEntries = true;
        }

        if (!$hasAnyEntries) {
            return new ParsedExif(
                ifd0: new Ifd([]),
                exifIfd: new Ifd([]),
                gpsIfd: null,
                interopIfd: null,
                ifd1: null,
            );
        }

        $ifd0    = new Ifd($ifd0Entries);
        $exifIfd = new Ifd($exifEntries);

        return new ParsedExif(
            ifd0: $ifd0,
            exifIfd: $exifIfd,
            gpsIfd: null,
            interopIfd: null,
            ifd1: null,
        );
    }
}
