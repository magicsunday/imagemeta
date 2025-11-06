<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Model\Exif;

use DateTimeImmutable;
use MagicSunday\ImageMeta\Core\ByteReader;
use MagicSunday\ImageMeta\Core\ExifCapabilities;
use MagicSunday\ImageMeta\Core\MemoryBuffer;
use MagicSunday\ImageMeta\Core\Util\UInt64;
use MagicSunday\ImageMeta\Core\Util\Unpack;
use MagicSunday\ImageMeta\Exif\Support\EnumFromIntStringNullable;
use MagicSunday\ImageMeta\Model\Exif\ExifNumericList;
use MagicSunday\ImageMeta\Model\Exif\ExifRational;
use MagicSunday\ImageMeta\Model\Exif\ExifRationalList;
use MagicSunday\ImageMeta\Model\Exif\ExifTag;
use MagicSunday\ImageMeta\Model\Exif\Ifd;
use MagicSunday\ImageMeta\Model\Exif\IfdEntry;
use MagicSunday\ImageMeta\Model\Exif\ParsedExif;
use MagicSunday\ImageMeta\Model\Exif\ValueConverters;
use MagicSunday\ImageMeta\Parse\Tiff\TiffConst;
use MagicSunday\ImageMeta\Parse\Tiff\TiffExifReader;
use MagicSunday\ImageMeta\Tests\Support\GpsTiffBuilder;
use MagicSunday\ImageMeta\Value\Enum\CfaPatternColor;
use MagicSunday\ImageMeta\Value\Enum\ColorSpace;
use MagicSunday\ImageMeta\Value\Enum\CompositeImage;
use MagicSunday\ImageMeta\Value\Enum\Compression;
use MagicSunday\ImageMeta\Value\Enum\CustomRendered;
use MagicSunday\ImageMeta\Value\Enum\ExposureProgram;
use MagicSunday\ImageMeta\Value\Enum\MeteringMode;
use MagicSunday\ImageMeta\Value\Enum\Orientation;
use MagicSunday\ImageMeta\Value\Enum\SceneType;
use MagicSunday\ImageMeta\Value\Enum\WhiteBalance;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesTrait;
use PHPUnit\Framework\TestCase;

use function iconv;
use function pack;
use function str_pad;
use function strlen;
use function substr;

#[UsesClass(ExifRational::class)]
#[UsesClass(ExifRationalList::class)]
#[UsesClass(Ifd::class)]
#[UsesClass(IfdEntry::class)]
#[UsesClass(ValueConverters::class)]
#[UsesClass(TiffExifReader::class)]
#[UsesClass(ExifNumericList::class)]
#[UsesClass(ExifCapabilities::class)]
#[UsesClass(UInt64::class)]
#[UsesClass(ByteReader::class)]
#[UsesClass(MemoryBuffer::class)]
#[UsesClass(Unpack::class)]
#[UsesTrait(EnumFromIntStringNullable::class)]
#[CoversClass(ParsedExif::class)]
final class ParsedExifTest extends TestCase
{
    private const string ISO_8601_MILLISECONDS = 'Y-m-d\TH:i:s.vP';

    /**
     * Ensures an Exif document exposes representative camera, exposure, and GPS metadata values.
     */
    #[Test]
    public function exposesRepresentativeExifValues(): void
    {
        $ifd0 = new Ifd([
            ExifTag::MAKE        => new IfdEntry(ExifTag::MAKE, 2, 1, "Canon\0"),
            ExifTag::MODEL       => new IfdEntry(ExifTag::MODEL, 2, 1, 'EOS R5'),
            ExifTag::ORIENTATION => new IfdEntry(ExifTag::ORIENTATION, 3, 1, 6),
        ]);

        $exifIfd = new Ifd([
            ExifTag::PHOTOGRAPHIC_SENSITIVITY => new IfdEntry(ExifTag::PHOTOGRAPHIC_SENSITIVITY, 3, 1, 200),
            ExifTag::EXPOSURE_TIME            => new IfdEntry(
                ExifTag::EXPOSURE_TIME,
                5,
                1,
                new ExifRational(1, 125),
            ),
            ExifTag::F_NUMBER => new IfdEntry(
                ExifTag::F_NUMBER,
                5,
                1,
                new ExifRational(28, 10),
            ),
            ExifTag::FOCAL_LENGTH => new IfdEntry(
                ExifTag::FOCAL_LENGTH,
                5,
                1,
                new ExifRational(50, 1),
            ),
            ExifTag::NOISE => new IfdEntry(
                ExifTag::NOISE,
                5,
                1,
                new ExifRational(123, 10),
            ),
            ExifTag::LENS_MODEL             => new IfdEntry(ExifTag::LENS_MODEL, 2, 1, 'RF50mm F1.2L USM'),
            ExifTag::DATETIME_ORIGINAL      => new IfdEntry(ExifTag::DATETIME_ORIGINAL, 2, 1, '2024:05:01 12:34:56'),
            ExifTag::SUB_SEC_TIME_ORIGINAL  => new IfdEntry(ExifTag::SUB_SEC_TIME_ORIGINAL, 2, 1, '123'),
            ExifTag::SUB_SEC_TIME_DIGITIZED => new IfdEntry(ExifTag::SUB_SEC_TIME_DIGITIZED, 2, 1, '456'),
            ExifTag::OFFSET_TIME_ORIGINAL   => new IfdEntry(ExifTag::OFFSET_TIME_ORIGINAL, 2, 1, '+02:00'),
        ]);

        $gpsIfd = new Ifd([
            ExifTag::GPS_LATITUDE_REF => new IfdEntry(ExifTag::GPS_LATITUDE_REF, 2, 2, 'N'),
            ExifTag::GPS_LATITUDE     => new IfdEntry(
                ExifTag::GPS_LATITUDE,
                5,
                3,
                new ExifRationalList([
                    new ExifRational(40, 1),
                    new ExifRational(26, 1),
                    new ExifRational(3000, 100),
                ]),
            ),
            ExifTag::GPS_LONGITUDE_REF => new IfdEntry(ExifTag::GPS_LONGITUDE_REF, 2, 2, 'E'),
            ExifTag::GPS_LONGITUDE     => new IfdEntry(
                ExifTag::GPS_LONGITUDE,
                5,
                3,
                new ExifRationalList([
                    new ExifRational(79, 1),
                    new ExifRational(58, 1),
                    new ExifRational(6000, 100),
                ]),
            ),
            ExifTag::GPS_ALTITUDE_REF => new IfdEntry(ExifTag::GPS_ALTITUDE_REF, 1, 1, 0),
            ExifTag::GPS_ALTITUDE     => new IfdEntry(
                ExifTag::GPS_ALTITUDE,
                5,
                1,
                new ExifRational(123, 1),
            ),
        ]);

        $doc = new ParsedExif($ifd0, $exifIfd, $gpsIfd, null, null);

        self::assertSame('Canon', $doc->cameraMake());
        self::assertSame('EOS R5', $doc->cameraModel());
        self::assertSame('RF50mm F1.2L USM', $doc->lensModel());
        self::assertSame(Orientation::RIGHT_TOP, $doc->orientation());
        self::assertSame(200, $doc->iso());
        self::assertSame(0.008, $doc->exposureTime());
        self::assertSame(2.8, $doc->fNumber());
        self::assertSame(50.0, $doc->focalLengthMm());
        self::assertEqualsWithDelta(12.3, $doc->noise(), 0.0001);
        self::assertSame('2024:05:01 12:34:56', $doc->dateTimeOriginalRaw());
        self::assertSame('+02:00', $doc->offsetTimeOriginalRaw());

        $capture = $doc->captureDateTime();
        self::assertNotNull($capture);
        self::assertSame('2024-05-01T12:34:56.123+02:00', $capture->format(self::ISO_8601_MILLISECONDS));

        /**
         * @var array{
         *     lat_ref:?string,
         *     lat:?float,
         *     lon_ref:?string,
         *     lon:?float,
         *     alt_ref:?int,
         *     alt:?float,
         *     version:?string,
         *     version_raw:?string,
         *     satellites:?string,
         *     status:?string,
         *     measure_mode:?string,
         *     dop:?float,
         *     speed_ref:?string,
         *     speed_ms:?float,
         *     speed_original_ref:?string,
         *     speed_original:?float,
         *     track_ref:?string,
         *     track:?float,
         *     img_direction_ref:?string,
         *     img_direction:?float,
         *     map_datum:?string,
         *     dest_lat_ref:?string,
         *     dest_lat:?float,
         *     dest_lon_ref:?string,
         *     dest_lon:?float,
         *     dest_bearing_ref:?string,
         *     dest_bearing:?float,
         *     dest_distance_ref:?string,
         *     dest_distance_m:?float,
         *     dest_distance_original_ref:?string,
         *     dest_distance_original:?float,
         *     processing_method:?string,
         *     area_information:?string,
         *     date:?string,
         *     date_raw:?string,
         *     time:?string,
         *     timestamp:?DateTimeImmutable,
         *     differential:?int,
         *     h_positioning_error:?float
         * } $gps
         */
        $gps = $doc->gps();
        self::assertEqualsWithDelta(40.441666, $gps['lat'], 0.000001);
        self::assertEqualsWithDelta(79.983333, $gps['lon'], 0.000001);
        self::assertEquals(123.0, $gps['alt']);
        self::assertSame('2.0.0.0', $gps['version']);
        self::assertNull($gps['version_raw']);
    }

    #[Test]
    public function normalisesFlashpixVersionFromPaddedNumericString(): void
    {
        $ifd0 = new Ifd([]);

        $exifIfd = new Ifd([
            ExifTag::FLASHPIX_VERSION => new IfdEntry(
                ExifTag::FLASHPIX_VERSION,
                2,
                1,
                pack('A8', '0300'),
            ),
        ]);

        $doc = new ParsedExif($ifd0, $exifIfd, null, null, null);

        self::assertSame('3.00', $doc->flashpixVersion());
    }

    #[Test]
    public function exposesCompositeImageMetadata(): void
    {
        $ifd0 = new Ifd([]);

        $exifIfd = new Ifd([
            ExifTag::COMPOSITE_IMAGE => new IfdEntry(
                ExifTag::COMPOSITE_IMAGE,
                3,
                1,
                CompositeImage::GENERAL_COMPOSITE->value,
            ),
            ExifTag::SOURCE_IMAGE_NUMBER_OF_COMPOSITE_IMAGE => new IfdEntry(
                ExifTag::SOURCE_IMAGE_NUMBER_OF_COMPOSITE_IMAGE,
                3,
                2,
                new ExifNumericList([5, 2]),
            ),
            ExifTag::SOURCE_EXPOSURE_TIMES_OF_COMPOSITE_IMAGE => new IfdEntry(
                ExifTag::SOURCE_EXPOSURE_TIMES_OF_COMPOSITE_IMAGE,
                5,
                3,
                [[1, 30], [1, 15], [1, 8]],
            ),
        ]);

        $doc = new ParsedExif($ifd0, $exifIfd, null, null, null);

        self::assertSame(CompositeImage::GENERAL_COMPOSITE, $doc->compositeImage());
        self::assertSame([5, 2], $doc->sourceImageNumberOfCompositeImage());

        $exposureTimes = $doc->sourceExposureTimesOfCompositeImage();
        self::assertNotNull($exposureTimes);
        self::assertCount(3, $exposureTimes);
        self::assertEqualsWithDelta(0.0333333333, $exposureTimes[0], 1e-10);
        self::assertEqualsWithDelta(0.0666666666, $exposureTimes[1], 1e-10);
        self::assertEqualsWithDelta(0.125, $exposureTimes[2], 1e-10);
    }

    #[Test]
    public function returnsCfaRepeatPatternDimensions(): void
    {
        $ifd0 = new Ifd([]);

        $exifIfd = new Ifd([
            ExifTag::CFA_REPEAT_PATTERN_DIM => new IfdEntry(
                ExifTag::CFA_REPEAT_PATTERN_DIM,
                3,
                2,
                new ExifNumericList([4, 2]),
            ),
        ]);

        $doc = new ParsedExif($ifd0, $exifIfd, null, null, null);

        self::assertSame(
            ['width' => 4, 'height' => 2],
            $doc->cfaRepeatPatternDim(),
        );
    }

    #[Test]
    public function returnsNullWhenCfaRepeatPatternDimensionsMissing(): void
    {
        $doc = new ParsedExif(new Ifd([]), new Ifd([]), null, null, null);

        self::assertNull($doc->cfaRepeatPatternDim());
    }

    #[Test]
    public function returnsNullWhenCfaRepeatPatternDimensionsInvalid(): void
    {
        $ifd0 = new Ifd([]);

        $invalidZero = new Ifd([
            ExifTag::CFA_REPEAT_PATTERN_DIM => new IfdEntry(
                ExifTag::CFA_REPEAT_PATTERN_DIM,
                3,
                2,
                new ExifNumericList([4, 0]),
            ),
        ]);

        self::assertNull((new ParsedExif($ifd0, $invalidZero, null, null, null))->cfaRepeatPatternDim());

        $invalidCount = new Ifd([
            ExifTag::CFA_REPEAT_PATTERN_DIM => new IfdEntry(
                ExifTag::CFA_REPEAT_PATTERN_DIM,
                3,
                1,
                new ExifNumericList([4]),
            ),
        ]);

        self::assertNull((new ParsedExif($ifd0, $invalidCount, null, null, null))->cfaRepeatPatternDim());
    }

    #[Test]
    public function exposesPreviewMetadataFromExifThreeTags(): void
    {
        $ifd0 = new Ifd([
            ExifTag::JPEG_INTERCHANGE_FORMAT        => new IfdEntry(ExifTag::JPEG_INTERCHANGE_FORMAT, 4, 1, 122_880),
            ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH => new IfdEntry(ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH, 4, 1, 4_096),
        ]);

        $exifIfd = new Ifd([
            ExifTag::PREVIEW_IMAGE_START       => new IfdEntry(ExifTag::PREVIEW_IMAGE_START, 4, 1, 32_768),
            ExifTag::PREVIEW_IMAGE_LENGTH      => new IfdEntry(ExifTag::PREVIEW_IMAGE_LENGTH, 4, 1, 16_384),
            ExifTag::PREVIEW_IMAGE_WIDTH       => new IfdEntry(ExifTag::PREVIEW_IMAGE_WIDTH, 4, 1, 1_600),
            ExifTag::PREVIEW_IMAGE_HEIGHT      => new IfdEntry(ExifTag::PREVIEW_IMAGE_HEIGHT, 4, 1, 900),
            ExifTag::PREVIEW_IMAGE_ENCODING    => new IfdEntry(ExifTag::PREVIEW_IMAGE_ENCODING, 2, 4, 'JPEG'),
            ExifTag::PREVIEW_IMAGE_MIME_TYPE   => new IfdEntry(ExifTag::PREVIEW_IMAGE_MIME_TYPE, 2, 10, 'image/jpeg'),
            ExifTag::PREVIEW_IMAGE_COLOR_SPACE => new IfdEntry(
                ExifTag::PREVIEW_IMAGE_COLOR_SPACE,
                3,
                1,
                ColorSpace::ADOBE_RGB->value,
            ),
            ExifTag::PREVIEW_IMAGE_BIT_DEPTH     => new IfdEntry(ExifTag::PREVIEW_IMAGE_BIT_DEPTH, 3, 1, 8),
            ExifTag::PREVIEW_IMAGE_COMPRESSION   => new IfdEntry(ExifTag::PREVIEW_IMAGE_COMPRESSION, 3, 1, Compression::JPEG->value),
            ExifTag::PREVIEW_IMAGE_SCALE         => new IfdEntry(ExifTag::PREVIEW_IMAGE_SCALE, 5, 1, new ExifRational(1, 2)),
            ExifTag::PREVIEW_DATE_TIME           => new IfdEntry(ExifTag::PREVIEW_DATE_TIME, 2, 19, '2024:10:25 18:45:30'),
            ExifTag::PREVIEW_DATE_TIME_DIGITIZED => new IfdEntry(ExifTag::PREVIEW_DATE_TIME_DIGITIZED, 2, 19, '2024:10:25 18:40:00'),
        ]);

        $document = new ParsedExif($ifd0, $exifIfd, null, null, null);

        self::assertTrue($document->hasThumbnail());
        self::assertSame(32_768, $document->previewImageOffset());
        self::assertSame(16_384, $document->previewImageLength());
        self::assertTrue($document->hasPreviewImage());
        self::assertSame(1_600, $document->previewImageWidth());
        self::assertSame(900, $document->previewImageHeight());
        self::assertSame('JPEG', $document->previewImageEncoding());
        self::assertSame('image/jpeg', $document->previewImageMimeType());
        self::assertSame(8, $document->previewImageBitDepth());
        self::assertSame(Compression::JPEG->value, $document->previewImageCompression());
        self::assertEqualsWithDelta(0.5, $document->previewImageScale(), 1e-6);
        self::assertSame(ColorSpace::ADOBE_RGB->value, $document->previewColorSpace());

        $previewDateTime = $document->previewDateTime();
        self::assertInstanceOf(DateTimeImmutable::class, $previewDateTime);
        self::assertSame('2024-10-25T18:45:30+00:00', $previewDateTime->format(DATE_ATOM));

        $previewDigitized = $document->previewDateTimeDigitized();
        self::assertInstanceOf(DateTimeImmutable::class, $previewDigitized);
        self::assertSame('2024-10-25T18:40:00+00:00', $previewDigitized->format(DATE_ATOM));
    }

    #[Test]
    public function previewMetadataFallsBackToRootIfdWhenExifIfdMissing(): void
    {
        $ifd0 = new Ifd([
            ExifTag::PREVIEW_IMAGE_START       => new IfdEntry(ExifTag::PREVIEW_IMAGE_START, 4, 1, 8192),
            ExifTag::PREVIEW_IMAGE_LENGTH      => new IfdEntry(ExifTag::PREVIEW_IMAGE_LENGTH, 4, 1, 4096),
            ExifTag::PREVIEW_IMAGE_COMPRESSION => new IfdEntry(
                ExifTag::PREVIEW_IMAGE_COMPRESSION,
                3,
                1,
                Compression::JPEG_OLD_STYLE->value,
            ),
            ExifTag::PREVIEW_IMAGE_SCALE => new IfdEntry(
                ExifTag::PREVIEW_IMAGE_SCALE,
                5,
                1,
                new ExifRational(1, 4),
            ),
        ]);

        $document = new ParsedExif($ifd0, null, null, null, null);

        self::assertTrue($document->hasPreviewImage());
        self::assertSame(8192, $document->previewImageOffset());
        self::assertSame(4096, $document->previewImageLength());
        self::assertSame(Compression::JPEG_OLD_STYLE->value, $document->previewImageCompression());
        self::assertEqualsWithDelta(0.25, $document->previewImageScale(), 1e-6);
    }

    #[Test]
    public function previewImageTreatsZeroOffsetsAndLengthsAsMissing(): void
    {
        $ifd0 = new Ifd([]);

        $zeroOffsetExif = new Ifd([
            ExifTag::PREVIEW_IMAGE_START  => new IfdEntry(ExifTag::PREVIEW_IMAGE_START, 4, 1, 0),
            ExifTag::PREVIEW_IMAGE_LENGTH => new IfdEntry(ExifTag::PREVIEW_IMAGE_LENGTH, 4, 1, 2048),
        ]);

        $docWithZeroOffset = new ParsedExif($ifd0, $zeroOffsetExif, null, null, null);

        self::assertNull($docWithZeroOffset->previewImageOffset());
        self::assertFalse($docWithZeroOffset->hasPreviewImage());

        $zeroLengthExif = new Ifd([
            ExifTag::PREVIEW_IMAGE_START  => new IfdEntry(ExifTag::PREVIEW_IMAGE_START, 4, 1, 4096),
            ExifTag::PREVIEW_IMAGE_LENGTH => new IfdEntry(ExifTag::PREVIEW_IMAGE_LENGTH, 4, 1, 0),
        ]);

        $docWithZeroLength = new ParsedExif($ifd0, $zeroLengthExif, null, null, null);

        self::assertNull($docWithZeroLength->previewImageOffset());
        self::assertNull($docWithZeroLength->previewImageLength());
        self::assertFalse($docWithZeroLength->hasPreviewImage());
    }

    #[Test]
    public function previewImageCompressionTreatsNonPositiveValuesAsMissing(): void
    {
        $ifd0 = new Ifd([]);

        $zeroCompressionExif = new Ifd([
            ExifTag::PREVIEW_IMAGE_COMPRESSION => new IfdEntry(ExifTag::PREVIEW_IMAGE_COMPRESSION, 3, 1, 0),
        ]);

        $docWithZeroCompression = new ParsedExif($ifd0, $zeroCompressionExif, null, null, null);

        self::assertNull($docWithZeroCompression->previewImageCompression());

        $negativeCompressionExif = new Ifd([
            ExifTag::PREVIEW_IMAGE_COMPRESSION => new IfdEntry(ExifTag::PREVIEW_IMAGE_COMPRESSION, 4, 1, -5),
        ]);

        $docWithNegativeCompression = new ParsedExif($ifd0, $negativeCompressionExif, null, null, null);

        self::assertNull($docWithNegativeCompression->previewImageCompression());
    }

    #[Test]
    public function previewImageScaleTreatsNonPositiveValuesAsMissing(): void
    {
        $ifd0 = new Ifd([]);

        $zeroScaleExif = new Ifd([
            ExifTag::PREVIEW_IMAGE_SCALE => new IfdEntry(
                ExifTag::PREVIEW_IMAGE_SCALE,
                5,
                1,
                new ExifRational(0, 1),
            ),
        ]);

        $docWithZeroScale = new ParsedExif($ifd0, $zeroScaleExif, null, null, null);

        self::assertNull($docWithZeroScale->previewImageScale());

        $negativeScaleExif = new Ifd([
            ExifTag::PREVIEW_IMAGE_SCALE => new IfdEntry(
                ExifTag::PREVIEW_IMAGE_SCALE,
                5,
                1,
                new ExifRational(-1, 2),
            ),
        ]);

        $docWithNegativeScale = new ParsedExif($ifd0, $negativeScaleExif, null, null, null);

        self::assertNull($docWithNegativeScale->previewImageScale());
    }

    #[Test]
    public function previewMetadataRequiresOffsetAndLengthPair(): void
    {
        $ifd0 = new Ifd([]);

        $missingLengthExif = new Ifd([
            ExifTag::PREVIEW_IMAGE_START       => new IfdEntry(ExifTag::PREVIEW_IMAGE_START, 4, 1, 512),
            ExifTag::PREVIEW_IMAGE_COMPRESSION => new IfdEntry(ExifTag::PREVIEW_IMAGE_COMPRESSION, 3, 1, 6),
            ExifTag::PREVIEW_IMAGE_SCALE       => new IfdEntry(ExifTag::PREVIEW_IMAGE_SCALE, 5, 1, new ExifRational(1, 2)),
        ]);

        $docMissingLength = new ParsedExif($ifd0, $missingLengthExif, null, null, null);

        self::assertNull($docMissingLength->previewImageOffset());
        self::assertNull($docMissingLength->previewImageLength());
        self::assertNull($docMissingLength->previewImageCompression());
        self::assertNull($docMissingLength->previewImageScale());

        $completeExif = new Ifd([
            ExifTag::PREVIEW_IMAGE_START       => new IfdEntry(ExifTag::PREVIEW_IMAGE_START, 4, 1, 2048),
            ExifTag::PREVIEW_IMAGE_LENGTH      => new IfdEntry(ExifTag::PREVIEW_IMAGE_LENGTH, 4, 1, 4096),
            ExifTag::PREVIEW_IMAGE_COMPRESSION => new IfdEntry(ExifTag::PREVIEW_IMAGE_COMPRESSION, 3, 1, 6),
            ExifTag::PREVIEW_IMAGE_SCALE       => new IfdEntry(ExifTag::PREVIEW_IMAGE_SCALE, 5, 1, new ExifRational(1, 2)),
        ]);

        $docComplete = new ParsedExif($ifd0, $completeExif, null, null, null);

        self::assertSame(2048, $docComplete->previewImageOffset());
        self::assertSame(4096, $docComplete->previewImageLength());
        self::assertSame(6, $docComplete->previewImageCompression());
        self::assertEqualsWithDelta(0.5, $docComplete->previewImageScale(), 1e-6);
    }

    #[Test]
    public function previewMetadataIgnoresOffsetsBeyondSupportedRange(): void
    {
        $ifd0 = new Ifd([]);

        $offset = UInt64::fromUInt32(0x8000_0000, 0);

        $exifIfd = new Ifd([
            ExifTag::PREVIEW_IMAGE_START       => new IfdEntry(ExifTag::PREVIEW_IMAGE_START, 16, 1, $offset),
            ExifTag::PREVIEW_IMAGE_LENGTH      => new IfdEntry(ExifTag::PREVIEW_IMAGE_LENGTH, 4, 1, 4096),
            ExifTag::PREVIEW_IMAGE_COMPRESSION => new IfdEntry(ExifTag::PREVIEW_IMAGE_COMPRESSION, 3, 1, 6),
            ExifTag::PREVIEW_IMAGE_SCALE       => new IfdEntry(ExifTag::PREVIEW_IMAGE_SCALE, 5, 1, new ExifRational(1, 2)),
        ]);

        $document = new ParsedExif($ifd0, $exifIfd, null, null, null);

        self::assertFalse($document->hasPreviewImage());
        self::assertNull($document->previewImageOffset());
        self::assertNull($document->previewImageLength());
        self::assertNull($document->previewImageCompression());
        self::assertNull($document->previewImageScale());
    }

    /**
     * Falls back to DateTimeDigitized when DateTimeOriginal is missing.
     */
    #[Test]
    public function fallsBackToDateTimeDigitizedWhenOriginalMissing(): void
    {
        $ifd0 = new Ifd([]);

        $exifIfd = new Ifd([
            ExifTag::DATETIME_DIGITIZED     => new IfdEntry(ExifTag::DATETIME_DIGITIZED, 2, 1, '2015:06:07 08:09:10'),
            ExifTag::SUB_SEC_TIME_DIGITIZED => new IfdEntry(ExifTag::SUB_SEC_TIME_DIGITIZED, 2, 1, '234'),
            ExifTag::OFFSET_TIME_DIGITIZED  => new IfdEntry(ExifTag::OFFSET_TIME_DIGITIZED, 2, 1, '-04:00'),
        ]);

        $doc = new ParsedExif($ifd0, $exifIfd, null, null, null);

        $capture = $doc->captureDateTime();
        self::assertNotNull($capture);
        self::assertSame('2015-06-07T08:09:10.234-04:00', $capture->format(self::ISO_8601_MILLISECONDS));
    }

    #[Test]
    public function dateTimeOriginalBestEffortFallsBackToDigitized(): void
    {
        $ifd0 = new Ifd([]);

        $exifIfd = new Ifd([
            ExifTag::DATETIME_ORIGINAL     => new IfdEntry(ExifTag::DATETIME_ORIGINAL, 2, 1, 'bad metadata'),
            ExifTag::DATETIME_DIGITIZED    => new IfdEntry(ExifTag::DATETIME_DIGITIZED, 2, 1, '2024:01:02 03:04:05'),
            ExifTag::OFFSET_TIME_DIGITIZED => new IfdEntry(ExifTag::OFFSET_TIME_DIGITIZED, 2, 1, '+00:00'),
        ]);

        $doc = new ParsedExif($ifd0, $exifIfd, null, null, null);

        $digitized = $doc->dateTimeDigitized();
        self::assertInstanceOf(DateTimeImmutable::class, $digitized);
        self::assertEquals($digitized, $doc->dateTimeOriginal());

        $best = $doc->dateTimeOriginalBestEffort();
        self::assertInstanceOf(DateTimeImmutable::class, $best);
        self::assertEquals($digitized, $best);
    }

    #[Test]
    public function dateTimeOriginalRawFallsBackToSubsequentIfds(): void
    {
        $ifd0      = new Ifd([]);
        $thumbnail = new Ifd([]);
        $source    = new Ifd([
            ExifTag::DATETIME_ORIGINAL => new IfdEntry(ExifTag::DATETIME_ORIGINAL, 2, 1, '2020:05:06 07:08:09'),
        ]);

        $doc = new ParsedExif(
            $ifd0,
            new Ifd([]),
            null,
            null,
            $thumbnail,
            null,
            [$thumbnail, $source],
            [],
        );

        self::assertSame('2020:05:06 07:08:09', $doc->dateTimeOriginalRaw());

        $parsed = $doc->dateTimeOriginal();
        self::assertInstanceOf(DateTimeImmutable::class, $parsed);
        self::assertSame('2020-05-06T07:08:09+00:00', $parsed->format(DATE_ATOM));
    }

    #[Test]
    public function dateTimeOriginalRawFallsBackToIfd0WhenExifTagMissing(): void
    {
        $ifd0 = new Ifd([
            ExifTag::DATETIME_ORIGINAL => new IfdEntry(
                ExifTag::DATETIME_ORIGINAL,
                TiffConst::TYPE_ASCII,
                1,
                '2001:02:03 04:05:06',
            ),
        ]);

        $doc = new ParsedExif($ifd0, new Ifd([]), null, null, null);

        self::assertSame('2001:02:03 04:05:06', $doc->dateTimeOriginalRaw());

        $resolved = $doc->dateTimeOriginal();
        self::assertInstanceOf(DateTimeImmutable::class, $resolved);
        self::assertSame('2001-02-03T04:05:06+00:00', $resolved->format(DATE_ATOM));
    }

    #[Test]
    public function dateTimeOriginalFallsBackToModifyDateWhenPrimaryTagsMissing(): void
    {
        $ifd0 = new Ifd([
            ExifTag::DATETIME => new IfdEntry(ExifTag::DATETIME, 2, 1, '2010:11:12 13:14:15'),
        ]);

        $exifIfd = new Ifd([
            ExifTag::TIME_ZONE_OFFSET => new IfdEntry(ExifTag::TIME_ZONE_OFFSET, 3, 1, new ExifNumericList([2])),
            ExifTag::SUB_SEC_TIME     => new IfdEntry(ExifTag::SUB_SEC_TIME, 2, 1, '246'),
        ]);

        $doc = new ParsedExif($ifd0, $exifIfd, null, null, null);

        self::assertNull($doc->dateTimeOriginalRaw());

        $fallback = $doc->dateTimeOriginal();
        self::assertInstanceOf(DateTimeImmutable::class, $fallback);
        self::assertSame('2010-11-12T13:14:15.246+02:00', $fallback->format(self::ISO_8601_MILLISECONDS));
    }

    #[Test]
    public function dateTimeOriginalReturnsParsedTimestamp(): void
    {
        $ifd0 = new Ifd([]);

        $exifIfd = new Ifd([
            ExifTag::DATETIME_ORIGINAL     => new IfdEntry(ExifTag::DATETIME_ORIGINAL, 2, 1, '2022:03:04 05:06:07'),
            ExifTag::SUB_SEC_TIME_ORIGINAL => new IfdEntry(ExifTag::SUB_SEC_TIME_ORIGINAL, 2, 1, '123'),
            ExifTag::OFFSET_TIME_ORIGINAL  => new IfdEntry(ExifTag::OFFSET_TIME_ORIGINAL, 2, 1, '-05:30'),
        ]);

        $doc = new ParsedExif($ifd0, $exifIfd, null, null, null);

        $original = $doc->dateTimeOriginal();
        self::assertInstanceOf(DateTimeImmutable::class, $original);
        self::assertSame('2022-03-04T05:06:07.123-05:30', $original->format(self::ISO_8601_MILLISECONDS));
    }

    #[Test]
    public function dateTimeOriginalFallsBackToDigitizedWhenAbsent(): void
    {
        $ifd0 = new Ifd([]);

        $exifIfd = new Ifd([
            ExifTag::DATETIME_DIGITIZED     => new IfdEntry(ExifTag::DATETIME_DIGITIZED, 2, 1, '2019:07:08 09:10:11'),
            ExifTag::SUB_SEC_TIME_DIGITIZED => new IfdEntry(ExifTag::SUB_SEC_TIME_DIGITIZED, 2, 1, '456'),
            ExifTag::OFFSET_TIME_DIGITIZED  => new IfdEntry(ExifTag::OFFSET_TIME_DIGITIZED, 2, 1, '+02:00'),
        ]);

        $doc = new ParsedExif($ifd0, $exifIfd, null, null, null);

        $original = $doc->dateTimeOriginal();
        self::assertInstanceOf(DateTimeImmutable::class, $original);
        self::assertSame('2019-07-08T09:10:11.456+02:00', $original->format(self::ISO_8601_MILLISECONDS));
    }

    #[Test]
    public function captureDateTimeFallsBackToGpsTimestamp(): void
    {
        $gpsIfd = new Ifd([
            ExifTag::GPS_DATE_STAMP => new IfdEntry(ExifTag::GPS_DATE_STAMP, 2, 10, '2024:08:09'),
            ExifTag::GPS_TIME_STAMP => new IfdEntry(
                ExifTag::GPS_TIME_STAMP,
                5,
                3,
                new ExifRationalList([
                    new ExifRational(10, 1),
                    new ExifRational(11, 1),
                    new ExifRational(12, 1),
                ]),
            ),
        ]);

        $doc = new ParsedExif(new Ifd([]), null, $gpsIfd, null, null);

        $capture = $doc->captureDateTime();
        self::assertInstanceOf(DateTimeImmutable::class, $capture);
        self::assertSame('2024-08-09T10:11:12+00:00', $capture->format(DATE_ATOM));
    }

    /**
     * Uses the legacy TimeZoneOffset tag when explicit offset tags are unavailable.
     */
    #[Test]
    public function usesTimeZoneOffsetWhenOffsetTagsMissing(): void
    {
        $ifd0 = new Ifd([]);

        $exifIfd = new Ifd([
            ExifTag::DATETIME_ORIGINAL     => new IfdEntry(ExifTag::DATETIME_ORIGINAL, 2, 1, '2021:02:03 04:05:06'),
            ExifTag::SUB_SEC_TIME_ORIGINAL => new IfdEntry(ExifTag::SUB_SEC_TIME_ORIGINAL, 2, 1, '789'),
            ExifTag::TIME_ZONE_OFFSET      => new IfdEntry(
                ExifTag::TIME_ZONE_OFFSET,
                3,
                1,
                new ExifNumericList([9]),
            ),
        ]);

        $doc = new ParsedExif($ifd0, $exifIfd, null, null, null);

        $capture = $doc->captureDateTime();
        self::assertNotNull($capture);
        self::assertSame('2021-02-03T04:05:06.789+09:00', $capture->format(self::ISO_8601_MILLISECONDS));
    }

    #[Test]
    public function normalisesSrationalTimeZoneOffsetValues(): void
    {
        $ifd0 = new Ifd([
            ExifTag::DATETIME_ORIGINAL => new IfdEntry(ExifTag::DATETIME_ORIGINAL, 2, 1, '2024:03:01 06:07:08'),
        ]);

        $exifIfd = new Ifd([
            ExifTag::DATETIME_ORIGINAL => new IfdEntry(ExifTag::DATETIME_ORIGINAL, 2, 1, '2024:03:01 06:07:08'),
            ExifTag::TIME_ZONE_OFFSET  => new IfdEntry(
                ExifTag::TIME_ZONE_OFFSET,
                10,
                2,
                new ExifRationalList([
                    new ExifRational(11, 2),
                    new ExifRational(-23, 4),
                ]),
            ),
        ]);

        $document = new ParsedExif($ifd0, $exifIfd, null, null, null);

        self::assertSame([330, -345], $document->timeZoneOffsetMinutes());

        $capture = $document->captureDateTime();
        self::assertNotNull($capture);
        self::assertSame('2024-03-01T06:07:08.000+05:30', $capture->format(self::ISO_8601_MILLISECONDS));
    }

    #[Test]
    public function decodesPrintImageMatchingPayload(): void
    {
        $payload = $this->buildPrintImPayload();

        $ifd0    = new Ifd([]);
        $exifIfd = new Ifd([
            ExifTag::PRINT_IMAGE_MATCHING => new IfdEntry(
                ExifTag::PRINT_IMAGE_MATCHING,
                7,
                strlen($payload),
                $payload,
            ),
        ]);

        $document = new ParsedExif($ifd0, $exifIfd, null, null, null);

        $expected = [
            'header'     => 'PrintIM',
            'version'    => '0400',
            'parameters' => [
                ['id' => 0x0100, 'value' => 0x0000002A],
                ['id' => 0x0101, 'value' => 0x00000064],
            ],
        ];

        self::assertSame($expected, $document->printImageMatching());
    }

    #[Test]
    public function ignoresMalformedPrintImageMatchingPayload(): void
    {
        $payload   = $this->buildPrintImPayload();
        $truncated = substr($payload, 0, -1);

        $ifd0    = new Ifd([]);
        $exifIfd = new Ifd([
            ExifTag::PRINT_IMAGE_MATCHING => new IfdEntry(
                ExifTag::PRINT_IMAGE_MATCHING,
                7,
                strlen($truncated),
                $truncated,
            ),
        ]);

        $document = new ParsedExif($ifd0, $exifIfd, null, null, null);

        self::assertNull($document->printImageMatching());
    }

    /**
     * Builds a synthetic PrintIM payload for testing.
     */
    private function buildPrintImPayload(): string
    {
        $parameters = [
            [0x0100, 0x0000002A],
            [0x0101, 0x00000064],
        ];

        $payload = 'PrintIM 0400' . pack('n', count($parameters));

        foreach ($parameters as [$id, $value]) {
            $payload .= pack('nN', $id, $value);
        }

        return $payload;
    }

    #[Test]
    public function usesSecondTimeZoneOffsetComponentForDigitizedFallback(): void
    {
        $ifd0 = new Ifd([]);

        $exifIfd = new Ifd([
            ExifTag::DATETIME_DIGITIZED     => new IfdEntry(ExifTag::DATETIME_DIGITIZED, 2, 1, '2022:08:09 10:11:12'),
            ExifTag::SUB_SEC_TIME_DIGITIZED => new IfdEntry(ExifTag::SUB_SEC_TIME_DIGITIZED, 2, 1, '321'),
            ExifTag::TIME_ZONE_OFFSET       => new IfdEntry(
                ExifTag::TIME_ZONE_OFFSET,
                3,
                2,
                new ExifNumericList([9, -3]),
            ),
        ]);

        $doc = new ParsedExif($ifd0, $exifIfd, null, null, null);

        $capture = $doc->captureDateTime();
        self::assertNotNull($capture);
        self::assertSame('2022-08-09T10:11:12.321-03:00', $capture->format(self::ISO_8601_MILLISECONDS));
    }

    #[Test]
    public function exposesRelatedImageMetadataFromInteroperabilityIfd(): void
    {
        $ifd0       = new Ifd([]);
        $interopIfd = new Ifd([
            ExifTag::RELATED_IMAGE_FILE_FORMAT => new IfdEntry(ExifTag::RELATED_IMAGE_FILE_FORMAT, 2, 4, "JPEG\0"),
            ExifTag::RELATED_IMAGE_WIDTH       => new IfdEntry(ExifTag::RELATED_IMAGE_WIDTH, 4, 1, 4000),
            ExifTag::RELATED_IMAGE_LENGTH      => new IfdEntry(ExifTag::RELATED_IMAGE_LENGTH, 4, 1, 3000),
        ]);

        $doc = new ParsedExif($ifd0, null, null, $interopIfd, null);

        self::assertSame('JPEG', $doc->relatedImageFileFormat());
        self::assertSame(4000, $doc->relatedImageWidth());
        self::assertSame(3000, $doc->relatedImageLength());
    }

    /**
     * Uses the legacy ModifyDate tag when original and digitised timestamps are absent.
     */
    #[Test]
    public function usesModifyDateWhenOriginalAndDigitizedMissing(): void
    {
        $ifd0 = new Ifd([
            ExifTag::DATETIME => new IfdEntry(ExifTag::DATETIME, 2, 1, '2010:02:03 04:05:06'),
        ]);

        $exifIfd = new Ifd([
            ExifTag::SUB_SEC_TIME => new IfdEntry(ExifTag::SUB_SEC_TIME, 2, 1, '12'),
            ExifTag::OFFSET_TIME  => new IfdEntry(ExifTag::OFFSET_TIME, 2, 1, '-05:00'),
        ]);

        $doc = new ParsedExif($ifd0, $exifIfd, null, null, null);

        $capture = $doc->captureDateTime();
        self::assertNotNull($capture);
        self::assertSame('2010-02-03T04:05:06.120-05:00', $capture->format(self::ISO_8601_MILLISECONDS));
    }

    /**
     * Ensures EXIF 3.0 table 64 convenience accessors expose normalised values.
     */
    #[Test]
    public function exposesTable64ConvenienceAccessors(): void
    {
        $transferFunction    = new ExifNumericList([0, 32768, 65535]);
        $referenceBlackWhite = new ExifRationalList([
            new ExifRational(0, 1),
            new ExifRational(255, 1),
            new ExifRational(0, 1),
            new ExifRational(255, 1),
            new ExifRational(0, 1),
            new ExifRational(255, 1),
        ]);

        $ifd0 = new Ifd([
            ExifTag::TRANSFER_FUNCTION     => new IfdEntry(ExifTag::TRANSFER_FUNCTION, 3, 3, $transferFunction),
            ExifTag::REFERENCE_BLACK_WHITE => new IfdEntry(ExifTag::REFERENCE_BLACK_WHITE, 5, 6, $referenceBlackWhite),
            ExifTag::COPYRIGHT             => new IfdEntry(ExifTag::COPYRIGHT, 2, 9, "Jane Doe\0"),
        ]);

        $thumbnailIfd = new Ifd([
            ExifTag::STRIP_OFFSETS => new IfdEntry(
                ExifTag::STRIP_OFFSETS,
                4,
                2,
                new ExifNumericList([64, 128]),
            ),
            ExifTag::STRIP_BYTE_COUNTS => new IfdEntry(
                ExifTag::STRIP_BYTE_COUNTS,
                4,
                2,
                new ExifNumericList([256, 512]),
            ),
            ExifTag::TILE_WIDTH   => new IfdEntry(ExifTag::TILE_WIDTH, 4, 1, 320),
            ExifTag::TILE_LENGTH  => new IfdEntry(ExifTag::TILE_LENGTH, 4, 1, 640),
            ExifTag::TILE_OFFSETS => new IfdEntry(
                ExifTag::TILE_OFFSETS,
                4,
                3,
                new ExifNumericList([1024, 2048, 3072]),
            ),
            ExifTag::TILE_BYTE_COUNTS => new IfdEntry(
                ExifTag::TILE_BYTE_COUNTS,
                4,
                3,
                new ExifNumericList([4096, 4096, 8192]),
            ),
            ExifTag::JPEG_INTERCHANGE_FORMAT        => new IfdEntry(ExifTag::JPEG_INTERCHANGE_FORMAT, 4, 1, 4096),
            ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH => new IfdEntry(ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH, 4, 1, 8192),
        ]);

        $doc = new ParsedExif($ifd0, null, null, null, $thumbnailIfd);

        self::assertSame([64, 128], $doc->stripOffsets());
        self::assertSame([256, 512], $doc->stripByteCounts());
        self::assertSame(320, $doc->tileWidth());
        self::assertSame(640, $doc->tileLength());
        self::assertSame([1024, 2048, 3072], $doc->tileOffsets());
        self::assertSame([4096, 4096, 8192], $doc->tileByteCounts());
        self::assertSame([0, 32768, 65535], $doc->transferFunction());
        self::assertSame(4096, $doc->thumbnailJpegInterchangeFormat());
        self::assertSame(8192, $doc->thumbnailJpegInterchangeFormatLength());
        self::assertSame([0.0, 255.0, 0.0, 255.0, 0.0, 255.0], $doc->referenceBlackWhite());
        self::assertSame('Jane Doe', $doc->copyright());
    }

    /**
     * Ensures the GPS helper exposes every decoded field from table 66 including references.
     */
    #[Test]
    public function exposesCompleteGpsMetadata(): void
    {
        $gpsIfd = new Ifd([
            ExifTag::GPS_VERSION_ID   => new IfdEntry(ExifTag::GPS_VERSION_ID, 1, 4, [3, 0, 0, 0]),
            ExifTag::GPS_LATITUDE_REF => new IfdEntry(ExifTag::GPS_LATITUDE_REF, 2, 2, 'S'),
            ExifTag::GPS_LATITUDE     => new IfdEntry(
                ExifTag::GPS_LATITUDE,
                5,
                3,
                new ExifRationalList([
                    new ExifRational(33, 1),
                    new ExifRational(52, 1),
                    new ExifRational(1234, 100),
                ]),
            ),
            ExifTag::GPS_LONGITUDE_REF => new IfdEntry(ExifTag::GPS_LONGITUDE_REF, 2, 2, 'W'),
            ExifTag::GPS_LONGITUDE     => new IfdEntry(
                ExifTag::GPS_LONGITUDE,
                5,
                3,
                new ExifRationalList([
                    new ExifRational(18, 1),
                    new ExifRational(24, 1),
                    new ExifRational(5678, 100),
                ]),
            ),
            ExifTag::GPS_ALTITUDE_REF => new IfdEntry(ExifTag::GPS_ALTITUDE_REF, 1, 1, 1),
            ExifTag::GPS_ALTITUDE     => new IfdEntry(ExifTag::GPS_ALTITUDE, 5, 1, new ExifRational(250, 1)),
            ExifTag::GPS_TIME_STAMP   => new IfdEntry(
                ExifTag::GPS_TIME_STAMP,
                5,
                3,
                new ExifRationalList([
                    new ExifRational(5, 1),
                    new ExifRational(6, 1),
                    new ExifRational(789, 100),
                ]),
            ),
            ExifTag::GPS_DATE_STAMP        => new IfdEntry(ExifTag::GPS_DATE_STAMP, 2, 10, '2024:05:07'),
            ExifTag::GPS_SATELLITES        => new IfdEntry(ExifTag::GPS_SATELLITES, 2, 2, '07'),
            ExifTag::GPS_STATUS            => new IfdEntry(ExifTag::GPS_STATUS, 2, 1, 'V'),
            ExifTag::GPS_MEASURE_MODE      => new IfdEntry(ExifTag::GPS_MEASURE_MODE, 2, 1, '2'),
            ExifTag::GPS_DOP               => new IfdEntry(ExifTag::GPS_DOP, 5, 1, new ExifRational(15, 10)),
            ExifTag::GPS_SPEED_REF         => new IfdEntry(ExifTag::GPS_SPEED_REF, 2, 1, 'N'),
            ExifTag::GPS_SPEED             => new IfdEntry(ExifTag::GPS_SPEED, 5, 1, new ExifRational(12345, 1000)),
            ExifTag::GPS_TRACK_REF         => new IfdEntry(ExifTag::GPS_TRACK_REF, 2, 1, 'M'),
            ExifTag::GPS_TRACK             => new IfdEntry(ExifTag::GPS_TRACK, 5, 1, new ExifRational(54321, 100)),
            ExifTag::GPS_IMG_DIRECTION_REF => new IfdEntry(ExifTag::GPS_IMG_DIRECTION_REF, 2, 1, 'T'),
            ExifTag::GPS_IMG_DIRECTION     => new IfdEntry(ExifTag::GPS_IMG_DIRECTION, 5, 1, new ExifRational(90, 1)),
            ExifTag::GPS_MAP_DATUM         => new IfdEntry(ExifTag::GPS_MAP_DATUM, 2, 9, "WGS-84\0"),
            ExifTag::GPS_DEST_LATITUDE_REF => new IfdEntry(ExifTag::GPS_DEST_LATITUDE_REF, 2, 1, 'N'),
            ExifTag::GPS_DEST_LATITUDE     => new IfdEntry(
                ExifTag::GPS_DEST_LATITUDE,
                5,
                3,
                new ExifRationalList([
                    new ExifRational(34, 1),
                    new ExifRational(0, 1),
                    new ExifRational(0, 1),
                ]),
            ),
            ExifTag::GPS_DEST_LONGITUDE_REF => new IfdEntry(ExifTag::GPS_DEST_LONGITUDE_REF, 2, 1, 'E'),
            ExifTag::GPS_DEST_LONGITUDE     => new IfdEntry(
                ExifTag::GPS_DEST_LONGITUDE,
                5,
                3,
                new ExifRationalList([
                    new ExifRational(19, 1),
                    new ExifRational(0, 1),
                    new ExifRational(0, 1),
                ]),
            ),
            ExifTag::GPS_DEST_BEARING_REF    => new IfdEntry(ExifTag::GPS_DEST_BEARING_REF, 2, 1, 'T'),
            ExifTag::GPS_DEST_BEARING        => new IfdEntry(ExifTag::GPS_DEST_BEARING, 5, 1, new ExifRational(45, 1)),
            ExifTag::GPS_DEST_DISTANCE_REF   => new IfdEntry(ExifTag::GPS_DEST_DISTANCE_REF, 2, 1, 'M'),
            ExifTag::GPS_DEST_DISTANCE       => new IfdEntry(ExifTag::GPS_DEST_DISTANCE, 5, 1, new ExifRational(100, 1)),
            ExifTag::GPS_PROCESSING_METHOD   => new IfdEntry(ExifTag::GPS_PROCESSING_METHOD, 7, 11, "ASCII\0\0\0SURVEY"),
            ExifTag::GPS_AREA_INFORMATION    => new IfdEntry(ExifTag::GPS_AREA_INFORMATION, 7, 13, "ASCII\0\0\0Test Area"),
            ExifTag::GPS_DIFFERENTIAL        => new IfdEntry(ExifTag::GPS_DIFFERENTIAL, 3, 1, 1),
            ExifTag::GPS_H_POSITIONING_ERROR => new IfdEntry(ExifTag::GPS_H_POSITIONING_ERROR, 5, 1, new ExifRational(5, 10)),
        ]);

        $doc = new ParsedExif(new Ifd([]), null, $gpsIfd, null, null);

        $gps = $doc->gps();

        self::assertSame('S', $gps['lat_ref']);
        self::assertIsFloat($gps['lat']);
        self::assertEqualsWithDelta(-33.870094, $gps['lat'], 0.000001);
        self::assertSame('W', $gps['lon_ref']);
        self::assertIsFloat($gps['lon']);
        self::assertEqualsWithDelta(-18.415772, $gps['lon'], 0.000001);
        self::assertSame(1, $gps['alt_ref']);
        self::assertEqualsWithDelta(-250.0, $gps['alt'], 0.000001);

        self::assertSame('3.0.0.0', $gps['version']);
        self::assertSame('07', $gps['satellites']);
        self::assertSame('V', $gps['status']);
        self::assertSame('2', $gps['measure_mode']);
        self::assertIsFloat($gps['dop']);
        self::assertEqualsWithDelta(1.5, $gps['dop'], 0.000001);
        self::assertSame('N', $gps['speed_ref']);
        self::assertIsFloat($gps['speed_ms']);
        self::assertEqualsWithDelta(6.3508166667, $gps['speed_ms'], 0.000001);
        self::assertSame('M', $gps['track_ref']);
        self::assertIsFloat($gps['track']);
        self::assertEqualsWithDelta(183.21, $gps['track'], 0.000001);
        self::assertSame('T', $gps['img_direction_ref']);
        self::assertIsFloat($gps['img_direction']);
        self::assertEqualsWithDelta(90.0, $gps['img_direction'], 0.000001);
        self::assertSame('WGS-84', $gps['map_datum']);
        self::assertSame('N', $gps['dest_lat_ref']);
        self::assertIsFloat($gps['dest_lat']);
        self::assertEqualsWithDelta(34.0, $gps['dest_lat'], 0.000001);
        self::assertSame('E', $gps['dest_lon_ref']);
        self::assertIsFloat($gps['dest_lon']);
        self::assertEqualsWithDelta(19.0, $gps['dest_lon'], 0.000001);
        self::assertSame('T', $gps['dest_bearing_ref']);
        self::assertIsFloat($gps['dest_bearing']);
        self::assertEqualsWithDelta(45.0, $gps['dest_bearing'], 0.000001);
        self::assertSame('M', $gps['dest_distance_ref']);
        self::assertIsFloat($gps['dest_distance_m']);
        self::assertEqualsWithDelta(160934.4, $gps['dest_distance_m'], 0.000001);
        self::assertSame('SURVEY', $gps['processing_method']);
        self::assertSame('Test Area', $gps['area_information']);
        self::assertSame('2024-05-07', $gps['date']);
        self::assertSame('05:06:07.89', $gps['time']);

        $timestamp = $gps['timestamp'];
        self::assertInstanceOf(DateTimeImmutable::class, $timestamp);
        self::assertSame('2024-05-07T05:06:07+00:00', $timestamp->format(DATE_ATOM));

        self::assertSame(1, $gps['differential']);
        self::assertEqualsWithDelta(0.5, $gps['h_positioning_error'], 0.000001);

        self::assertIsFloat($doc->gpsSpeedMetresPerSecond());
        self::assertIsFloat($doc->gpsTrack());
        self::assertIsFloat($doc->gpsImgDirection());
        self::assertIsFloat($doc->gpsDestinationBearing());
        self::assertIsFloat($doc->gpsDestinationDistanceMetres());
        self::assertSame(1, $doc->gpsDifferential());
        self::assertIsFloat($doc->gpsHorizontalPositioningError());
    }

    /**
     * Ensures GPS speed conversion returns null when the unit reference is unknown.
     */
    #[Test]
    public function gpsSpeedMetresPerSecondRequiresKnownReference(): void
    {
        $gpsIfd = new Ifd([
            ExifTag::GPS_SPEED_REF => new IfdEntry(ExifTag::GPS_SPEED_REF, 2, 1, 'X'),
            ExifTag::GPS_SPEED     => new IfdEntry(ExifTag::GPS_SPEED, 5, 1, new ExifRational(5000, 100)),
        ]);

        $doc = new ParsedExif(new Ifd([]), null, $gpsIfd, null, null);

        self::assertNull($doc->gpsSpeedMetresPerSecond());
    }

    /**
     * Ensures destination distance conversion returns null when the unit reference is unknown.
     */
    #[Test]
    public function gpsDestinationDistanceRequiresKnownReference(): void
    {
        $gpsIfd = new Ifd([
            ExifTag::GPS_DEST_DISTANCE_REF => new IfdEntry(ExifTag::GPS_DEST_DISTANCE_REF, 2, 1, 'Q'),
            ExifTag::GPS_DEST_DISTANCE     => new IfdEntry(ExifTag::GPS_DEST_DISTANCE, 5, 1, new ExifRational(250, 1)),
        ]);

        $doc = new ParsedExif(new Ifd([]), null, $gpsIfd, null, null);

        self::assertNull($doc->gpsDestinationDistanceMetres());
    }

    /**
     * Ensures extended EXIF getters expose camera ownership, dimensions and exposure metadata.
     */
    #[Test]
    public function exposesExtendedExifValues(): void
    {
        $ifd0 = new Ifd([
            ExifTag::MAKE         => new IfdEntry(ExifTag::MAKE, 2, 1, 'Canon'),
            ExifTag::MODEL        => new IfdEntry(ExifTag::MODEL, 2, 1, 'EOS R6'),
            ExifTag::IMAGE_WIDTH  => new IfdEntry(ExifTag::IMAGE_WIDTH, 4, 1, 4000),
            ExifTag::IMAGE_HEIGHT => new IfdEntry(ExifTag::IMAGE_HEIGHT, 4, 1, 2667),
            ExifTag::DATETIME     => new IfdEntry(ExifTag::DATETIME, 2, 1, '2024:05:02 09:10:11'),
        ]);

        $exifIfd = new Ifd([
            ExifTag::CAMERA_OWNER_NAME         => new IfdEntry(ExifTag::CAMERA_OWNER_NAME, 2, 1, "Jane Doe\0"),
            ExifTag::BODY_SERIAL_NUMBER        => new IfdEntry(ExifTag::BODY_SERIAL_NUMBER, 2, 1, '123456789'),
            ExifTag::LENS_MODEL                => new IfdEntry(ExifTag::LENS_MODEL, 2, 1, 'RF70-200mm'),
            ExifTag::LENS_SERIAL_NUMBER        => new IfdEntry(ExifTag::LENS_SERIAL_NUMBER, 2, 1, 'LNS987654321'),
            ExifTag::PIXEL_X_DIMENSION         => new IfdEntry(ExifTag::PIXEL_X_DIMENSION, 4, 1, 5472),
            ExifTag::PIXEL_Y_DIMENSION         => new IfdEntry(ExifTag::PIXEL_Y_DIMENSION, 4, 1, 3648),
            ExifTag::COLOR_SPACE               => new IfdEntry(ExifTag::COLOR_SPACE, 3, 1, 1),
            ExifTag::IMAGE_UNIQUE_ID           => new IfdEntry(ExifTag::IMAGE_UNIQUE_ID, 2, 1, "UNIQUE-ID-123\0"),
            ExifTag::ISO_SPEED                 => new IfdEntry(ExifTag::ISO_SPEED, 3, 1, 800),
            ExifTag::EXPOSURE_TIME             => new IfdEntry(ExifTag::EXPOSURE_TIME, 5, 1, new ExifRational(1, 60)),
            ExifTag::F_NUMBER                  => new IfdEntry(ExifTag::F_NUMBER, 5, 1, new ExifRational(56, 10)),
            ExifTag::FOCAL_LENGTH              => new IfdEntry(ExifTag::FOCAL_LENGTH, 5, 1, new ExifRational(85, 1)),
            ExifTag::FOCAL_LENGTH_IN_35MM_FILM => new IfdEntry(ExifTag::FOCAL_LENGTH_IN_35MM_FILM, 3, 1, 85),
            ExifTag::EXPOSURE_PROGRAM          => new IfdEntry(ExifTag::EXPOSURE_PROGRAM, 3, 1, 3),
            ExifTag::INTERLACE                 => new IfdEntry(ExifTag::INTERLACE, 3, 1, 1),
            ExifTag::METERING_MODE             => new IfdEntry(ExifTag::METERING_MODE, 3, 1, 5),
            ExifTag::FLASH                     => new IfdEntry(ExifTag::FLASH, 3, 1, 0x5F),
            ExifTag::WHITE_BALANCE             => new IfdEntry(ExifTag::WHITE_BALANCE, 3, 1, 1),
            ExifTag::EXPOSURE_BIAS_VALUE       => new IfdEntry(ExifTag::EXPOSURE_BIAS_VALUE, 10, 1, new ExifRational(-1, 2)),
            ExifTag::BRIGHTNESS_VALUE          => new IfdEntry(ExifTag::BRIGHTNESS_VALUE, 10, 1, new ExifRational(55, 10)),
            ExifTag::MAX_APERTURE_VALUE        => new IfdEntry(ExifTag::MAX_APERTURE_VALUE, 5, 1, new ExifRational(28, 10)),
            ExifTag::DATETIME_ORIGINAL         => new IfdEntry(ExifTag::DATETIME_ORIGINAL, 2, 1, '2024:05:02 09:10:11'),
            ExifTag::SUB_SEC_TIME_ORIGINAL     => new IfdEntry(ExifTag::SUB_SEC_TIME_ORIGINAL, 2, 1, '125'),
            ExifTag::OFFSET_TIME_ORIGINAL      => new IfdEntry(ExifTag::OFFSET_TIME_ORIGINAL, 2, 1, '+01:30'),
            ExifTag::DATETIME_DIGITIZED        => new IfdEntry(ExifTag::DATETIME_DIGITIZED, 2, 1, '2024:05:02 09:15:00'),
            ExifTag::SUB_SEC_TIME_DIGITIZED    => new IfdEntry(ExifTag::SUB_SEC_TIME_DIGITIZED, 2, 1, '250'),
            ExifTag::OFFSET_TIME_DIGITIZED     => new IfdEntry(ExifTag::OFFSET_TIME_DIGITIZED, 2, 1, '+01:30'),
            ExifTag::OFFSET_TIME               => new IfdEntry(ExifTag::OFFSET_TIME, 2, 1, '+01:30'),
            ExifTag::SUB_SEC_TIME              => new IfdEntry(ExifTag::SUB_SEC_TIME, 2, 1, '500'),
            ExifTag::TIME_ZONE_OFFSET          => new IfdEntry(ExifTag::TIME_ZONE_OFFSET, 8, 1, new ExifNumericList([-130])),
            ExifTag::SELF_TIMER_MODE           => new IfdEntry(ExifTag::SELF_TIMER_MODE, 3, 1, 10),
        ]);

        $doc = new ParsedExif($ifd0, $exifIfd, null, null, null);

        self::assertSame('Jane Doe', $doc->ownerName());
        self::assertSame('123456789', $doc->bodySerialNumber());
        self::assertSame('LNS987654321', $doc->lensSerialNumber());
        self::assertSame(5472, $doc->imageWidth());
        self::assertSame(3648, $doc->imageHeight());
        self::assertSame(ColorSpace::SRGB, $doc->colorSpace());
        self::assertSame('UNIQUE-ID-123', $doc->imageUniqueId());
        self::assertSame(800, $doc->iso());
        self::assertEqualsWithDelta(0.016666666666667, $doc->exposureTime(), 0.000000000000001);
        self::assertEqualsWithDelta(5.6, $doc->fNumber(), 0.000000000000001);
        self::assertEqualsWithDelta(85.0, $doc->focalLengthMm(), 0.000000000000001);
        self::assertSame(85, $doc->focalLength35Mm());
        self::assertSame(ExposureProgram::APERTURE_PRIORITY, $doc->exposureProgram());
        self::assertSame(MeteringMode::PATTERN, $doc->meteringMode());
        self::assertSame(0x5F, $doc->flash());
        self::assertSame(WhiteBalance::MANUAL, $doc->whiteBalance());
        self::assertEqualsWithDelta(-0.5, $doc->exposureBias(), 0.000000000000001);
        self::assertEqualsWithDelta(5.5, $doc->brightnessValue(), 0.000000000000001);
        self::assertEqualsWithDelta(2.8, $doc->maxApertureApex(), 0.000000000000001);
        self::assertSame('500', $doc->subSecTime());
        self::assertSame('125', $doc->subSecTimeOriginal());
        self::assertSame('250', $doc->subSecTimeDigitized());
        self::assertSame('+01:30', $doc->offsetTime());
        self::assertSame('+01:30', $doc->offsetTimeOriginal());
        self::assertSame('+01:30', $doc->offsetTimeDigitized());
        self::assertSame([-90], $doc->timeZoneOffsetMinutes());
        self::assertSame(10, $doc->selfTimerModeSeconds());
        self::assertSame(1, $doc->interlace());

        $captured = $doc->captureDateTime();
        self::assertNotNull($captured);
        self::assertSame('2024-05-02T09:10:11.125+01:30', $captured->format(self::ISO_8601_MILLISECONDS));

        $digitized = $doc->dateTimeDigitized();
        self::assertNotNull($digitized);
        self::assertSame('2024-05-02T09:15:00.250+01:30', $digitized->format(self::ISO_8601_MILLISECONDS));

        $fileDate = $doc->dateTime();
        self::assertNotNull($fileDate);
        self::assertSame('2024-05-02T09:10:11.500+01:30', $fileDate->format(self::ISO_8601_MILLISECONDS));
    }

    #[Test]
    public function cameraSerialNumberPrefersNewTag(): void
    {
        $exifIfd = new Ifd([
            ExifTag::CAMERA_SERIAL_NUMBER => new IfdEntry(ExifTag::CAMERA_SERIAL_NUMBER, 2, 1, "CAMERA-0001\0"),
            ExifTag::BODY_SERIAL_NUMBER   => new IfdEntry(ExifTag::BODY_SERIAL_NUMBER, 2, 1, 'BODY-LEGACY'),
        ]);

        $doc = new ParsedExif(new Ifd([]), $exifIfd, null, null, null);

        self::assertSame('CAMERA-0001', $doc->cameraSerialNumber());
        self::assertSame('BODY-LEGACY', $doc->bodySerialNumber());
    }

    #[Test]
    public function cameraSerialNumberFallsBackToBodyTag(): void
    {
        $exifIfd = new Ifd([
            ExifTag::BODY_SERIAL_NUMBER => new IfdEntry(ExifTag::BODY_SERIAL_NUMBER, 2, 1, 'BODY-ONLY'),
        ]);

        $doc = new ParsedExif(new Ifd([]), $exifIfd, null, null, null);

        self::assertSame('BODY-ONLY', $doc->cameraSerialNumber());
    }

    #[Test]
    public function makerNoteSafetyMapsNumericValues(): void
    {
        $safeExifIfd = new Ifd([
            ExifTag::MAKER_NOTE_SAFETY => new IfdEntry(ExifTag::MAKER_NOTE_SAFETY, 3, 1, 1),
        ]);

        $unsafeExifIfd = new Ifd([
            ExifTag::MAKER_NOTE_SAFETY => new IfdEntry(ExifTag::MAKER_NOTE_SAFETY, 3, 1, 0),
        ]);

        $safeDoc    = new ParsedExif(new Ifd([]), $safeExifIfd, null, null, null);
        $unsafeDoc  = new ParsedExif(new Ifd([]), $unsafeExifIfd, null, null, null);
        $missingDoc = new ParsedExif(new Ifd([]), null, null, null, null);

        self::assertTrue($safeDoc->makerNoteSafety());
        self::assertFalse($unsafeDoc->makerNoteSafety());
        self::assertNull($missingDoc->makerNoteSafety());
    }

    #[Test]
    public function documentNameUsesLegacyDocumentNameWhenAlone(): void
    {
        $ifd0 = new Ifd([
            ExifTag::DOCUMENT_NAME => new IfdEntry(ExifTag::DOCUMENT_NAME, 2, 1, 'Archive Page'),
        ]);

        $doc = new ParsedExif($ifd0, null, null, null, null);

        self::assertSame('Archive Page', $doc->documentName());
    }

    #[Test]
    public function documentNamePrefersLegacyDocumentNameTag(): void
    {
        $ifd0 = new Ifd([
            ExifTag::DOCUMENT_NAME => new IfdEntry(ExifTag::DOCUMENT_NAME, 2, 1, "Scan 001\0"),
            ExifTag::XP_SUBJECT    => new IfdEntry(ExifTag::XP_SUBJECT, 1, 1, 'XP Subject'),
        ]);

        $doc = new ParsedExif($ifd0, null, null, null, null);

        self::assertSame('Scan 001', $doc->documentName());
    }

    /**
     * Ensures Table 65 extension tags are exposed via dedicated getters.
     */
    #[Test]
    public function exposesTable65Extensions(): void
    {
        $oecfPayload = $this->buildOecfPayload();
        $sfrPayload  = $this->buildSpatialFrequencyResponsePayload();

        $ifd0 = new Ifd([
            ExifTag::IMAGE_DESCRIPTION => new IfdEntry(ExifTag::IMAGE_DESCRIPTION, 2, 1, 'Coastal cliffs'),
            ExifTag::IMAGE_TITLE       => new IfdEntry(ExifTag::IMAGE_TITLE, 2, 1, 'Cliffside Dusk'),
            ExifTag::PHOTOGRAPHER      => new IfdEntry(ExifTag::PHOTOGRAPHER, 2, 1, 'Alex Light'),
            ExifTag::IMAGE_EDITOR      => new IfdEntry(ExifTag::IMAGE_EDITOR, 2, 1, 'Chris Edit'),
        ]);

        $gpsIfd = new Ifd([
            ExifTag::TEMPERATURE            => new IfdEntry(ExifTag::TEMPERATURE, 10, 1, new ExifRational(200, 10)),
            ExifTag::HUMIDITY               => new IfdEntry(ExifTag::HUMIDITY, 10, 1, new ExifRational(550, 10)),
            ExifTag::PRESSURE               => new IfdEntry(ExifTag::PRESSURE, 10, 1, new ExifRational(100000, 100)),
            ExifTag::WATER_DEPTH            => new IfdEntry(ExifTag::WATER_DEPTH, 10, 1, new ExifRational(30, 10)),
            ExifTag::ACCELERATION           => new IfdEntry(ExifTag::ACCELERATION, 10, 1, new ExifRational(10, 1)),
            ExifTag::CAMERA_ELEVATION_ANGLE => new IfdEntry(ExifTag::CAMERA_ELEVATION_ANGLE, 10, 1, new ExifRational(50, 10)),
        ]);

        $exifIfd = new Ifd([
            ExifTag::COMPONENTS_CONFIGURATION         => new IfdEntry(ExifTag::COMPONENTS_CONFIGURATION, 7, 4, new ExifNumericList([1, 2, 3, 0])),
            ExifTag::COMPRESSED_BITS_PER_PIXEL        => new IfdEntry(ExifTag::COMPRESSED_BITS_PER_PIXEL, 5, 1, new ExifRational(45, 10)),
            ExifTag::USER_COMMENT                     => new IfdEntry(ExifTag::USER_COMMENT, 7, 1, "ASCII\0\0\0Calibrated output\0"),
            ExifTag::SPECTRAL_SENSITIVITY             => new IfdEntry(ExifTag::SPECTRAL_SENSITIVITY, 2, 1, 'Spectral A'),
            ExifTag::OECF                             => new IfdEntry(ExifTag::OECF, 7, strlen($oecfPayload), $oecfPayload),
            ExifTag::ISO_SPEED_LATITUDE_YYY           => new IfdEntry(ExifTag::ISO_SPEED_LATITUDE_YYY, 3, 1, 200),
            ExifTag::ISO_SPEED_LATITUDE_ZZZ           => new IfdEntry(ExifTag::ISO_SPEED_LATITUDE_ZZZ, 3, 1, 400),
            ExifTag::IMAGE_NUMBER                     => new IfdEntry(ExifTag::IMAGE_NUMBER, 3, 1, 512),
            ExifTag::SECURITY_CLASSIFICATION          => new IfdEntry(ExifTag::SECURITY_CLASSIFICATION, 2, 1, 'Confidential'),
            ExifTag::IMAGE_HISTORY                    => new IfdEntry(ExifTag::IMAGE_HISTORY, 2, 1, 'Processed in RawLab'),
            ExifTag::BATTERY_LEVEL                    => new IfdEntry(ExifTag::BATTERY_LEVEL, 5, 1, new ExifRational(3, 4)),
            ExifTag::RELATED_SOUND_FILE               => new IfdEntry(ExifTag::RELATED_SOUND_FILE, 2, 1, 'clip.wav'),
            ExifTag::FLASH_ENERGY                     => new IfdEntry(ExifTag::FLASH_ENERGY, 5, 1, new ExifRational(150, 10)),
            ExifTag::SPATIAL_FREQUENCY_RESPONSE       => new IfdEntry(ExifTag::SPATIAL_FREQUENCY_RESPONSE, 7, strlen($sfrPayload), $sfrPayload),
            ExifTag::FOCAL_PLANE_X_RESOLUTION         => new IfdEntry(ExifTag::FOCAL_PLANE_X_RESOLUTION, 5, 1, new ExifRational(8000, 100)),
            ExifTag::FOCAL_PLANE_Y_RESOLUTION         => new IfdEntry(ExifTag::FOCAL_PLANE_Y_RESOLUTION, 5, 1, new ExifRational(7900, 100)),
            ExifTag::FOCAL_PLANE_RESOLUTION_UNIT      => new IfdEntry(ExifTag::FOCAL_PLANE_RESOLUTION_UNIT, 3, 1, 2),
            ExifTag::TIFF_EP_STANDARD_ID              => new IfdEntry(ExifTag::TIFF_EP_STANDARD_ID, 1, 4, new ExifNumericList([2, 0, 0, 0])),
            ExifTag::SUBJECT_LOCATION                 => new IfdEntry(ExifTag::SUBJECT_LOCATION, 3, 2, new ExifNumericList([1024, 768])),
            ExifTag::EXPOSURE_INDEX                   => new IfdEntry(ExifTag::EXPOSURE_INDEX, 5, 1, new ExifRational(320, 1)),
            ExifTag::SCENE_TYPE                       => new IfdEntry(ExifTag::SCENE_TYPE, 7, 1, chr(1)),
            ExifTag::CFA_PATTERN                      => new IfdEntry(ExifTag::CFA_PATTERN, 7, 4, "\x02\x01\x01\x02"),
            ExifTag::CUSTOM_RENDERED                  => new IfdEntry(ExifTag::CUSTOM_RENDERED, 3, 1, 1),
            ExifTag::DEVICE_SETTING_DESCRIPTION       => new IfdEntry(ExifTag::DEVICE_SETTING_DESCRIPTION, 7, 1, 'Neutral profile'),
            ExifTag::CAMERA_FIRMWARE                  => new IfdEntry(ExifTag::CAMERA_FIRMWARE, 2, 1, 'FW 2.0'),
            ExifTag::RAW_DEVELOPING_SOFTWARE          => new IfdEntry(ExifTag::RAW_DEVELOPING_SOFTWARE, 2, 1, 'RawLab'),
            ExifTag::IMAGE_EDITING_SOFTWARE           => new IfdEntry(ExifTag::IMAGE_EDITING_SOFTWARE, 2, 1, 'EditLab'),
            ExifTag::METADATA_EDITING_SOFTWARE        => new IfdEntry(ExifTag::METADATA_EDITING_SOFTWARE, 2, 1, 'MetaLab'),
            ExifTag::IMAGE_EDITING_SOFTWARE_LEGACY    => new IfdEntry(ExifTag::IMAGE_EDITING_SOFTWARE_LEGACY, 2, 1, 'EditLab'),
            ExifTag::METADATA_EDITING_SOFTWARE_LEGACY => new IfdEntry(ExifTag::METADATA_EDITING_SOFTWARE_LEGACY, 2, 1, 'MetaLab'),
            ExifTag::CAMERA_FIRMWARE_LEGACY           => new IfdEntry(ExifTag::CAMERA_FIRMWARE_LEGACY, 2, 1, 'FW 2.0'),
        ]);

        $doc = new ParsedExif($ifd0, $exifIfd, $gpsIfd, null, null);

        self::assertSame([1, 2, 3, 0], $doc->componentsConfiguration());
        self::assertSame(['Y', 'Cb', 'Cr', '-'], $doc->componentsConfigurationLabels());
        self::assertSame(4.5, $doc->compressedBitsPerPixel());
        self::assertSame('Calibrated output', $doc->userComment());
        self::assertSame('Spectral A', $doc->spectralSensitivity());
        $oecf = $doc->oecf();
        self::assertNotNull($oecf);
        self::assertSame($oecfPayload, $oecf['payload']);
        $oecfMatrix = $oecf['matrix'];
        self::assertNotNull($oecfMatrix);
        self::assertSame(2, $oecfMatrix['columns']);
        self::assertSame(2, $oecfMatrix['rows']);
        self::assertSame(['Input 0', 'Input 1'], $oecfMatrix['labels']['columns']);
        self::assertSame(['Channel R', 'Channel G'], $oecfMatrix['labels']['rows']);
        self::assertEqualsWithDelta(0.1, $oecfMatrix['values'][0][0] ?? 0.0, 0.0001);
        self::assertEqualsWithDelta(0.2, $oecfMatrix['values'][0][1] ?? 0.0, 0.0001);
        self::assertEqualsWithDelta(0.3, $oecfMatrix['values'][1][0] ?? 0.0, 0.0001);
        self::assertEqualsWithDelta(0.4, $oecfMatrix['values'][1][1] ?? 0.0, 0.0001);
        self::assertSame($oecfPayload, $doc->oecfPayload());
        self::assertSame(200, $doc->isoSpeedLatitudeYyy());
        self::assertSame(400, $doc->isoSpeedLatitudeZzz());
        self::assertSame(512, $doc->imageNumber());
        self::assertSame('Confidential', $doc->securityClassification());
        self::assertSame('Processed in RawLab', $doc->imageHistory());
        self::assertEqualsWithDelta(20.0, $doc->temperatureCelsius(), 0.0001);
        self::assertEqualsWithDelta(75.0, $doc->batteryLevelPercent() ?? 0.0, 0.0001);
        self::assertEqualsWithDelta(55.0, $doc->humidityPercent(), 0.0001);
        self::assertEqualsWithDelta(1000.0, $doc->pressureHPa(), 0.0001);
        self::assertEqualsWithDelta(3.0, $doc->waterDepthMeters(), 0.0001);
        self::assertEqualsWithDelta(10.0, $doc->accelerationMs2(), 0.0001);
        self::assertEqualsWithDelta(5.0, $doc->cameraElevationAngleDeg(), 0.0001);
        self::assertSame('clip.wav', $doc->relatedSoundFile());
        self::assertEqualsWithDelta(15.0, $doc->flashEnergy() ?? 0.0, 0.0001);
        $sfr = $doc->spatialFrequencyResponse();
        self::assertNotNull($sfr);
        self::assertSame(3, $sfr['columns']);
        self::assertSame(2, $sfr['rows']);
        self::assertSame(['10lp/mm', '20lp/mm', '40lp/mm'], $sfr['labels']['columns']);
        self::assertSame(['Luminance', 'Chrominance'], $sfr['labels']['rows']);
        self::assertCount(2, $sfr['values']);
        self::assertEqualsWithDelta(0.9, $sfr['values'][0][0] ?? 0.0, 0.0001);
        self::assertEqualsWithDelta(0.75, $sfr['values'][0][1] ?? 0.0, 0.0001);
        self::assertEqualsWithDelta(0.6, $sfr['values'][0][2] ?? 0.0, 0.0001);
        self::assertEqualsWithDelta(0.85, $sfr['values'][1][0] ?? 0.0, 0.0001);
        self::assertEqualsWithDelta(0.7, $sfr['values'][1][1] ?? 0.0, 0.0001);
        self::assertEqualsWithDelta(0.55, $sfr['values'][1][2] ?? 0.0, 0.0001);
        self::assertEqualsWithDelta(80.0, $doc->focalPlaneXResolution() ?? 0.0, 0.0001);
        self::assertEqualsWithDelta(79.0, $doc->focalPlaneYResolution() ?? 0.0, 0.0001);
        self::assertSame(2, $doc->focalPlaneResolutionUnit());
        self::assertSame([2, 0, 0, 0], $doc->tiffEpStandardId());
        self::assertSame('2.0.0.0', $doc->tiffEpStandardIdString());
        self::assertSame([1024, 768], $doc->subjectLocation());
        self::assertSame(320.0, $doc->exposureIndex());
        self::assertSame(SceneType::DIRECTLY_PHOTOGRAPHED_IMAGE, $doc->sceneType());
        self::assertSame([2, 1, 1, 2], $doc->cfaPattern());
        self::assertSame([
            CfaPatternColor::BLUE,
            CfaPatternColor::GREEN,
            CfaPatternColor::GREEN,
            CfaPatternColor::BLUE,
        ], $doc->cfaPatternColors());
        self::assertSame(CustomRendered::CUSTOM_PROCESS, $doc->customRendered());
        self::assertSame('Neutral profile', $doc->deviceSettingDescription());
        self::assertSame('Cliffside Dusk', $doc->imageTitle());
        self::assertSame('Alex Light', $doc->photographer());
        self::assertSame('Chris Edit', $doc->imageEditor());
        self::assertSame('FW 2.0', $doc->cameraFirmware());
        self::assertSame('RawLab', $doc->rawDevelopingSoftware());
        self::assertSame('EditLab', $doc->imageEditingSoftware());
        self::assertSame('MetaLab', $doc->metadataEditingSoftware());
    }

    #[Test]
    public function decodesPrintableTiffEpStandardIdBytes(): void
    {
        $ifd0 = new Ifd([]);

        $exifIfd = new Ifd([
            ExifTag::TIFF_EP_STANDARD_ID => new IfdEntry(
                ExifTag::TIFF_EP_STANDARD_ID,
                1,
                5,
                new ExifNumericList([0x30, 0x31, 0x30, 0x30, 0]),
            ),
        ]);

        $doc = new ParsedExif($ifd0, $exifIfd, null, null, null);

        self::assertSame([48, 49, 48, 48, 0], $doc->tiffEpStandardId());
        self::assertSame('0100', $doc->tiffEpStandardIdString());
    }

    #[Test]
    public function exposesAccelerationVectorWhenPresent(): void
    {
        $gpsIfd = new Ifd([
            ExifTag::ACCELERATION => new IfdEntry(
                ExifTag::ACCELERATION,
                10,
                3,
                new ExifRationalList([
                    new ExifRational(-3, 1),
                    new ExifRational(4, 1),
                    new ExifRational(0, 1),
                ]),
            ),
        ]);

        $doc = new ParsedExif(new Ifd([]), null, $gpsIfd, null, null);

        $vector = $doc->accelerationVector();
        self::assertNotNull($vector);
        self::assertSame([-3.0, 4.0, 0.0], $vector);

        $magnitude = $doc->accelerationMs2();
        self::assertNotNull($magnitude);
        self::assertEqualsWithDelta(5.0, $magnitude, 0.0001);
    }

    #[Test]
    public function accelerationFallsBackToExifWhenGpsMissing(): void
    {
        $exifIfd = new Ifd([
            ExifTag::ACCELERATION => new IfdEntry(
                ExifTag::ACCELERATION,
                10,
                3,
                new ExifRationalList([
                    new ExifRational(0, 1),
                    new ExifRational(0, 1),
                    new ExifRational(98, 10),
                ]),
            ),
        ]);

        $doc = new ParsedExif(new Ifd([]), $exifIfd, null, null, null);

        $vector = $doc->accelerationVector();
        self::assertNotNull($vector);
        self::assertEqualsWithDelta(0.0, $vector[0], 0.0001);
        self::assertEqualsWithDelta(0.0, $vector[1], 0.0001);
        self::assertEqualsWithDelta(9.8, $vector[2], 0.0001);

        $magnitude = $doc->accelerationMs2();
        self::assertNotNull($magnitude);
        self::assertEqualsWithDelta(9.8, $magnitude, 0.0001);
    }

    #[Test]
    public function userCommentFallsBackToIfd0WhenExifEntryMissing(): void
    {
        $payload = "ASCII\0\0\0Legacy fallback\0";

        $ifd0 = new Ifd([
            ExifTag::USER_COMMENT => new IfdEntry(ExifTag::USER_COMMENT, 7, 1, $payload),
        ]);

        $doc = new ParsedExif($ifd0, new Ifd([]), null, null, null);

        self::assertSame('Legacy fallback', $doc->userComment());
        self::assertSame('ASCII', $doc->userCommentEncodingBestEffort());
    }

    /**
     * Preserves ASCII user comments when the encoding prefix is omitted.
     */
    #[Test]
    public function preservesAsciiUserCommentsWithoutEncodingPrefix(): void
    {
        $ifd0 = new Ifd([]);

        $payload = "Note\0\0";

        $exifIfd = new Ifd([
            ExifTag::USER_COMMENT => new IfdEntry(ExifTag::USER_COMMENT, 7, 1, $payload),
        ]);

        $doc = new ParsedExif($ifd0, $exifIfd, null, null, null);

        self::assertSame('Note', $doc->userComment());
        self::assertSame('ASCII', $doc->userCommentEncoding());
    }

    /**
     * Treats non-standard UTF-8 user comment prefixes as undefined per the EXIF specification.
     */
    #[Test]
    public function treatsUtf8UserCommentPrefixAsUndefined(): void
    {
        $ifd0 = new Ifd([]);

        $payload = "UTF-8\0\0\0Résumé\0";

        $exifIfd = new Ifd([
            ExifTag::USER_COMMENT => new IfdEntry(ExifTag::USER_COMMENT, 7, 1, $payload),
        ]);

        $doc = new ParsedExif($ifd0, $exifIfd, null, null, null);

        self::assertSame('Résumé', $doc->userComment());
        self::assertSame('UNDEFINED', $doc->userCommentEncoding());
        self::assertSame('UNDEFINED', $doc->userCommentEncodingBestEffort());
    }

    #[Test]
    public function userCommentFallbacksToSubsequentIfds(): void
    {
        $ifd0       = new Ifd([]);
        $thumbnail  = new Ifd([]);
        $commentIfd = new Ifd([
            ExifTag::USER_COMMENT => new IfdEntry(ExifTag::USER_COMMENT, 7, 1, 'Fallback note'),
        ]);

        $doc = new ParsedExif(
            $ifd0,
            null,
            null,
            null,
            $thumbnail,
            null,
            [$thumbnail, $commentIfd],
            [],
        );

        self::assertSame('Fallback note', $doc->userComment());
        self::assertSame('ASCII', $doc->userCommentEncodingBestEffort());
    }

    /**
     * Decodes user comments tagged as Shift-JIS into UTF-8 strings.
     */
    #[Test]
    public function decodesShiftJisUserComments(): void
    {
        $ifd0 = new Ifd([]);

        $commentUtf8 = '富士山でのテスト';
        $encoded     = iconv('UTF-8', 'SJIS', $commentUtf8);
        self::assertNotFalse($encoded, 'Expected iconv to produce Shift-JIS bytes');

        // Shift-JIS bytes produced from the UTF-8 phrase ensure the documented round-trip behaviour.
        $payload = "JIS\0\0\0\0\0" . $encoded . "\0";

        $exifIfd = new Ifd([
            ExifTag::USER_COMMENT => new IfdEntry(ExifTag::USER_COMMENT, 7, 1, $payload),
        ]);

        $doc = new ParsedExif($ifd0, $exifIfd, null, null, null);

        self::assertSame(
            $commentUtf8,
            $doc->userComment(),
            'Expected Shift-JIS encoded bytes to decode to the documented UTF-8 phrase',
        );
        self::assertSame('JIS', $doc->userCommentEncoding());
    }

    /**
     * Falls back to a sanitized ASCII string when Shift-JIS decoding fails.
     */
    #[Test]
    public function fallsBackWhenShiftJisDecodingFails(): void
    {
        $ifd0 = new Ifd([]);

        // Leading 0xFF bytes cannot be decoded via Shift-JIS and trigger the ASCII fallback behaviour.
        $payload = "JIS\0\0\0\0\0\xFF\xFF Invalid metadata\0";

        $exifIfd = new Ifd([
            ExifTag::USER_COMMENT => new IfdEntry(ExifTag::USER_COMMENT, 7, 1, $payload),
        ]);

        $doc = new ParsedExif($ifd0, $exifIfd, null, null, null);

        self::assertSame(
            'Invalid metadata',
            $doc->userComment(),
            'Fallback should strip non-ASCII bytes and trim the decoded output',
        );
        self::assertSame('JIS', $doc->userCommentEncoding());
    }

    #[Test]
    public function decodesUtf16UserCommentsWithoutEncodingPrefix(): void
    {
        $ifd0 = new Ifd([]);

        $encoded = iconv('UTF-8', 'UTF-16LE', 'Grüße aus Köln');
        self::assertIsString($encoded);
        $payload = $encoded . "\0\0";

        $exifIfd = new Ifd([
            ExifTag::USER_COMMENT => new IfdEntry(ExifTag::USER_COMMENT, 7, strlen($payload), $payload),
        ]);

        $doc = new ParsedExif($ifd0, $exifIfd, null, null, null);

        self::assertSame('Grüße aus Köln', $doc->userComment());
        self::assertSame('UNICODE', $doc->userCommentEncoding());
        self::assertSame('UNICODE', $doc->userCommentEncodingBestEffort());
    }

    #[Test]
    public function userCommentEncodingBestEffortDetectsUtf8WithoutPrefix(): void
    {
        $ifd0 = new Ifd([]);

        $payload = 'Café 🌟';

        $exifIfd = new Ifd([
            ExifTag::USER_COMMENT => new IfdEntry(ExifTag::USER_COMMENT, 7, strlen($payload), $payload),
        ]);

        $doc = new ParsedExif($ifd0, $exifIfd, null, null, null);

        self::assertSame('Café 🌟', $doc->userComment());
        self::assertSame('UNDEFINED', $doc->userCommentEncoding());
        self::assertSame('UNDEFINED', $doc->userCommentEncodingBestEffort());
    }

    /**
     * Ensures image dimension getters fall back to values stored within IFD0 when EXIF tags are absent.
     */
    #[Test]
    public function imageDimensionsFallbackToIfd0(): void
    {
        $ifd0 = new Ifd([
            ExifTag::IMAGE_WIDTH  => new IfdEntry(ExifTag::IMAGE_WIDTH, 4, 1, 1024),
            ExifTag::IMAGE_HEIGHT => new IfdEntry(ExifTag::IMAGE_HEIGHT, 4, 1, 768),
        ]);

        $doc = new ParsedExif($ifd0, null, null, null, null);

        self::assertSame(1024, $doc->imageWidth());
        self::assertSame(768, $doc->imageHeight());
    }

    #[Test]
    public function processingSoftwareReturnsTrimmedString(): void
    {
        $ifd0 = new Ifd([
            ExifTag::PROCESSING_SOFTWARE => new IfdEntry(ExifTag::PROCESSING_SOFTWARE, 2, 1, "PixelLab\0\0"),
        ]);

        $exifIfd = new Ifd([
            ExifTag::EXIF_VERSION => new IfdEntry(ExifTag::EXIF_VERSION, 7, 4, '0300'),
        ]);

        $doc = new ParsedExif($ifd0, $exifIfd, null, null, null);

        self::assertSame('PixelLab', $doc->processingSoftware());
    }

    #[Test]
    public function processingSoftwareFallsBackToLegacyForExifTwo(): void
    {
        $ifd0 = new Ifd([
            ExifTag::SOFTWARE => new IfdEntry(ExifTag::SOFTWARE, 2, 1, 'Legacy Editor'),
        ]);

        $exifIfd = new Ifd([
            ExifTag::EXIF_VERSION => new IfdEntry(ExifTag::EXIF_VERSION, 7, 4, '0221'),
        ]);

        $doc = new ParsedExif($ifd0, $exifIfd, null, null, null);

        self::assertSame('Legacy Editor', $doc->processingSoftware());
    }

    #[Test]
    public function textualSoftwareTagsFallbackToLegacyIdentifiers(): void
    {
        $ifd0 = new Ifd([
            ExifTag::IMAGE_TITLE_LEGACY  => new IfdEntry(ExifTag::IMAGE_TITLE_LEGACY, 2, 1, 'Legacy Title'),
            ExifTag::PHOTOGRAPHER_LEGACY => new IfdEntry(ExifTag::PHOTOGRAPHER_LEGACY, 2, 1, 'Legacy Photographer'),
            ExifTag::IMAGE_EDITOR_LEGACY => new IfdEntry(ExifTag::IMAGE_EDITOR_LEGACY, 2, 1, 'Legacy Editor'),
            ExifTag::ARTIST              => new IfdEntry(ExifTag::ARTIST, 2, 1, 'Fallback Artist'),
            ExifTag::IMAGE_DESCRIPTION   => new IfdEntry(ExifTag::IMAGE_DESCRIPTION, 2, 1, 'Fallback Description'),
        ]);

        $exifIfd = new Ifd([
            ExifTag::CAMERA_FIRMWARE_LEGACY           => new IfdEntry(ExifTag::CAMERA_FIRMWARE_LEGACY, 2, 1, 'Legacy FW'),
            ExifTag::RAW_DEVELOPING_SOFTWARE_LEGACY   => new IfdEntry(ExifTag::RAW_DEVELOPING_SOFTWARE_LEGACY, 2, 1, 'Legacy Raw'),
            ExifTag::IMAGE_EDITING_SOFTWARE_LEGACY    => new IfdEntry(ExifTag::IMAGE_EDITING_SOFTWARE_LEGACY, 2, 1, 'Legacy Edit'),
            ExifTag::METADATA_EDITING_SOFTWARE_LEGACY => new IfdEntry(ExifTag::METADATA_EDITING_SOFTWARE_LEGACY, 2, 1, 'Legacy Meta'),
        ]);

        $doc = new ParsedExif($ifd0, $exifIfd, null, null, null);

        self::assertSame('Legacy Title', $doc->imageTitle());
        self::assertSame('Legacy Photographer', $doc->photographer());
        self::assertSame('Legacy Editor', $doc->imageEditor());
        self::assertSame('Legacy FW', $doc->cameraFirmware());
        self::assertSame('Legacy Raw', $doc->rawDevelopingSoftware());
        self::assertSame('Legacy Edit', $doc->imageEditingSoftware());
        self::assertSame('Legacy Meta', $doc->metadataEditingSoftware());
    }

    #[Test]
    public function exifTwoPrefersLegacySoftwareNamesOverVersionStrings(): void
    {
        $exifIfd = new Ifd([
            ExifTag::EXIF_VERSION                     => new IfdEntry(ExifTag::EXIF_VERSION, 7, 4, '0221'),
            ExifTag::CAMERA_FIRMWARE                  => new IfdEntry(ExifTag::CAMERA_FIRMWARE, 2, 1, 'FW 1.2.3'),
            ExifTag::IMAGE_EDITING_SOFTWARE           => new IfdEntry(ExifTag::IMAGE_EDITING_SOFTWARE, 2, 1, 'Edit Suite 4.5'),
            ExifTag::METADATA_EDITING_SOFTWARE        => new IfdEntry(ExifTag::METADATA_EDITING_SOFTWARE, 2, 1, 'Meta Suite 6.7'),
            ExifTag::CAMERA_FIRMWARE_LEGACY           => new IfdEntry(ExifTag::CAMERA_FIRMWARE_LEGACY, 2, 1, 'Legacy Firmware Name'),
            ExifTag::IMAGE_EDITING_SOFTWARE_LEGACY    => new IfdEntry(ExifTag::IMAGE_EDITING_SOFTWARE_LEGACY, 2, 1, 'Legacy Editor Name'),
            ExifTag::METADATA_EDITING_SOFTWARE_LEGACY => new IfdEntry(ExifTag::METADATA_EDITING_SOFTWARE_LEGACY, 2, 1, 'Legacy Metadata Name'),
        ]);

        $doc = new ParsedExif(new Ifd([]), $exifIfd, null, null, null);

        self::assertSame('Legacy Firmware Name', $doc->cameraFirmware());
        self::assertSame('Legacy Editor Name', $doc->imageEditingSoftware());
        self::assertSame('Legacy Metadata Name', $doc->metadataEditingSoftware());
    }

    #[Test]
    public function textualSoftwareVersionsUseLegacyTags(): void
    {
        $exifIfd = new Ifd([
            ExifTag::CAMERA_FIRMWARE_VERSION_LEGACY           => new IfdEntry(ExifTag::CAMERA_FIRMWARE_VERSION_LEGACY, 2, 1, 'FW 3.1.0'),
            ExifTag::RAW_DEVELOPING_SOFTWARE_VERSION_LEGACY   => new IfdEntry(ExifTag::RAW_DEVELOPING_SOFTWARE_VERSION_LEGACY, 2, 1, 'RawLab 5.2.1'),
            ExifTag::IMAGE_EDITING_SOFTWARE_VERSION_LEGACY    => new IfdEntry(ExifTag::IMAGE_EDITING_SOFTWARE_VERSION_LEGACY, 2, 1, 'ImageLab 2.3'),
            ExifTag::METADATA_EDITING_SOFTWARE_VERSION_LEGACY => new IfdEntry(ExifTag::METADATA_EDITING_SOFTWARE_VERSION_LEGACY, 2, 1, 'MetaLab 1.0.0'),
        ]);

        $doc = new ParsedExif(new Ifd([]), $exifIfd, null, null, null);

        self::assertSame('FW 3.1.0', $doc->cameraFirmwareVersion());
        self::assertSame('RawLab 5.2.1', $doc->rawDevelopingSoftwareVersion());
        self::assertSame('ImageLab 2.3', $doc->imageEditingSoftwareVersion());
        self::assertSame('MetaLab 1.0.0', $doc->metadataEditingSoftwareVersion());
    }

    #[Test]
    public function hostComputerReturnsLegacyValue(): void
    {
        $ifd0 = new Ifd([
            ExifTag::HOST_COMPUTER => new IfdEntry(ExifTag::HOST_COMPUTER, 2, 1, 'PowerMac G4'),
        ]);

        $exifIfd = new Ifd([
            ExifTag::EXIF_VERSION => new IfdEntry(ExifTag::EXIF_VERSION, 7, 4, '0221'),
        ]);

        $doc = new ParsedExif($ifd0, $exifIfd, null, null, null);

        self::assertSame('PowerMac G4', $doc->hostComputer());
    }

    #[Test]
    public function hostComputerOmittedForExifThree(): void
    {
        $ifd0 = new Ifd([
            ExifTag::HOST_COMPUTER => new IfdEntry(ExifTag::HOST_COMPUTER, 2, 1, 'Workstation'),
        ]);

        $exifIfd = new Ifd([
            ExifTag::EXIF_VERSION => new IfdEntry(ExifTag::EXIF_VERSION, 7, 4, '0300'),
        ]);

        $doc = new ParsedExif($ifd0, $exifIfd, null, null, null);

        self::assertNull($doc->hostComputer());
    }

    #[Test]
    public function exifVersionDefaultsToTwoPointTwoWhenTagMissing(): void
    {
        $doc = new ParsedExif(new Ifd([]), null, null, null, null);

        self::assertSame('2.2', $doc->exifVersion());
    }

    #[Test]
    public function exifVersionDefaultsToTwoPointTwoWhenTagEmpty(): void
    {
        $emptyExifIfd = new Ifd([
            ExifTag::EXIF_VERSION => new IfdEntry(ExifTag::EXIF_VERSION, 7, 4, "\x00\x00\x00\x00"),
        ]);

        $doc = new ParsedExif(new Ifd([]), $emptyExifIfd, null, null, null);

        self::assertSame('2.2', $doc->exifVersion());
    }

    #[Test]
    public function exifThreeOmitsLegacySoftwareVersions(): void
    {
        $ifd0 = new Ifd([
            ExifTag::IMAGE_TITLE => new IfdEntry(ExifTag::IMAGE_TITLE, 2, 1, 'Autumn Sunset'),
        ]);

        $exifIfd = new Ifd([
            ExifTag::EXIF_VERSION              => new IfdEntry(ExifTag::EXIF_VERSION, 7, 4, '0300'),
            ExifTag::CAMERA_FIRMWARE           => new IfdEntry(ExifTag::CAMERA_FIRMWARE, 2, 1, 'Firmware Build 5'),
            ExifTag::RAW_DEVELOPING_SOFTWARE   => new IfdEntry(ExifTag::RAW_DEVELOPING_SOFTWARE, 2, 1, 'Raw Developer X'),
            ExifTag::IMAGE_EDITING_SOFTWARE    => new IfdEntry(ExifTag::IMAGE_EDITING_SOFTWARE, 2, 1, 'Image Editor Y'),
            ExifTag::METADATA_EDITING_SOFTWARE => new IfdEntry(ExifTag::METADATA_EDITING_SOFTWARE, 2, 1, 'Metadata Tool Z'),
        ]);

        $doc = new ParsedExif($ifd0, $exifIfd, null, null, null);

        self::assertSame('3.00', $doc->exifVersion());
        self::assertSame('3.0', $doc->exifProfile());
        self::assertSame('Autumn Sunset', $doc->imageTitle());
        self::assertSame('Firmware Build 5', $doc->cameraFirmware());
        self::assertNull($doc->cameraFirmwareVersion());
        self::assertSame('Raw Developer X', $doc->rawDevelopingSoftware());
        self::assertNull($doc->rawDevelopingSoftwareVersion());
        self::assertSame('Image Editor Y', $doc->imageEditingSoftware());
        self::assertNull($doc->imageEditingSoftwareVersion());
        self::assertSame('Metadata Tool Z', $doc->metadataEditingSoftware());
        self::assertNull($doc->metadataEditingSoftwareVersion());
    }

    #[Test]
    public function profileHueSatMapIncludesEncodings(): void
    {
        $profileIfd = new Ifd([
            ExifTag::PROFILE_HUE_SAT_MAP_DIMS      => new IfdEntry(ExifTag::PROFILE_HUE_SAT_MAP_DIMS, 4, 3, new ExifNumericList([4, 2, 1])),
            ExifTag::PROFILE_HUE_SAT_MAP_ENCODINGS => new IfdEntry(ExifTag::PROFILE_HUE_SAT_MAP_ENCODINGS, 3, 3, new ExifNumericList([0, 2, 1])),
            ExifTag::PROFILE_HUE_SAT_MAP_DATA_1    => new IfdEntry(ExifTag::PROFILE_HUE_SAT_MAP_DATA_1, 11, 2, new ExifNumericList([0.25, 0.5])),
        ]);

        $document = new ParsedExif(new Ifd([]), null, null, null, null, null, [], [
            0 => $profileIfd,
        ]);

        $map = $document->profileHueSatMap();

        self::assertNotNull($map);
        self::assertSame([4, 2, 1], $map['dimensions']);
        self::assertSame([0, 2, 1], $map['encodings']);
        self::assertSame([0.25, 0.5], $map['map1']);
    }

    #[Test]
    #[DataProvider('provideExifVersionMatrix')]
    public function normalizesExifVersions(string $raw, string $expectedVersion, string $expectedProfile): void
    {
        $ifd0 = new Ifd([]);

        $exifIfd = new Ifd([
            ExifTag::EXIF_VERSION => new IfdEntry(ExifTag::EXIF_VERSION, 7, strlen($raw), $raw),
        ]);

        $doc = new ParsedExif($ifd0, $exifIfd, null, null, null);

        self::assertSame($expectedVersion, $doc->exifVersion());
        self::assertSame($expectedProfile, $doc->exifProfile());
    }

    /**
     * @return iterable<string, array{string,string,string}>
     */
    public static function provideExifVersionMatrix(): iterable
    {
        yield '1.0' => ['0100', '1.00', '1.0'];
        yield '1.1' => ['0110', '1.10', '1.1'];
        yield '2.1' => ['0210', '2.10', '2.1'];
        yield '2.2' => ['0220', '2.20', '2.2'];
        yield '2.21' => ['0221', '2.21', '2.21'];
        yield '2.3' => ['0230', '2.30', '2.3'];
        yield '2.31' => ['0231', '2.31', '2.31'];
        yield '2.32' => ['0232', '2.32', '2.32'];
        yield '3.0' => ['0300', '3.00', '3.0'];
    }

    /**
     * Ensures the ISO getter prefers EXIF 3.0 sensitivity tags before falling back to photographic sensitivity.
     */
    #[Test]
    public function isoPrefersStandardOutputAndRecommendedIndices(): void
    {
        $ifd0 = new Ifd([
            ExifTag::PHOTOGRAPHIC_SENSITIVITY => new IfdEntry(ExifTag::PHOTOGRAPHIC_SENSITIVITY, 3, 1, 100),
        ]);

        $exifIfd = new Ifd([
            ExifTag::STANDARD_OUTPUT_SENSITIVITY => new IfdEntry(ExifTag::STANDARD_OUTPUT_SENSITIVITY, 3, 1, 160),
            ExifTag::RECOMMENDED_EXPOSURE_INDEX  => new IfdEntry(ExifTag::RECOMMENDED_EXPOSURE_INDEX, 3, 1, 320),
            ExifTag::PHOTOGRAPHIC_SENSITIVITY    => new IfdEntry(ExifTag::PHOTOGRAPHIC_SENSITIVITY, 3, 1, 640),
        ]);

        $doc = new ParsedExif($ifd0, $exifIfd, null, null, null);
        self::assertSame(160, $doc->iso());

        $recommendedExifIfd = new Ifd([
            ExifTag::RECOMMENDED_EXPOSURE_INDEX => new IfdEntry(ExifTag::RECOMMENDED_EXPOSURE_INDEX, 3, 1, 320),
            ExifTag::PHOTOGRAPHIC_SENSITIVITY   => new IfdEntry(ExifTag::PHOTOGRAPHIC_SENSITIVITY, 3, 1, 640),
        ]);

        $docWithRecommended = new ParsedExif($ifd0, $recommendedExifIfd, null, null, null);
        self::assertSame(320, $docWithRecommended->iso());

        $photographicExifIfd = new Ifd([
            ExifTag::PHOTOGRAPHIC_SENSITIVITY => new IfdEntry(ExifTag::PHOTOGRAPHIC_SENSITIVITY, 3, 1, 640),
        ]);

        $docWithPhotographic = new ParsedExif($ifd0, $photographicExifIfd, null, null, null);
        self::assertSame(640, $docWithPhotographic->iso());
    }

    /**
     * Ensures the ISO getter falls back to legacy tags when the ISO speed tag is missing.
     */
    #[Test]
    public function isoFallsBackToLegacyTags(): void
    {
        $ifd0 = new Ifd([
            ExifTag::PHOTOGRAPHIC_SENSITIVITY => new IfdEntry(ExifTag::PHOTOGRAPHIC_SENSITIVITY, 3, 1, 200),
        ]);

        $exifIfd = new Ifd([
            ExifTag::PHOTOGRAPHIC_SENSITIVITY => new IfdEntry(ExifTag::PHOTOGRAPHIC_SENSITIVITY, 3, 1, 320),
        ]);

        $doc = new ParsedExif($ifd0, $exifIfd, null, null, null);

        self::assertSame(320, $doc->iso());

        $docWithoutExif = new ParsedExif($ifd0, null, null, null, null);
        self::assertSame(200, $docWithoutExif->iso());
    }

    #[Test]
    public function isoFallsBackToLegacyIsoSpeedRatings(): void
    {
        $ifd0 = new Ifd([
            ExifTag::ISO_SPEED_RATINGS_LEGACY => new IfdEntry(ExifTag::ISO_SPEED_RATINGS_LEGACY, 3, 1, 400),
        ]);

        $exifIfd = new Ifd([
            ExifTag::ISO_SPEED_RATINGS_LEGACY => new IfdEntry(ExifTag::ISO_SPEED_RATINGS_LEGACY, 3, 1, 800),
        ]);

        $doc = new ParsedExif($ifd0, $exifIfd, null, null, null);

        self::assertSame(800, $doc->iso());
        self::assertSame(800, $doc->isoBestEffort());

        $docWithoutExif = new ParsedExif($ifd0, null, null, null, null);

        self::assertSame(400, $docWithoutExif->iso());
        self::assertSame(400, $docWithoutExif->isoBestEffort());
    }

    #[Test]
    public function isoParsesAsciiEncodedValues(): void
    {
        $ifd0 = new Ifd([]);

        $exifIfd = new Ifd([
            ExifTag::ISO_SPEED => new IfdEntry(ExifTag::ISO_SPEED, 2, 1, '0200'),
        ]);

        $doc = new ParsedExif($ifd0, $exifIfd, null, null, null);

        self::assertSame(200, $doc->iso());
        self::assertSame(200, $doc->isoBestEffort());
    }

    #[Test]
    public function isoBestEffortParsesIsoPrefixedStrings(): void
    {
        $exifIfd = new Ifd([
            ExifTag::ISO_SPEED => new IfdEntry(ExifTag::ISO_SPEED, 2, 7, 'ISO 800'),
        ]);

        $doc = new ParsedExif(new Ifd([]), $exifIfd, null, null, null);

        self::assertSame(800, $doc->isoBestEffort());
    }

    #[Test]
    public function isoBestEffortFallsBackToExposureIndex(): void
    {
        $exifIfd = new Ifd([
            ExifTag::EXPOSURE_INDEX => new IfdEntry(
                ExifTag::EXPOSURE_INDEX,
                5,
                1,
                new ExifRational(400, 1),
            ),
        ]);

        $doc = new ParsedExif(new Ifd([]), $exifIfd, null, null, null);

        self::assertSame(400, $doc->isoBestEffort());
    }

    #[Test]
    public function isoBestEffortFallsBackToSubsequentIfds(): void
    {
        $ifd0      = new Ifd([]);
        $thumbnail = new Ifd([]);
        $isoIfd    = new Ifd([
            ExifTag::ISO_SPEED => new IfdEntry(ExifTag::ISO_SPEED, 3, 1, 640),
        ]);

        $doc = new ParsedExif(
            $ifd0,
            null,
            null,
            null,
            $thumbnail,
            null,
            [$thumbnail, $isoIfd],
            [],
        );

        self::assertSame(640, $doc->isoBestEffort());
    }

    #[Test]
    public function isoBestEffortReadsFromSubIfds(): void
    {
        $subIfd = new Ifd([
            ExifTag::PHOTOGRAPHIC_SENSITIVITY => new IfdEntry(
                ExifTag::PHOTOGRAPHIC_SENSITIVITY,
                3,
                1,
                640,
            ),
        ]);

        $doc = new ParsedExif(new Ifd([]), null, null, null, null, null, [], [256 => $subIfd]);

        self::assertSame(640, $doc->isoBestEffort());
    }

    #[Test]
    public function isoFallsBackToSubIfdsWhenPrimaryTagsAreMissing(): void
    {
        $subIfd = new Ifd([
            ExifTag::PHOTOGRAPHIC_SENSITIVITY => new IfdEntry(
                ExifTag::PHOTOGRAPHIC_SENSITIVITY,
                3,
                1,
                512,
            ),
        ]);

        $doc = new ParsedExif(new Ifd([]), null, null, null, null, null, [], [1024 => $subIfd]);

        self::assertSame(512, $doc->iso());
        self::assertSame(512, $doc->isoBestEffort());
    }

    #[Test]
    public function isoFallsBackToThumbnailIfdWhenAvailable(): void
    {
        $ifd1 = new Ifd([
            ExifTag::PHOTOGRAPHIC_SENSITIVITY => new IfdEntry(
                ExifTag::PHOTOGRAPHIC_SENSITIVITY,
                3,
                1,
                1600,
            ),
        ]);

        $doc = new ParsedExif(new Ifd([]), null, null, null, $ifd1, null, [$ifd1], []);

        self::assertSame(1600, $doc->iso());
        self::assertSame(1600, $doc->isoBestEffort());
    }

    #[Test]
    public function dateTimeOriginalFallsBackToThumbnailIfdMetadata(): void
    {
        $ifd1 = new Ifd([
            ExifTag::DATETIME_ORIGINAL    => new IfdEntry(ExifTag::DATETIME_ORIGINAL, 2, 1, '2024:05:06 07:08:09'),
            ExifTag::OFFSET_TIME_ORIGINAL => new IfdEntry(ExifTag::OFFSET_TIME_ORIGINAL, 2, 1, '+02:00'),
        ]);

        $doc = new ParsedExif(new Ifd([]), null, null, null, $ifd1, null, [$ifd1], []);

        self::assertSame('2024:05:06 07:08:09', $doc->dateTimeOriginalRaw());

        $dateTime = $doc->dateTimeOriginalBestEffort();
        self::assertNotNull($dateTime);
        self::assertSame('2024-05-06T07:08:09+02:00', $dateTime->format(DATE_ATOM));
    }

    #[Test]
    public function userCommentEncodingFallsBackToThumbnailIfd(): void
    {
        $comment = str_pad('ASCII', 8, "\0") . 'Fallback comment';

        $ifd1 = new Ifd([
            ExifTag::USER_COMMENT => new IfdEntry(ExifTag::USER_COMMENT, 7, 1, $comment),
        ]);

        $doc = new ParsedExif(new Ifd([]), null, null, null, $ifd1, null, [$ifd1], []);

        self::assertSame('Fallback comment', $doc->userComment());
        self::assertSame('ASCII', $doc->userCommentEncoding());
        self::assertSame('ASCII', $doc->userCommentEncodingBestEffort());
    }

    /**
     * Ensures GPS metadata parsed via the TIFF reader is normalised and exposed via dedicated helpers.
     */
    #[Test]
    public function parsesGpsMetadataFromSyntheticTiff(): void
    {
        $document = (new TiffExifReader())->parseFromBlob(GpsTiffBuilder::buildClassicGpsTiff());

        $gps = $document->gps();

        self::assertSame('N', $gps['lat_ref']);
        self::assertEqualsWithDelta(51.5, $gps['lat'], 0.000001);
        self::assertSame('E', $gps['lon_ref']);
        self::assertEqualsWithDelta(8.5, $gps['lon'], 0.000001);
        self::assertSame(0, $gps['alt_ref']);
        self::assertEqualsWithDelta(150.0, $gps['alt'], 0.000001);
        self::assertEqualsWithDelta(90.0, $gps['track'], 0.000001);
        self::assertEqualsWithDelta(45.0, $gps['img_direction'], 0.000001);
        self::assertEqualsWithDelta(45.0, $gps['dest_bearing'], 0.000001);
        self::assertEqualsWithDelta(42000.0, $gps['dest_distance_m'], 0.000001);

        self::assertSame('K', $document->gpsSpeedRef());
        self::assertEqualsWithDelta(20.0, $document->gpsSpeedMetresPerSecond(), 0.000001);
        self::assertSame('T', $document->gpsTrackRef());
        self::assertEqualsWithDelta(90.0, $document->gpsTrack(), 0.000001);
        self::assertSame('M', $document->gpsImgDirectionRef());
        self::assertEqualsWithDelta(45.0, $document->gpsImgDirection(), 0.000001);
        self::assertSame('T', $document->gpsDestinationBearingRef());
        self::assertEqualsWithDelta(45.0, $document->gpsDestinationBearing(), 0.000001);
        self::assertSame('K', $document->gpsDestinationDistanceRef());
        self::assertEqualsWithDelta(42000.0, $document->gpsDestinationDistanceMetres(), 0.000001);
        self::assertSame('2024-05-06', $document->gpsDateStamp());
        self::assertSame('12:34:56.789', $document->gpsTimeStampString());

        $timestamp = $document->gpsTimestamp();
        self::assertInstanceOf(DateTimeImmutable::class, $timestamp);
        self::assertSame('2024-05-06T12:34:56+00:00', $timestamp->format(DATE_ATOM));

        self::assertSame(2, $document->gpsDifferential());
        self::assertEqualsWithDelta(1.5, $document->gpsHorizontalPositioningError(), 0.000001);
    }

    #[Test]
    public function exposesUavMetadataWhenPresent(): void
    {
        $ifd0    = new Ifd([]);
        $exifIfd = new Ifd([
            ExifTag::AIRCRAFT_MAKE  => new IfdEntry(ExifTag::AIRCRAFT_MAKE, 2, 1, 'DJI'),
            ExifTag::AIRCRAFT_MODEL => new IfdEntry(ExifTag::AIRCRAFT_MODEL, 2, 1, 'Mavic 3'),
        ]);

        $gpsIfd = new Ifd([
            ExifTag::CAMERA_YAW_DEGREE   => new IfdEntry(ExifTag::CAMERA_YAW_DEGREE, 10, 1, new ExifRational(123, 10)),
            ExifTag::CAMERA_PITCH_DEGREE => new IfdEntry(ExifTag::CAMERA_PITCH_DEGREE, 10, 1, new ExifRational(-45, 10)),
            ExifTag::CAMERA_ROLL_DEGREE  => new IfdEntry(ExifTag::CAMERA_ROLL_DEGREE, 10, 1, new ExifRational(15, 10)),
            ExifTag::GIMBAL_YAW_DEGREE   => new IfdEntry(ExifTag::GIMBAL_YAW_DEGREE, 10, 1, new ExifRational(321, 10)),
            ExifTag::GIMBAL_PITCH_DEGREE => new IfdEntry(ExifTag::GIMBAL_PITCH_DEGREE, 10, 1, new ExifRational(-210, 10)),
            ExifTag::GIMBAL_ROLL_DEGREE  => new IfdEntry(ExifTag::GIMBAL_ROLL_DEGREE, 10, 1, new ExifRational(-5, 10)),
        ]);

        $document = new ParsedExif($ifd0, $exifIfd, $gpsIfd, null, null);

        self::assertSame('DJI', $document->aircraftMake());
        self::assertSame('Mavic 3', $document->aircraftModel());
        self::assertEqualsWithDelta(12.3, $document->cameraYawDeg(), 0.0001);
        self::assertEqualsWithDelta(-4.5, $document->cameraPitchDeg(), 0.0001);
        self::assertEqualsWithDelta(1.5, $document->cameraRollDeg(), 0.0001);
        self::assertEqualsWithDelta(12.3, $document->flightYawDeg(), 0.0001);
        self::assertEqualsWithDelta(-4.5, $document->flightPitchDeg(), 0.0001);
        self::assertEqualsWithDelta(1.5, $document->flightRollDeg(), 0.0001);
        self::assertEqualsWithDelta(32.1, $document->gimbalYawDeg(), 0.0001);
        self::assertEqualsWithDelta(-21.0, $document->gimbalPitchDeg(), 0.0001);
        self::assertEqualsWithDelta(-0.5, $document->gimbalRollDeg(), 0.0001);
    }

    #[Test]
    public function exposesUavMetadataFromExifWhenGpsMissing(): void
    {
        $ifd0    = new Ifd([]);
        $exifIfd = new Ifd([
            ExifTag::AIRCRAFT_MAKE       => new IfdEntry(ExifTag::AIRCRAFT_MAKE, 2, 1, 'DJI'),
            ExifTag::AIRCRAFT_MODEL      => new IfdEntry(ExifTag::AIRCRAFT_MODEL, 2, 1, 'Mavic 3'),
            ExifTag::CAMERA_YAW_DEGREE   => new IfdEntry(ExifTag::CAMERA_YAW_DEGREE, 10, 1, new ExifRational(123, 10)),
            ExifTag::CAMERA_PITCH_DEGREE => new IfdEntry(ExifTag::CAMERA_PITCH_DEGREE, 10, 1, new ExifRational(-45, 10)),
            ExifTag::CAMERA_ROLL_DEGREE  => new IfdEntry(ExifTag::CAMERA_ROLL_DEGREE, 10, 1, new ExifRational(15, 10)),
            ExifTag::GIMBAL_YAW_DEGREE   => new IfdEntry(ExifTag::GIMBAL_YAW_DEGREE, 10, 1, new ExifRational(321, 10)),
            ExifTag::GIMBAL_PITCH_DEGREE => new IfdEntry(ExifTag::GIMBAL_PITCH_DEGREE, 10, 1, new ExifRational(-210, 10)),
            ExifTag::GIMBAL_ROLL_DEGREE  => new IfdEntry(ExifTag::GIMBAL_ROLL_DEGREE, 10, 1, new ExifRational(-5, 10)),
        ]);

        $document = new ParsedExif($ifd0, $exifIfd, null, null, null);

        self::assertSame('DJI', $document->aircraftMake());
        self::assertSame('Mavic 3', $document->aircraftModel());
        self::assertEqualsWithDelta(12.3, $document->cameraYawDeg(), 0.0001);
        self::assertEqualsWithDelta(-4.5, $document->cameraPitchDeg(), 0.0001);
        self::assertEqualsWithDelta(1.5, $document->cameraRollDeg(), 0.0001);
        self::assertEqualsWithDelta(12.3, $document->flightYawDeg(), 0.0001);
        self::assertEqualsWithDelta(-4.5, $document->flightPitchDeg(), 0.0001);
        self::assertEqualsWithDelta(1.5, $document->flightRollDeg(), 0.0001);
        self::assertEqualsWithDelta(32.1, $document->gimbalYawDeg(), 0.0001);
        self::assertEqualsWithDelta(-21.0, $document->gimbalPitchDeg(), 0.0001);
        self::assertEqualsWithDelta(-0.5, $document->gimbalRollDeg(), 0.0001);
    }

    private function buildOecfPayload(): string
    {
        $columns = 2;
        $rows    = 2;

        $payload = pack('n', $columns) . pack('n', $rows);
        $payload .= "Input 0\0";
        $payload .= "Input 1\0";
        $payload .= "Channel R\0";
        $payload .= "Channel G\0";

        $payload .= $this->packSrational(1, 10);
        $payload .= $this->packSrational(2, 10);
        $payload .= $this->packSrational(3, 10);
        $payload .= $this->packSrational(4, 10);

        return $payload;
    }

    private function buildSpatialFrequencyResponsePayload(): string
    {
        $columns = 3;
        $rows    = 2;

        $payload = pack('n', $columns) . pack('n', $rows);
        $payload .= "10lp/mm\0";
        $payload .= "20lp/mm\0";
        $payload .= "40lp/mm\0";
        $payload .= "Luminance\0";
        $payload .= "Chrominance\0";

        $payload .= $this->packSrational(90, 100);
        $payload .= $this->packSrational(75, 100);
        $payload .= $this->packSrational(60, 100);
        $payload .= $this->packSrational(85, 100);
        $payload .= $this->packSrational(70, 100);
        $payload .= $this->packSrational(55, 100);

        return $payload;
    }

    private function packSrational(int $numerator, int $denominator): string
    {
        return pack('N', $numerator) . pack('N', $denominator);
    }
}
