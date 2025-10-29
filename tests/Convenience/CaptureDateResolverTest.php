<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Convenience;

use DateTimeImmutable;
use MagicSunday\ImageMeta\Convenience\CaptureDateResolver;
use MagicSunday\ImageMeta\Convenience\ExifConvenience;
use MagicSunday\ImageMeta\Model\Exif\ExifDocument;
use MagicSunday\ImageMeta\Model\Exif\ExifTag;
use MagicSunday\ImageMeta\Model\Exif\Ifd;
use MagicSunday\ImageMeta\Model\Exif\IfdEntry;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;
use MagicSunday\ImageMeta\Core\ExifCapabilities;
use MagicSunday\ImageMeta\Model\Exif\ValueConverters;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MagicSunday\ImageMeta\Convenience\CaptureDateResolver
 */
#[CoversClass(CaptureDateResolver::class)]
#[UsesClass(Metadata::class)]
#[UsesClass(ExifDocument::class)]
#[UsesClass(Ifd::class)]
#[UsesClass(IfdEntry::class)]
#[UsesClass(XmpDocument::class)]
#[UsesClass(ExifConvenience::class)]
#[UsesClass(ExifCapabilities::class)]
#[UsesClass(ValueConverters::class)]
final class CaptureDateResolverTest extends TestCase
{
    private const string XMP_NAMESPACE = 'http://ns.adobe.com/xap/1.0/';

    /**
     * Uses only XMP metadata to confirm the resolver falls back to the CreateDate string and
     * returns a DateTimeImmutable instance.
     */
    #[Test]
    public function returnsXmpCreateDateWhenExifIsMissing(): void
    {
        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            exifDoc: null,
            xmpBlobs: [],
            xmpDoc: new XmpDocument([
                '{' . self::XMP_NAMESPACE . '}CreateDate' => '2024-03-30T12:34:56Z',
            ]),
        );

        $result = CaptureDateResolver::bestCaptureDateTime($metadata);

        self::assertInstanceOf(DateTimeImmutable::class, $result);
        self::assertSame('2024-03-30T12:34:56+00:00', $result->format(DATE_ATOM));
    }

    /**
     * Provides an invalid CreateDate value to ensure the resolver rejects non-ISO formatted
     * strings and returns null.
     */
    #[Test]
    public function ignoresNonIsoCreateDateValues(): void
    {
        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            exifDoc: null,
            xmpBlobs: [],
            xmpDoc: new XmpDocument([
                '{' . self::XMP_NAMESPACE . '}CreateDate' => 'not-a-date',
            ]),
        );

        self::assertNull(CaptureDateResolver::bestCaptureDateTime($metadata));
    }

    /**
     * Supplies multiple XMP CreateDate entries and checks that the first ISO-8601 value is
     * accepted and converted to a DateTimeImmutable instance.
     */
    #[Test]
    public function acceptsFirstArrayElementWhenIsoString(): void
    {
        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            exifDoc: null,
            xmpBlobs: [],
            xmpDoc: new XmpDocument([
                '{' . self::XMP_NAMESPACE . '}CreateDate' => [
                    '2024-03-30T12:34:56Z',
                    '2024-03-30T12:34:56+01:00',
                ],
            ]),
        );

        $result = CaptureDateResolver::bestCaptureDateTime($metadata);

        self::assertInstanceOf(DateTimeImmutable::class, $result);
        self::assertSame('2024-03-30T12:34:56+00:00', $result->format(DATE_ATOM));
    }

    /**
     * Ensures EXIF capture timestamps are preferred and that fallback logic covers digitised
     * timestamps when DateTimeOriginal is not provided.
     */
    #[Test]
    public function prefersExifCaptureDateWhenAvailable(): void
    {
        $exifDoc = new ExifDocument(
            new Ifd([]),
            new Ifd([
                ExifTag::DATETIME_DIGITIZED => new IfdEntry(
                    ExifTag::DATETIME_DIGITIZED,
                    2,
                    19,
                    '2024:04:05 01:02:03',
                ),
                ExifTag::OFFSET_TIME_DIGITIZED => new IfdEntry(
                    ExifTag::OFFSET_TIME_DIGITIZED,
                    2,
                    6,
                    '+01:00',
                ),
            ]),
            null,
            null,
            null,
        );

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            exifDoc: $exifDoc,
            xmpBlobs: [],
            xmpDoc: null,
        );

        $result = CaptureDateResolver::bestCaptureDateTime($metadata);

        self::assertInstanceOf(DateTimeImmutable::class, $result);
        self::assertSame('2024-04-05T01:02:03+01:00', $result->format(DATE_ATOM));
    }
}
