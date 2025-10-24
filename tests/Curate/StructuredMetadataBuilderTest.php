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
use MagicSunday\ImageMeta\Model\Exif\ExifNumericList;
use MagicSunday\ImageMeta\Model\Exif\ExifTag;
use MagicSunday\ImageMeta\Model\Exif\Ifd;
use MagicSunday\ImageMeta\Model\Exif\IfdEntry;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Model\QuickTimeMeta;
use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;
use MagicSunday\ImageMeta\Value\Enum\ColorSpace;
use MagicSunday\ImageMeta\Value\Enum\CompositeImage;
use MagicSunday\ImageMeta\Value\Enum\Compression;
use MagicSunday\ImageMeta\Value\Enum\ExposureMode;
use MagicSunday\ImageMeta\Value\Enum\ExposureProgram;
use MagicSunday\ImageMeta\Value\Enum\FileSource;
use MagicSunday\ImageMeta\Value\Enum\GainControl;
use MagicSunday\ImageMeta\Value\Enum\LightSource;
use MagicSunday\ImageMeta\Value\Enum\MeteringMode;
use MagicSunday\ImageMeta\Value\Enum\Orientation;
use MagicSunday\ImageMeta\Value\Enum\Photometric;
use MagicSunday\ImageMeta\Value\Enum\PlanarConfiguration;
use MagicSunday\ImageMeta\Value\Enum\ResolutionUnit;
use MagicSunday\ImageMeta\Value\Enum\SceneCaptureType;
use MagicSunday\ImageMeta\Value\Enum\SensingMethod;
use MagicSunday\ImageMeta\Value\Enum\SubjectDistanceRange;
use MagicSunday\ImageMeta\Value\Enum\WhiteBalance;
use MagicSunday\ImageMeta\Value\Enum\YCbCrPositioning;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MagicSunday\ImageMeta\Curate\StructuredMetadataBuilder
 * @covers \MagicSunday\ImageMeta\Curate\StructuredMetadata
 */
final class StructuredMetadataBuilderTest extends TestCase
{
    /**
     * Ensures DSLR style EXIF data is mapped to the extended value objects.
     */
    #[Test]
    public function buildsStructuredAggregateForDslrJpeg(): void
    {
        $ifd0 = new Ifd([
            ExifTag::IMAGE_WIDTH                => new IfdEntry(ExifTag::IMAGE_WIDTH, 4, 1, 6720),
            ExifTag::IMAGE_HEIGHT               => new IfdEntry(ExifTag::IMAGE_HEIGHT, 4, 1, 4480),
            ExifTag::BITS_PER_SAMPLE            => new IfdEntry(ExifTag::BITS_PER_SAMPLE, 3, 3, new ExifNumericList([14, 14, 14])),
            ExifTag::COMPRESSION                => new IfdEntry(ExifTag::COMPRESSION, 3, 1, Compression::JPEG->value),
            ExifTag::PHOTOMETRIC_INTERPRETATION => new IfdEntry(ExifTag::PHOTOMETRIC_INTERPRETATION, 3, 1, Photometric::YCBCR->value),
            ExifTag::PLANAR_CONFIGURATION       => new IfdEntry(ExifTag::PLANAR_CONFIGURATION, 3, 1, PlanarConfiguration::CHUNKY->value),
            ExifTag::RESOLUTION_UNIT            => new IfdEntry(ExifTag::RESOLUTION_UNIT, 3, 1, ResolutionUnit::INCHES->value),
            ExifTag::X_RESOLUTION               => new IfdEntry(ExifTag::X_RESOLUTION, 5, 1, [[300, 1]]),
            ExifTag::Y_RESOLUTION               => new IfdEntry(ExifTag::Y_RESOLUTION, 5, 1, [[300, 1]]),
            ExifTag::YCBCR_POSITIONING          => new IfdEntry(ExifTag::YCBCR_POSITIONING, 3, 1, YCbCrPositioning::CENTERED->value),
            ExifTag::YCBCR_SUB_SAMPLING         => new IfdEntry(ExifTag::YCBCR_SUB_SAMPLING, 3, 2, new ExifNumericList([2, 2])),
            ExifTag::YCBCR_COEFFICIENTS         => new IfdEntry(ExifTag::YCBCR_COEFFICIENTS, 5, 3, [[299, 1000], [587, 1000], [114, 1000]]),
            ExifTag::WHITE_POINT                => new IfdEntry(ExifTag::WHITE_POINT, 5, 2, [[3127, 10000], [3290, 10000]]),
            ExifTag::PRIMARY_CHROMATICITIES     => new IfdEntry(ExifTag::PRIMARY_CHROMATICITIES, 5, 6, [[6400, 10000], [3300, 10000], [3000, 10000], [6000, 10000], [1500, 10000], [6000, 10000]]),
            ExifTag::MAKE                       => new IfdEntry(ExifTag::MAKE, 2, 5, 'Canon'),
            ExifTag::MODEL                      => new IfdEntry(ExifTag::MODEL, 2, 8, 'EOS R6 II'),
            ExifTag::SOFTWARE                   => new IfdEntry(ExifTag::SOFTWARE, 2, 8, 'Firmware1'),
            ExifTag::IMAGE_DESCRIPTION          => new IfdEntry(ExifTag::IMAGE_DESCRIPTION, 2, 16, 'Sunset over Alps'),
            ExifTag::ORIENTATION                => new IfdEntry(ExifTag::ORIENTATION, 3, 1, Orientation::RIGHT_TOP->value),
            ExifTag::ARTIST                     => new IfdEntry(ExifTag::ARTIST, 2, 12, 'Jane Doe'),
        ]);

        $exifIfd = new Ifd([
            ExifTag::EXIF_VERSION              => new IfdEntry(ExifTag::EXIF_VERSION, 7, 4, '0300'),
            ExifTag::FLASHPIX_VERSION          => new IfdEntry(ExifTag::FLASHPIX_VERSION, 7, 4, '0100'),
            ExifTag::PHOTOGRAPHIC_SENSITIVITY  => new IfdEntry(ExifTag::PHOTOGRAPHIC_SENSITIVITY, 3, 1, 400),
            ExifTag::EXPOSURE_TIME             => new IfdEntry(ExifTag::EXPOSURE_TIME, 5, 1, [[1, 125]]),
            ExifTag::F_NUMBER                  => new IfdEntry(ExifTag::F_NUMBER, 5, 1, [[56, 10]]),
            ExifTag::EXPOSURE_PROGRAM          => new IfdEntry(ExifTag::EXPOSURE_PROGRAM, 3, 1, ExposureProgram::APERTURE_PRIORITY->value),
            ExifTag::EXPOSURE_BIAS_VALUE       => new IfdEntry(ExifTag::EXPOSURE_BIAS_VALUE, 10, 1, [[-2, 1]]),
            ExifTag::METERING_MODE             => new IfdEntry(ExifTag::METERING_MODE, 3, 1, MeteringMode::PATTERN->value),
            ExifTag::LIGHT_SOURCE              => new IfdEntry(ExifTag::LIGHT_SOURCE, 3, 1, LightSource::DAYLIGHT->value),
            ExifTag::FLASH                     => new IfdEntry(ExifTag::FLASH, 3, 1, 0x59),
            ExifTag::WHITE_BALANCE             => new IfdEntry(ExifTag::WHITE_BALANCE, 3, 1, WhiteBalance::MANUAL->value),
            ExifTag::BRIGHTNESS_VALUE          => new IfdEntry(ExifTag::BRIGHTNESS_VALUE, 10, 1, [[76, 10]]),
            ExifTag::COLOR_SPACE               => new IfdEntry(ExifTag::COLOR_SPACE, 3, 1, ColorSpace::SRGB->value),
            ExifTag::EXPOSURE_MODE             => new IfdEntry(ExifTag::EXPOSURE_MODE, 3, 1, ExposureMode::MANUAL->value),
            ExifTag::GAIN_CONTROL              => new IfdEntry(ExifTag::GAIN_CONTROL, 3, 1, GainControl::LOW_GAIN_UP->value),
            ExifTag::CONTRAST                  => new IfdEntry(ExifTag::CONTRAST, 3, 1, 1),
            ExifTag::SATURATION                => new IfdEntry(ExifTag::SATURATION, 3, 1, 0),
            ExifTag::SHARPNESS                 => new IfdEntry(ExifTag::SHARPNESS, 3, 1, 2),
            ExifTag::DIGITAL_ZOOM_RATIO        => new IfdEntry(ExifTag::DIGITAL_ZOOM_RATIO, 5, 1, [[1, 1]]),
            ExifTag::FOCAL_LENGTH              => new IfdEntry(ExifTag::FOCAL_LENGTH, 5, 1, [[85, 1]]),
            ExifTag::FOCAL_LENGTH_IN_35MM_FILM => new IfdEntry(ExifTag::FOCAL_LENGTH_IN_35MM_FILM, 3, 1, 85),
            ExifTag::MAX_APERTURE_VALUE        => new IfdEntry(ExifTag::MAX_APERTURE_VALUE, 5, 1, [[1995, 1000]]),
            ExifTag::LENS_INFO                 => new IfdEntry(ExifTag::LENS_INFO, 5, 4, [[35, 1], [40, 10], [150, 1], [56, 10]]),
            ExifTag::LENS_MODEL                => new IfdEntry(ExifTag::LENS_MODEL, 2, 15, 'EF 85mm f/1.4L'),
            ExifTag::LENS_MAKE                 => new IfdEntry(ExifTag::LENS_MAKE, 2, 5, 'Canon'),
            ExifTag::LENS_SERIAL_NUMBER        => new IfdEntry(ExifTag::LENS_SERIAL_NUMBER, 2, 10, '1234ABC'),
            ExifTag::SCENE_CAPTURE_TYPE        => new IfdEntry(ExifTag::SCENE_CAPTURE_TYPE, 3, 1, SceneCaptureType::STANDARD->value),
            ExifTag::SUBJECT_DISTANCE_RANGE    => new IfdEntry(ExifTag::SUBJECT_DISTANCE_RANGE, 3, 1, SubjectDistanceRange::DISTANT->value),
            ExifTag::FILE_SOURCE               => new IfdEntry(ExifTag::FILE_SOURCE, 7, 1, chr(FileSource::DIGITAL_CAMERA->value)),
            ExifTag::SENSING_METHOD            => new IfdEntry(ExifTag::SENSING_METHOD, 3, 1, SensingMethod::ONE_CHIP_COLOR_AREA->value),
            ExifTag::GAMMA                     => new IfdEntry(ExifTag::GAMMA, 5, 1, [[22, 10]]),
        ]);

        $interopIfd = new Ifd([
            ExifTag::INTEROPERABILITY_INDEX => new IfdEntry(ExifTag::INTEROPERABILITY_INDEX, 2, 4, 'R98'),
        ]);

        $exifDocument = new ExifDocument($ifd0, $exifIfd, null, $interopIfd, null);

        $xmpDocument = new XmpDocument([
            '{http://purl.org/dc/elements/1.1/}creator'                                                => ['Jane Doe'],
            '{http://iptc.org/std/Iptc4xmpCore/1.0/xmlns/}CreatorContactInfo/Iptc4xmpCore:CiEmailWork' => 'jane@example.com',
            '{http://ns.adobe.com/tiff/1.0/}Make'                                                      => 'Canon',
            '{http://ns.adobe.com/tiff/1.0/}Model'                                                     => 'EOS R6 II',
            '{http://ns.adobe.com/tiff/1.0/}DocumentName'                                              => 'IMG_5123.CR3',
        ]);

        $quickTime = new QuickTimeMeta([
            QuickTimeMeta::CONTENT_IDENTIFIER_KEY => 'asset-01',
            'com.apple.quicktime.make'            => 'Canon',
            'com.apple.quicktime.model'           => 'EOS R6 II',
            'com.apple.quicktime.software'        => '1.2.3',
        ]);

        $metadata = new Metadata(['primary'], $quickTime, $exifDocument, ['<xmp/>'], $xmpDocument);

        $structured = (new StructuredMetadataBuilder())->build($metadata);

        self::assertSame(['index' => 'R98'], get_object_vars($structured->interop));

        self::assertSame(Compression::JPEG, $structured->tiff->compression);
        self::assertSame(Photometric::YCBCR, $structured->tiff->photometric);
        self::assertSame([2, 2], $structured->tiff->ycbcrSubSampling);
        self::assertSame([0.299, 0.587, 0.114], $structured->tiff->ycbcrCoefficients);
        self::assertSame([0.3127, 0.329], $structured->tiff->whitePoint);
        self::assertSame([0.64, 0.33, 0.3, 0.6, 0.15, 0.6], $structured->tiff->primaryChromaticities);

        self::assertSame('Canon', $structured->camera->make);
        self::assertSame('EOS R6 II', $structured->camera->model);
        self::assertSame('Jane Doe', $structured->author->artist);
        self::assertSame('Firmware1', $structured->camera->firmware);
        self::assertSame(FileSource::DIGITAL_CAMERA, $structured->camera->fileSource);
        self::assertSame(SensingMethod::ONE_CHIP_COLOR_AREA, $structured->camera->sensingMethod);

        self::assertSame('EF 85mm f/1.4L', $structured->lens->lensModel);
        self::assertSame(85.0, $structured->lens->focalLengthMm);
        self::assertSame(85, $structured->lens->focalLengthIn35mm);
        self::assertSame([35.0, 4.0, 150.0, 5.6], $structured->lens->lensInfo);
        self::assertEqualsWithDelta(1.9965, $structured->lens->maxApertureFNumber, 0.001);

        self::assertSame(6720, $structured->image->width);
        self::assertSame(4480, $structured->image->height);
        self::assertSame(Orientation::RIGHT_TOP, $structured->image->orientation);
        self::assertSame(ColorSpace::SRGB, $structured->image->colorSpace);
        self::assertSame('IMG_5123.CR3', $structured->image->documentName);
        self::assertSame('Sunset over Alps', $structured->image->description);

        self::assertSame(400, $structured->exposure->iso);
        self::assertSame(0.008, $structured->exposure->exposureTimeSec);
        self::assertSame(5.6, $structured->exposure->fNumber);
        self::assertSame(-2.0, $structured->exposure->exposureBiasEv);
        self::assertSame(ExposureProgram::APERTURE_PRIORITY, $structured->exposure->program);
        self::assertSame(MeteringMode::PATTERN, $structured->exposure->meteringMode);
        self::assertSame(WhiteBalance::MANUAL, $structured->exposure->whiteBalance);
        self::assertSame(GainControl::LOW_GAIN_UP, $structured->exposure->gainControl);
        self::assertSame(1, $structured->exposure->contrast);
        self::assertSame(0, $structured->exposure->saturation);
        self::assertSame(2, $structured->exposure->sharpness);

        $flash = $structured->exposure->flash;
        self::assertNotNull($flash);
        self::assertTrue($flash->fired);

        self::assertSame(SceneCaptureType::STANDARD, $structured->scene->type);
        self::assertSame(SubjectDistanceRange::DISTANT, $structured->scene->subjectDistanceRange);

        self::assertSame('3.00', $structured->standards->exifVersion);
        self::assertSame('0100', $structured->standards->flashpixVersion);
    }

    /**
     * Ensures HEIF metadata including composite images is mapped correctly.
     */
    #[Test]
    public function buildsStructuredAggregateForHeif(): void
    {
        $ifd0 = new Ifd([
            ExifTag::IMAGE_WIDTH  => new IfdEntry(ExifTag::IMAGE_WIDTH, 4, 1, 4032),
            ExifTag::IMAGE_HEIGHT => new IfdEntry(ExifTag::IMAGE_HEIGHT, 4, 1, 3024),
            ExifTag::MAKE         => new IfdEntry(ExifTag::MAKE, 2, 5, 'Apple'),
            ExifTag::MODEL        => new IfdEntry(ExifTag::MODEL, 2, 9, 'iPhone 15'),
            ExifTag::ORIENTATION  => new IfdEntry(ExifTag::ORIENTATION, 3, 1, Orientation::TOP_LEFT->value),
        ]);

        $exifIfd = new Ifd([
            ExifTag::EXIF_VERSION                   => new IfdEntry(ExifTag::EXIF_VERSION, 7, 4, '0300'),
            ExifTag::PHOTOGRAPHIC_SENSITIVITY       => new IfdEntry(ExifTag::PHOTOGRAPHIC_SENSITIVITY, 3, 1, 125),
            ExifTag::EXPOSURE_TIME                  => new IfdEntry(ExifTag::EXPOSURE_TIME, 5, 1, [[1, 120]]),
            ExifTag::F_NUMBER                       => new IfdEntry(ExifTag::F_NUMBER, 5, 1, [[19, 10]]),
            ExifTag::COMPOSITE_IMAGE                => new IfdEntry(ExifTag::COMPOSITE_IMAGE, 3, 1, CompositeImage::GENERAL_COMPOSITE->value),
            ExifTag::COMPOSITE_IMAGE_COUNT          => new IfdEntry(ExifTag::COMPOSITE_IMAGE_COUNT, 3, 2, new ExifNumericList([9, 4])),
            ExifTag::COMPOSITE_IMAGE_EXPOSURE_TIMES => new IfdEntry(ExifTag::COMPOSITE_IMAGE_EXPOSURE_TIMES, 5, 4, [[1, 120], [1, 60], [1, 30], [1, 15]]),
            ExifTag::DATETIME_ORIGINAL              => new IfdEntry(ExifTag::DATETIME_ORIGINAL, 2, 19, '2024:02:01 20:45:00'),
            ExifTag::OFFSET_TIME_ORIGINAL           => new IfdEntry(ExifTag::OFFSET_TIME_ORIGINAL, 2, 6, '+01:00'),
        ]);

        $exifDocument = new ExifDocument($ifd0, $exifIfd, null, null, null);

        $xmpDocument = new XmpDocument([
            '{http://ns.adobe.com/xap/1.0/}CreateDate' => '2024-02-01T20:45:00+01:00',
        ]);

        $quickTime = new QuickTimeMeta([
            QuickTimeMeta::CONTENT_IDENTIFIER_KEY => 'burst-01',
            'HDRImageType'                        => 'HDR',
            'NightMode'                           => 1,
            'DepthData'                           => 'depth-asset',
            'com.apple.quicktime.software'        => '17.3',
            'CreationDate'                        => '2024-02-01T19:45:00Z',
        ]);

        $metadata = new Metadata(['primary'], $quickTime, $exifDocument, ['<xmp/>'], $xmpDocument);

        $structured = (new StructuredMetadataBuilder())->build($metadata);

        self::assertSame(CompositeImage::GENERAL_COMPOSITE, $structured->composite->type);
        self::assertSame([9, 4], $structured->composite->counts);
        foreach ([0.008333333333333333, 0.016666666666666666, 0.03333333333333333, 0.06666666666666667] as $idx => $expected) {
            self::assertEqualsWithDelta($expected, $structured->composite->exposureTimesTotal[$idx], 1e-12);
        }

        self::assertSame('17.3', $structured->device->software);

        self::assertTrue($structured->scene->hdrScene);
        self::assertTrue($structured->scene->nightMode);

        self::assertSame('OffsetTimeOriginal', $structured->temporal->tzSource);
        self::assertInstanceOf(DateTimeImmutable::class, $structured->temporal->original);
        self::assertSame('+01:00', $structured->temporal->original?->format('P'));
    }

    /**
     * Ensures missing metadata still results in instantiated value objects.
     */
    #[Test]
    public function handlesMissingMetadata(): void
    {
        $metadata = new Metadata([], null, null, []);

        $structured = (new StructuredMetadataBuilder())->build($metadata);

        self::assertSame(['index' => null], get_object_vars($structured->interop));
        self::assertNull($structured->tiff->compression);
        self::assertNull($structured->camera->make);
    }

    /**
     * Verifies the ISO fallback uses the Standard Output Sensitivity tag when available.
     */
    #[Test]
    public function usesStandardOutputSensitivityFallback(): void
    {
        $ifd0    = new Ifd([]);
        $exifIfd = new Ifd([
            ExifTag::SENSITIVITY_TYPE            => new IfdEntry(ExifTag::SENSITIVITY_TYPE, 3, 1, 1),
            ExifTag::STANDARD_OUTPUT_SENSITIVITY => new IfdEntry(ExifTag::STANDARD_OUTPUT_SENSITIVITY, 3, 1, 640),
        ]);

        $exifDocument = new ExifDocument($ifd0, $exifIfd, null, null, null);
        $metadata     = new Metadata(['primary'], null, $exifDocument);

        $structured = (new StructuredMetadataBuilder())->build($metadata);

        self::assertSame(640, $structured->exposure->iso);
    }
}
