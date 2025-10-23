<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Curate;

use DateTimeImmutable;
use MagicSunday\ImageMeta\Curate\StructuredMetadataBuilder;
use MagicSunday\ImageMeta\Model\Exif\ExifDocument;
use MagicSunday\ImageMeta\Model\Exif\ExifTag;
use MagicSunday\ImageMeta\Model\Exif\Ifd;
use MagicSunday\ImageMeta\Model\Exif\IfdEntry;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Model\QuickTimeMeta;
use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;
use MagicSunday\ImageMeta\Value\Enum\ColorSpace;
use MagicSunday\ImageMeta\Value\Enum\ExposureProgram;
use MagicSunday\ImageMeta\Value\Enum\FlashFunction;
use MagicSunday\ImageMeta\Value\Enum\FlashMode;
use MagicSunday\ImageMeta\Value\Enum\FlashReturn;
use MagicSunday\ImageMeta\Value\Enum\MeteringMode;
use MagicSunday\ImageMeta\Value\Enum\Orientation;
use MagicSunday\ImageMeta\Value\Enum\WhiteBalance;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MagicSunday\ImageMeta\Curate\StructuredMetadataBuilder
 * @covers \MagicSunday\ImageMeta\Curate\StructuredMetadata
 * @covers \MagicSunday\ImageMeta\Curate\Resolver\CameraResolver
 * @covers \MagicSunday\ImageMeta\Curate\Resolver\LensResolver
 * @covers \MagicSunday\ImageMeta\Curate\Resolver\ImageResolver
 * @covers \MagicSunday\ImageMeta\Curate\Resolver\ExposureResolver
 * @covers \MagicSunday\ImageMeta\Curate\Resolver\CaptureResolver
 * @covers \MagicSunday\ImageMeta\Curate\Resolver\GpsResolver
 * @covers \MagicSunday\ImageMeta\Curate\Resolver\DeviceResolver
 * @covers \MagicSunday\ImageMeta\Curate\Resolver\AppleResolver
 * @covers \MagicSunday\ImageMeta\Curate\Resolver\XmpResolver
 */
final class StructuredMetadataBuilderTest extends TestCase
{
    /**
     * Ensures the builder combines EXIF, XMP and QuickTime data into a typed structure.
     */
    #[Test]
    public function buildsStructuredAggregateFromAvailableSources(): void
    {
        $ifd0 = new Ifd([
            ExifTag::MAKE        => new IfdEntry(ExifTag::MAKE, 2, 5, 'Canon'),
            ExifTag::MODEL       => new IfdEntry(ExifTag::MODEL, 2, 5, 'EOS R5'),
            ExifTag::ORIENTATION => new IfdEntry(ExifTag::ORIENTATION, 3, 1, 6),
            ExifTag::ARTIST      => new IfdEntry(ExifTag::ARTIST, 2, 5, 'Canon'),
        ]);

        $exifIfd = new Ifd([
            ExifTag::PHOTOGRAPHIC_SENSITIVITY => new IfdEntry(ExifTag::PHOTOGRAPHIC_SENSITIVITY, 3, 1, 400),
            ExifTag::EXPOSURE_TIME            => new IfdEntry(ExifTag::EXPOSURE_TIME, 5, 1, [[1, 125]]),
            ExifTag::F_NUMBER                 => new IfdEntry(ExifTag::F_NUMBER, 5, 1, [[9, 2]]),
            ExifTag::FOCAL_LENGTH             => new IfdEntry(ExifTag::FOCAL_LENGTH, 5, 1, [[85, 1]]),
            ExifTag::EXPOSURE_PROGRAM        => new IfdEntry(ExifTag::EXPOSURE_PROGRAM, 3, 1, 3),
            ExifTag::METERING_MODE           => new IfdEntry(ExifTag::METERING_MODE, 3, 1, 5),
            ExifTag::WHITE_BALANCE           => new IfdEntry(ExifTag::WHITE_BALANCE, 3, 1, 1),
            ExifTag::FLASH                   => new IfdEntry(ExifTag::FLASH, 3, 1, 127),
            ExifTag::DATETIME_ORIGINAL       => new IfdEntry(ExifTag::DATETIME_ORIGINAL, 2, 19, '2024:03:01 12:34:56'),
            ExifTag::OFFSET_TIME_ORIGINAL    => new IfdEntry(ExifTag::OFFSET_TIME_ORIGINAL, 2, 6, '+02:00'),
            ExifTag::COLOR_SPACE              => new IfdEntry(ExifTag::COLOR_SPACE, 3, 1, 1),
        ]);

        $gpsIfd = new Ifd([
            ExifTag::GPS_LATITUDE_REF  => new IfdEntry(ExifTag::GPS_LATITUDE_REF, 2, 1, 'N'),
            ExifTag::GPS_LATITUDE      => new IfdEntry(ExifTag::GPS_LATITUDE, 5, 3, [[48, 1], [12, 1], [30, 1]]),
            ExifTag::GPS_LONGITUDE_REF => new IfdEntry(ExifTag::GPS_LONGITUDE_REF, 2, 1, 'E'),
            ExifTag::GPS_LONGITUDE     => new IfdEntry(ExifTag::GPS_LONGITUDE, 5, 3, [[11, 1], [34, 1], [45, 1]]),
            ExifTag::GPS_ALTITUDE      => new IfdEntry(ExifTag::GPS_ALTITUDE, 5, 1, [[120, 1]]),
            ExifTag::GPS_ALTITUDE_REF  => new IfdEntry(ExifTag::GPS_ALTITUDE_REF, 1, 1, 0),
        ]);

        $exifDocument = new ExifDocument($ifd0, $exifIfd, $gpsIfd, null, null);

        $xmpDocument = new XmpDocument([
            '{http://ns.adobe.com/exif/1.0/}ISOSpeedRatings' => '640',
            '{http://ns.adobe.com/exif/1.0/}ExposureTime'    => '1/60',
            '{http://ns.adobe.com/exif/1.0/}FNumber'         => '2.8',
            '{http://ns.adobe.com/exif/1.0/}FocalLength'     => '50/1',
            '{http://ns.adobe.com/exif/1.0/}ExposureProgram' => '3',
            '{http://ns.adobe.com/exif/1.0/}MeteringMode'    => '5',
            '{http://ns.adobe.com/exif/1.0/}WhiteBalance'    => '1',
            '{http://ns.adobe.com/exif/1.0/}Flash'           => '127',
            '{http://ns.adobe.com/exif/1.0/}ColorSpace'      => '1',
            '{http://ns.adobe.com/exif/1.0/}DateTimeOriginal' => '2024-03-01T12:34:56+02:00',
            '{http://ns.adobe.com/exif/1.0/}GPSLatitude'     => '48, 12, 30',
            '{http://ns.adobe.com/exif/1.0/}GPSLatitudeRef'  => 'N',
            '{http://ns.adobe.com/exif/1.0/}GPSLongitude'    => '11, 34, 45',
            '{http://ns.adobe.com/exif/1.0/}GPSLongitudeRef' => 'E',
            '{http://ns.adobe.com/exif/1.0/aux/}LensModel'   => 'RF 50mm F1.2L',
            '{http://ns.adobe.com/exif/1.0/aux/}SerialNumber' => '12345',
            '{http://ns.adobe.com/xap/1.0/}CreatorTool'      => 'Lightroom Classic',
            '{http://ns.adobe.com/tiff/1.0/}Make'            => 'Canon',
            '{http://ns.adobe.com/tiff/1.0/}Model'           => 'EOS R5',
            '{http://purl.org/dc/elements/1.1/}subject'      => ['keyword1', 'keyword2'],
            '{http://purl.org/dc/elements/1.1/}rights'       => 'Copyright ACME',
            '{http://ns.adobe.com/xap/1.0/rights/}UsageTerms' => 'Editorial use only',
            '{http://ns.adobe.com/xap/1.0/rights/}WebStatement' => 'https://example.com/license',
            '{http://ns.adobe.com/photoshop/1.0/}Credit'     => 'ACME Press',
            '{http://purl.org/dc/elements/1.1/}creator'      => ['Jane Doe'],
            '{http://iptc.org/std/Iptc4xmpCore/1.0/xmlns/}CreatorContactInfo/Iptc4xmpCore:CiEmailWork' => 'jane@example.com',
            '{http://ns.adobe.com/tiff/1.0/}OriginalFileName' => 'IMG_0001.HEIC',
            '{http://ns.adobe.com/xap/1.0/mm/}History'       => 'edited',
        ]);

        $quickTime = new QuickTimeMeta([
            QuickTimeMeta::CONTENT_IDENTIFIER_KEY => 'asset-id',
            'com.apple.quicktime.make'            => 'Apple',
            'com.apple.quicktime.model'           => 'iPhone 15',
            'com.apple.quicktime.software'        => '17.4.1',
            'MajorBrand'                          => 'heic',
            'Encoder'                             => 'Apple AVFoundation',
            'AvgBitrate'                          => 12000000,
            'CompressorID'                        => 'hvc1',
            'AudioFormat'                         => 'aac',
            'AudioChannels'                       => 2,
            'AudioSampleRate'                     => 48000,
            'AudioBitsPerSample'                  => 16,
            'Duration'                            => 3.5,
            'VideoFrameRate'                      => 59.94,
            'ImageWidth'                          => 4032,
            'ImageHeight'                         => 3024,
            'HDRFormat'                           => 'true',
            'TransferFunction'                    => 'PQ',
            'ColorPrimaries'                      => 'P3',
            'BurstUUID'                           => 'burst-123',
            'BurstSelected'                       => 1,
            'DepthData'                           => 'depth-asset',
        ]);

        $metadata = new Metadata([
            'primary-exif',
        ], $quickTime, $exifDocument, ['<xmp/>'], $xmpDocument);

        $structured = (new StructuredMetadataBuilder())->build($metadata);

        self::assertSame('Canon', $structured->camera->make);
        self::assertSame('EOS R5', $structured->camera->model);
        self::assertSame('12345', $structured->camera->serialNumber);
        self::assertSame('Lightroom Classic', $structured->camera->software);

        self::assertSame('RF 50mm F1.2L', $structured->lens->model);
        self::assertSame(85.0, $structured->lens->focalLengthMm);

        self::assertSame(Orientation::RIGHT_TOP, $structured->image->orientation);
        self::assertSame(ColorSpace::SRGB, $structured->image->colorSpace);

        self::assertSame(400, $structured->exposure->iso);
        self::assertSame(0.008, $structured->exposure->exposureTimeSeconds);
        self::assertSame(4.5, $structured->exposure->apertureFNumber);
        self::assertSame(85.0, $structured->exposure->focalLengthMm);
        self::assertSame(ExposureProgram::APERTURE_PRIORITY, $structured->exposure->program);
        self::assertSame(MeteringMode::PATTERN, $structured->exposure->meteringMode);
        self::assertSame(WhiteBalance::MANUAL, $structured->exposure->whiteBalance);

        $flash = $structured->exposure->flash;
        self::assertNotNull($flash);
        self::assertTrue($flash->fired);
        self::assertSame(FlashMode::AUTO, $flash->mode);
        self::assertSame(FlashReturn::DETECTED, $flash->returnDetection);
        self::assertSame(FlashFunction::ABSENT, $flash->functionPresence);
        self::assertTrue($flash->redEyeReduction);

        $capture = $structured->capture->dateTime;
        self::assertInstanceOf(DateTimeImmutable::class, $capture);
        self::assertSame('2024-03-01T12:34:56+02:00', $capture->format('c'));

        $gps = $structured->gps;
        self::assertNotNull($gps);
        self::assertEqualsWithDelta(48.208333333333, (float) $gps->latitude, 0.000000000001);
        self::assertEqualsWithDelta(11.579166666667, (float) $gps->longitude, 0.000000000001);
        self::assertSame(120.0, $gps->altitude);

        $device = $structured->device;
        self::assertSame('Apple', $device->manufacturer);
        self::assertSame('iPhone 15', $device->model);
        self::assertSame('17.4.1', $device->software);

        self::assertSame('asset-id', $structured->apple->contentIdentifier);
        self::assertSame($xmpDocument, $structured->xmp->document);

        self::assertSame('heic', $structured->container->format);
        self::assertSame('Apple AVFoundation', $structured->container->encoder);
        self::assertSame(12000000, $structured->container->bitrate);
        self::assertSame('hvc1', $structured->container->videoCodec);
        self::assertSame('aac', $structured->container->audioCodec);

        self::assertSame(3.5, $structured->video->durationSec);
        self::assertSame(59.94, $structured->video->frameRate);
        self::assertSame(4032, $structured->video->width);
        self::assertSame(3024, $structured->video->height);
        self::assertTrue($structured->video->hdr);
        self::assertSame('PQ', $structured->video->transferFunction);
        self::assertSame('P3', $structured->video->colorPrimaries);

        self::assertSame(2, $structured->audio->channels);
        self::assertSame(48000, $structured->audio->sampleRate);
        self::assertSame(16, $structured->audio->bitDepth);

        self::assertSame(['keyword1', 'keyword2'], $structured->keywords->flat);
        self::assertNull($structured->keywords->hierarchical);

        self::assertSame('Copyright ACME', $structured->rights->copyright);
        self::assertSame('Editorial use only', $structured->rights->usageTerms);
        self::assertSame('https://example.com/license', $structured->rights->licenseUrl);
        self::assertSame('ACME Press', $structured->rights->creditLine);

        self::assertSame('Jane Doe', $structured->author->creator);
        self::assertSame('jane@example.com', $structured->author->creatorEmail);
        self::assertSame('Canon', $structured->author->artist);

        self::assertNotNull($structured->temporal->original);
        self::assertSame('EXIF', $structured->temporal->tzSource);

        self::assertSame('burst-123', $structured->related->burstId);
        self::assertTrue($structured->related->isPrimaryInBurst);
        self::assertSame('depth-asset', $structured->related->depthDataId);

        self::assertNull($structured->file->mimeType);
        self::assertNull($structured->processing->pictureStyle);

        self::assertSame('IMG_0001.HEIC', $structured->integrity->originalFileName);
        self::assertTrue($structured->integrity->edited);
    }

    /**
     * Ensures the aggregated structured metadata is cached per metadata instance.
     */
    #[Test]
    public function cachesStructuredAggregate(): void
    {
        $metadata = new Metadata([], null, null, []);

        $first  = $metadata->structured();
        $second = $metadata->structured();

        self::assertSame($first, $second);
    }
}
