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
use MagicSunday\ImageMeta\Model\Exif\ExifRational;
use MagicSunday\ImageMeta\Model\Exif\ExifTag;
use MagicSunday\ImageMeta\Model\Exif\Ifd;
use MagicSunday\ImageMeta\Model\Exif\IfdEntry;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Model\QuickTimeMeta;
use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;
use MagicSunday\ImageMeta\Parse\Tiff\TiffExifReader;
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
            ExifTag::IMAGE_WIDTH                    => new IfdEntry(ExifTag::IMAGE_WIDTH, 4, 1, 6720),
            ExifTag::IMAGE_HEIGHT                   => new IfdEntry(ExifTag::IMAGE_HEIGHT, 4, 1, 4480),
            ExifTag::BITS_PER_SAMPLE                => new IfdEntry(ExifTag::BITS_PER_SAMPLE, 3, 3, new ExifNumericList([14, 14, 14])),
            ExifTag::COMPRESSION                    => new IfdEntry(ExifTag::COMPRESSION, 3, 1, Compression::JPEG->value),
            ExifTag::PHOTOMETRIC_INTERPRETATION     => new IfdEntry(ExifTag::PHOTOMETRIC_INTERPRETATION, 3, 1, Photometric::YCBCR->value),
            ExifTag::PLANAR_CONFIGURATION           => new IfdEntry(ExifTag::PLANAR_CONFIGURATION, 3, 1, PlanarConfiguration::CHUNKY->value),
            ExifTag::RESOLUTION_UNIT                => new IfdEntry(ExifTag::RESOLUTION_UNIT, 3, 1, ResolutionUnit::INCHES->value),
            ExifTag::X_RESOLUTION                   => new IfdEntry(ExifTag::X_RESOLUTION, 5, 1, [[300, 1]]),
            ExifTag::Y_RESOLUTION                   => new IfdEntry(ExifTag::Y_RESOLUTION, 5, 1, [[300, 1]]),
            ExifTag::YCBCR_POSITIONING              => new IfdEntry(ExifTag::YCBCR_POSITIONING, 3, 1, YCbCrPositioning::CENTERED->value),
            ExifTag::YCBCR_SUB_SAMPLING             => new IfdEntry(ExifTag::YCBCR_SUB_SAMPLING, 3, 2, new ExifNumericList([2, 2])),
            ExifTag::YCBCR_COEFFICIENTS             => new IfdEntry(ExifTag::YCBCR_COEFFICIENTS, 5, 3, [[299, 1000], [587, 1000], [114, 1000]]),
            ExifTag::WHITE_POINT                    => new IfdEntry(ExifTag::WHITE_POINT, 5, 2, [[3127, 10000], [3290, 10000]]),
            ExifTag::PRIMARY_CHROMATICITIES         => new IfdEntry(ExifTag::PRIMARY_CHROMATICITIES, 5, 6, [[6400, 10000], [3300, 10000], [3000, 10000], [6000, 10000], [1500, 10000], [6000, 10000]]),
            ExifTag::STRIP_OFFSETS                  => new IfdEntry(ExifTag::STRIP_OFFSETS, 4, 3, new ExifNumericList([512, 1024, 1536])),
            ExifTag::STRIP_BYTE_COUNTS              => new IfdEntry(ExifTag::STRIP_BYTE_COUNTS, 4, 3, new ExifNumericList([2048, 2048, 1024])),
            ExifTag::TRANSFER_FUNCTION              => new IfdEntry(ExifTag::TRANSFER_FUNCTION, 3, 3, new ExifNumericList([0, 32768, 65535])),
            ExifTag::JPEG_INTERCHANGE_FORMAT        => new IfdEntry(ExifTag::JPEG_INTERCHANGE_FORMAT, 4, 1, 24576),
            ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH => new IfdEntry(ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH, 4, 1, 8192),
            ExifTag::REFERENCE_BLACK_WHITE          => new IfdEntry(ExifTag::REFERENCE_BLACK_WHITE, 5, 6, [[0, 1], [255, 1], [0, 1], [255, 1], [0, 1], [255, 1]]),
            ExifTag::COPYRIGHT                      => new IfdEntry(ExifTag::COPYRIGHT, 2, 13, 'Jane Doe 2024'),
            ExifTag::MAKE                           => new IfdEntry(ExifTag::MAKE, 2, 5, 'Canon'),
            ExifTag::MODEL                          => new IfdEntry(ExifTag::MODEL, 2, 8, 'EOS R6 II'),
            ExifTag::SOFTWARE                       => new IfdEntry(ExifTag::SOFTWARE, 2, 8, 'Firmware1'),
            ExifTag::IMAGE_DESCRIPTION              => new IfdEntry(ExifTag::IMAGE_DESCRIPTION, 2, 16, 'Sunset over Alps'),
            ExifTag::ORIENTATION                    => new IfdEntry(ExifTag::ORIENTATION, 3, 1, Orientation::RIGHT_TOP->value),
            ExifTag::ARTIST                         => new IfdEntry(ExifTag::ARTIST, 2, 12, 'Jane Doe'),
        ]);

        $exifIfd = new Ifd([
            ExifTag::IMAGE_TITLE                 => new IfdEntry(ExifTag::IMAGE_TITLE, 2, 12, 'Sunset Title'),
            ExifTag::PHOTOGRAPHER                => new IfdEntry(ExifTag::PHOTOGRAPHER, 2, 22, 'Jane D. Photographer'),
            ExifTag::IMAGE_EDITOR                => new IfdEntry(ExifTag::IMAGE_EDITOR, 2, 12, 'John Editor'),
            ExifTag::EXIF_VERSION                => new IfdEntry(ExifTag::EXIF_VERSION, 7, 4, '0300'),
            ExifTag::FLASHPIX_VERSION            => new IfdEntry(ExifTag::FLASHPIX_VERSION, 7, 4, '0100'),
            ExifTag::PHOTOGRAPHIC_SENSITIVITY    => new IfdEntry(ExifTag::PHOTOGRAPHIC_SENSITIVITY, 3, 1, 400),
            ExifTag::ISO_SPEED_LATITUDE_YYY      => new IfdEntry(ExifTag::ISO_SPEED_LATITUDE_YYY, 3, 1, 320),
            ExifTag::ISO_SPEED_LATITUDE_ZZZ      => new IfdEntry(ExifTag::ISO_SPEED_LATITUDE_ZZZ, 3, 1, 540),
            ExifTag::EXPOSURE_TIME               => new IfdEntry(ExifTag::EXPOSURE_TIME, 5, 1, [[1, 125]]),
            ExifTag::F_NUMBER                    => new IfdEntry(ExifTag::F_NUMBER, 5, 1, [[56, 10]]),
            ExifTag::SHUTTER_SPEED_VALUE         => new IfdEntry(ExifTag::SHUTTER_SPEED_VALUE, 10, 1, new ExifRational(697, 100)),
            ExifTag::APERTURE_VALUE              => new IfdEntry(ExifTag::APERTURE_VALUE, 5, 1, new ExifRational(497, 100)),
            ExifTag::EXPOSURE_PROGRAM            => new IfdEntry(ExifTag::EXPOSURE_PROGRAM, 3, 1, ExposureProgram::APERTURE_PRIORITY->value),
            ExifTag::IMAGE_NUMBER                => new IfdEntry(ExifTag::IMAGE_NUMBER, 4, 1, 128),
            ExifTag::SECURITY_CLASSIFICATION     => new IfdEntry(ExifTag::SECURITY_CLASSIFICATION, 2, 1, 'Restricted'),
            ExifTag::IMAGE_HISTORY               => new IfdEntry(ExifTag::IMAGE_HISTORY, 2, 1, 'Developed in Raw Studio'),
            ExifTag::INTERLACE                   => new IfdEntry(ExifTag::INTERLACE, 3, 1, 1),
            ExifTag::EXPOSURE_BIAS_VALUE         => new IfdEntry(ExifTag::EXPOSURE_BIAS_VALUE, 10, 1, [[-2, 1]]),
            ExifTag::METERING_MODE               => new IfdEntry(ExifTag::METERING_MODE, 3, 1, MeteringMode::PATTERN->value),
            ExifTag::LIGHT_SOURCE                => new IfdEntry(ExifTag::LIGHT_SOURCE, 3, 1, LightSource::DAYLIGHT->value),
            ExifTag::FLASH                       => new IfdEntry(ExifTag::FLASH, 3, 1, 0x59),
            ExifTag::WHITE_BALANCE               => new IfdEntry(ExifTag::WHITE_BALANCE, 3, 1, WhiteBalance::MANUAL->value),
            ExifTag::BRIGHTNESS_VALUE            => new IfdEntry(ExifTag::BRIGHTNESS_VALUE, 10, 1, [[76, 10]]),
            ExifTag::COLOR_SPACE                 => new IfdEntry(ExifTag::COLOR_SPACE, 3, 1, ColorSpace::SRGB->value),
            ExifTag::COMPONENTS_CONFIGURATION    => new IfdEntry(ExifTag::COMPONENTS_CONFIGURATION, 7, 4, new ExifNumericList([1, 2, 3, 0])),
            ExifTag::COMPRESSED_BITS_PER_PIXEL   => new IfdEntry(ExifTag::COMPRESSED_BITS_PER_PIXEL, 5, 1, [[45, 10]]),
            ExifTag::EXPOSURE_MODE               => new IfdEntry(ExifTag::EXPOSURE_MODE, 3, 1, ExposureMode::MANUAL->value),
            ExifTag::GAIN_CONTROL                => new IfdEntry(ExifTag::GAIN_CONTROL, 3, 1, GainControl::LOW_GAIN_UP->value),
            ExifTag::CONTRAST                    => new IfdEntry(ExifTag::CONTRAST, 3, 1, 1),
            ExifTag::SATURATION                  => new IfdEntry(ExifTag::SATURATION, 3, 1, 0),
            ExifTag::SHARPNESS                   => new IfdEntry(ExifTag::SHARPNESS, 3, 1, 2),
            ExifTag::DIGITAL_ZOOM_RATIO          => new IfdEntry(ExifTag::DIGITAL_ZOOM_RATIO, 5, 1, [[1, 1]]),
            ExifTag::FOCAL_LENGTH                => new IfdEntry(ExifTag::FOCAL_LENGTH, 5, 1, [[85, 1]]),
            ExifTag::FOCAL_LENGTH_IN_35MM_FILM   => new IfdEntry(ExifTag::FOCAL_LENGTH_IN_35MM_FILM, 3, 1, 85),
            ExifTag::MAX_APERTURE_VALUE          => new IfdEntry(ExifTag::MAX_APERTURE_VALUE, 5, 1, [[1995, 1000]]),
            ExifTag::LENS_SPECIFICATION          => new IfdEntry(ExifTag::LENS_SPECIFICATION, 5, 4, [[35, 1], [40, 10], [150, 1], [56, 10]]),
            ExifTag::LENS_MODEL                  => new IfdEntry(ExifTag::LENS_MODEL, 2, 15, 'EF 85mm f/1.4L'),
            ExifTag::LENS_MAKE                   => new IfdEntry(ExifTag::LENS_MAKE, 2, 5, 'Canon'),
            ExifTag::LENS_SERIAL_NUMBER          => new IfdEntry(ExifTag::LENS_SERIAL_NUMBER, 2, 10, '1234ABC'),
            ExifTag::SPECTRAL_SENSITIVITY        => new IfdEntry(ExifTag::SPECTRAL_SENSITIVITY, 2, 16, 'Standard Spectral'),
            ExifTag::OECF                        => new IfdEntry(ExifTag::OECF, 7, 12, 'OECF-DATA-01'),
            ExifTag::USER_COMMENT                => new IfdEntry(ExifTag::USER_COMMENT, 7, 28, "ASCII\0\0\0Shot with ND filter\0"),
            ExifTag::SUB_SEC_TIME                => new IfdEntry(ExifTag::SUB_SEC_TIME, 2, 3, '321'),
            ExifTag::SUB_SEC_TIME_ORIGINAL       => new IfdEntry(ExifTag::SUB_SEC_TIME_ORIGINAL, 2, 3, '123'),
            ExifTag::SUB_SEC_TIME_DIGITIZED      => new IfdEntry(ExifTag::SUB_SEC_TIME_DIGITIZED, 2, 3, '456'),
            ExifTag::OFFSET_TIME_ORIGINAL        => new IfdEntry(ExifTag::OFFSET_TIME_ORIGINAL, 2, 6, '+01:30'),
            ExifTag::OFFSET_TIME_DIGITIZED       => new IfdEntry(ExifTag::OFFSET_TIME_DIGITIZED, 2, 6, '+01:30'),
            ExifTag::OFFSET_TIME                 => new IfdEntry(ExifTag::OFFSET_TIME, 2, 6, '+01:30'),
            ExifTag::TIME_ZONE_OFFSET            => new IfdEntry(ExifTag::TIME_ZONE_OFFSET, 8, 2, new ExifNumericList([-90, -60])),
            ExifTag::SELF_TIMER_MODE             => new IfdEntry(ExifTag::SELF_TIMER_MODE, 3, 1, 10),
            ExifTag::TEMPERATURE                 => new IfdEntry(ExifTag::TEMPERATURE, 10, 1, new ExifRational(215, 10)),
            ExifTag::HUMIDITY                    => new IfdEntry(ExifTag::HUMIDITY, 10, 1, new ExifRational(600, 10)),
            ExifTag::PRESSURE                    => new IfdEntry(ExifTag::PRESSURE, 10, 1, new ExifRational(101325, 100)),
            ExifTag::WATER_DEPTH                 => new IfdEntry(ExifTag::WATER_DEPTH, 10, 1, new ExifRational(150, 10)),
            ExifTag::ACCELERATION                => new IfdEntry(ExifTag::ACCELERATION, 10, 1, new ExifRational(98, 10)),
            ExifTag::CAMERA_ELEVATION_ANGLE      => new IfdEntry(ExifTag::CAMERA_ELEVATION_ANGLE, 10, 1, new ExifRational(150, 10)),
            ExifTag::RELATED_SOUND_FILE          => new IfdEntry(ExifTag::RELATED_SOUND_FILE, 2, 10, 'sound.wav'),
            ExifTag::FLASH_ENERGY                => new IfdEntry(ExifTag::FLASH_ENERGY, 5, 1, new ExifRational(250, 10)),
            ExifTag::SPATIAL_FREQUENCY_RESPONSE  => new IfdEntry(ExifTag::SPATIAL_FREQUENCY_RESPONSE, 7, 12, 'SFR-Curve-01'),
            ExifTag::FOCAL_PLANE_X_RESOLUTION    => new IfdEntry(ExifTag::FOCAL_PLANE_X_RESOLUTION, 5, 1, new ExifRational(4321, 100)),
            ExifTag::FOCAL_PLANE_Y_RESOLUTION    => new IfdEntry(ExifTag::FOCAL_PLANE_Y_RESOLUTION, 5, 1, new ExifRational(4300, 100)),
            ExifTag::FOCAL_PLANE_RESOLUTION_UNIT => new IfdEntry(ExifTag::FOCAL_PLANE_RESOLUTION_UNIT, 3, 1, ResolutionUnit::CENTIMETER->value),
            ExifTag::TIFF_EP_STANDARD_ID         => new IfdEntry(ExifTag::TIFF_EP_STANDARD_ID, 1, 4, new ExifNumericList([1, 0, 0, 0])),
            ExifTag::SUBJECT_LOCATION            => new IfdEntry(ExifTag::SUBJECT_LOCATION, 3, 2, new ExifNumericList([1600, 1700])),
            ExifTag::EXPOSURE_INDEX              => new IfdEntry(ExifTag::EXPOSURE_INDEX, 5, 1, new ExifRational(400, 1)),
            ExifTag::SCENE_CAPTURE_TYPE          => new IfdEntry(ExifTag::SCENE_CAPTURE_TYPE, 3, 1, SceneCaptureType::STANDARD->value),
            ExifTag::SCENE_TYPE                  => new IfdEntry(ExifTag::SCENE_TYPE, 7, 1, chr(1)),
            ExifTag::SUBJECT_DISTANCE_RANGE      => new IfdEntry(ExifTag::SUBJECT_DISTANCE_RANGE, 3, 1, SubjectDistanceRange::DISTANT->value),
            ExifTag::FILE_SOURCE                 => new IfdEntry(ExifTag::FILE_SOURCE, 7, 1, chr(FileSource::DIGITAL_CAMERA->value)),
            ExifTag::SENSING_METHOD              => new IfdEntry(ExifTag::SENSING_METHOD, 3, 1, SensingMethod::ONE_CHIP_COLOR_AREA->value),
            ExifTag::GAMMA                       => new IfdEntry(ExifTag::GAMMA, 5, 1, [[22, 10]]),
            ExifTag::CFA_PATTERN                 => new IfdEntry(ExifTag::CFA_PATTERN, 7, 4, "\x02\x01\x01\x00"),
            ExifTag::CUSTOM_RENDERED             => new IfdEntry(ExifTag::CUSTOM_RENDERED, 3, 1, 1),
            ExifTag::DEVICE_SETTING_DESCRIPTION  => new IfdEntry(ExifTag::DEVICE_SETTING_DESCRIPTION, 7, 12, 'Profile:Portrait'),
            ExifTag::CAMERA_FIRMWARE             => new IfdEntry(ExifTag::CAMERA_FIRMWARE, 2, 6, '1.2.3'),
            ExifTag::RAW_DEVELOPING_SOFTWARE     => new IfdEntry(ExifTag::RAW_DEVELOPING_SOFTWARE, 2, 11, 'Raw Studio'),
            ExifTag::IMAGE_EDITING_SOFTWARE      => new IfdEntry(ExifTag::IMAGE_EDITING_SOFTWARE, 2, 13, 'Image Studio'),
            ExifTag::METADATA_EDITING_SOFTWARE   => new IfdEntry(ExifTag::METADATA_EDITING_SOFTWARE, 2, 15, 'Metadata Studio'),
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
        self::assertSame([512, 1024, 1536], $structured->tiff->stripOffsets);
        self::assertSame([2048, 2048, 1024], $structured->tiff->stripByteCounts);
        self::assertSame([0, 32768, 65535], $structured->tiff->transferFunction);
        self::assertSame(24576, $structured->tiff->jpegInterchangeFormat);
        self::assertSame(8192, $structured->tiff->jpegInterchangeFormatLength);
        self::assertSame([0.0, 255.0, 0.0, 255.0, 0.0, 255.0], $structured->tiff->referenceBlackWhite);
        self::assertSame('Jane Doe 2024', $structured->tiff->copyright);

        self::assertSame('Canon', $structured->camera->make);
        self::assertSame('EOS R6 II', $structured->camera->model);
        self::assertSame('Jane Doe', $structured->author->artist);
        self::assertSame('Restricted', $structured->rights->securityClassification);
        self::assertSame('1.2.3', $structured->camera->firmware);
        self::assertSame(FileSource::DIGITAL_CAMERA, $structured->camera->fileSource);
        self::assertSame(SensingMethod::ONE_CHIP_COLOR_AREA, $structured->camera->sensingMethod);

        self::assertSame('EF 85mm f/1.4L', $structured->lens->lensModel);
        self::assertSame(85.0, $structured->lens->focalLengthMm);
        self::assertSame(85, $structured->lens->focalLengthIn35mm);
        self::assertSame([35.0, 4.0, 150.0, 5.6], $structured->lens->lensSpecification);
        self::assertEqualsWithDelta(1.9965, $structured->lens->maxApertureFNumber, 0.001);

        self::assertSame(6720, $structured->image->width);
        self::assertSame(4480, $structured->image->height);
        self::assertSame(Orientation::RIGHT_TOP, $structured->image->orientation);
        self::assertSame(ColorSpace::SRGB, $structured->image->colorSpace);
        self::assertSame(128, $structured->image->imageNumber);
        self::assertNull($structured->image->documentName);
        self::assertSame('Sunset over Alps', $structured->image->description);
        self::assertSame('Sunset Title', $structured->image->title);
        self::assertSame([1, 2, 3, 0], $structured->image->componentsConfiguration);
        self::assertSame(4.5, $structured->image->compressedBitsPerPixel);
        self::assertSame(1, $structured->image->interlace);
        self::assertSame('Shot with ND filter', $structured->image->userComment);
        self::assertSame('Developed in Raw Studio', $structured->integrity->imageHistory);

        self::assertSame(400, $structured->exposure->iso);
        self::assertSame(0.008, $structured->exposure->exposureTimeSec);
        self::assertSame(5.6, $structured->exposure->fNumber);
        self::assertSame(-2.0, $structured->exposure->exposureBiasEv);
        self::assertEqualsWithDelta(6.97, $structured->exposure->shutterSpeedEv, 0.01);
        self::assertEqualsWithDelta(4.97, $structured->exposure->apertureEv, 0.01);
        self::assertSame(ExposureProgram::APERTURE_PRIORITY, $structured->exposure->program);
        self::assertSame(MeteringMode::PATTERN, $structured->exposure->meteringMode);
        self::assertSame(WhiteBalance::MANUAL, $structured->exposure->whiteBalance);
        self::assertSame(GainControl::LOW_GAIN_UP, $structured->exposure->gainControl);
        self::assertSame(1, $structured->exposure->contrast);
        self::assertSame(0, $structured->exposure->saturation);
        self::assertSame(2, $structured->exposure->sharpness);
        self::assertSame(320, $structured->exposure->isoLatitudeYyy);
        self::assertSame(540, $structured->exposure->isoLatitudeZzz);
        self::assertSame(400.0, $structured->exposure->exposureIndex);
        self::assertSame(25.0, $structured->exposure->flashEnergy);

        $flash = $structured->exposure->flash;
        self::assertNotNull($flash);
        self::assertTrue($flash->fired);

        self::assertSame(SceneCaptureType::STANDARD, $structured->scene->type);
        self::assertSame(1, $structured->scene->sceneType);
        self::assertSame(SubjectDistanceRange::DISTANT, $structured->scene->subjectDistanceRange);

        self::assertSame('3.00', $structured->standards->exifVersion);
        self::assertSame('3.0', $structured->standards->profile);
        self::assertSame('0100', $structured->standards->flashpixVersion);
        self::assertSame([1, 0, 0, 0], $structured->standards->tiffEpStandardId);

        self::assertEqualsWithDelta(1.9965, $structured->lens->maxApertureFNumber, 0.001);

        self::assertEqualsWithDelta(21.5, $structured->capture->temperatureC, 0.001);
        self::assertEqualsWithDelta(60.0, $structured->capture->humidityPercent, 0.001);
        self::assertEqualsWithDelta(1013.25, $structured->capture->pressureHPa, 0.001);
        self::assertEqualsWithDelta(15.0, $structured->capture->waterDepthM, 0.001);
        self::assertEqualsWithDelta(9.8, $structured->capture->accelerationMs2, 0.001);
        self::assertEqualsWithDelta(15.0, $structured->capture->cameraElevationAngleDeg, 0.001);
        self::assertSame(10, $structured->capture->selfTimerModeSeconds);

        self::assertSame('sound.wav', $structured->related->relatedSoundFile);

        self::assertSame('Standard Spectral', $structured->sensor->spectralSensitivity);
        self::assertSame('OECF-DATA-01', $structured->sensor->oecf);
        self::assertSame([2, 1, 1, 0], $structured->sensor->cfaPattern);
        self::assertEqualsWithDelta(43.21, $structured->sensor->focalPlaneXResolution, 0.001);
        self::assertEqualsWithDelta(43.0, $structured->sensor->focalPlaneYResolution, 0.001);
        self::assertSame(ResolutionUnit::CENTIMETER, $structured->sensor->focalPlaneResolutionUnit);

        self::assertSame('Profile:Portrait', $structured->processing->deviceSettingDescription);
        self::assertSame(1, $structured->processing->customRendered);

        self::assertSame('Jane D. Photographer', $structured->author->photographer);
        self::assertSame('John Editor', $structured->author->imageEditor);

        self::assertSame('Raw Studio', $structured->device->rawDevelopingSoftware);
        self::assertSame('Image Studio', $structured->device->imageEditingSoftware);
        self::assertSame('Metadata Studio', $structured->device->metadataEditingSoftware);

        self::assertSame('321', $structured->temporal->subSecTime);
        self::assertSame('123', $structured->temporal->subSecTimeOriginal);
        self::assertSame('456', $structured->temporal->subSecTimeDigitized);
        self::assertSame('+01:30', $structured->temporal->offsetTime);
        self::assertSame('+01:30', $structured->temporal->offsetTimeOriginal);
        self::assertSame('+01:30', $structured->temporal->offsetTimeDigitized);
        self::assertSame([-90, -60], $structured->temporal->timeZoneOffsetMinutes);
        self::assertSame('OffsetTimeOriginal', $structured->temporal->tzSource);
    }

    /**
     * Ensures printable UNDEFINED EXIF values parsed from a TIFF blob map to structured standards metadata.
     */
    #[Test]
    public function buildsStandardsFromPrintableUndefinedBlob(): void
    {
        $blob     = $this->buildClassicVersionBlob();
        $document = (new TiffExifReader())->parseFromBlob($blob);

        $metadata   = new Metadata([$blob], null, $document);
        $structured = (new StructuredMetadataBuilder())->build($metadata);
        $standards  = $structured->standards;

        self::assertSame('2.32', $standards->exifVersion);
        self::assertSame('0100', $standards->flashpixVersion);
        self::assertSame('2.32', $standards->profile);
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
            ExifTag::EXIF_VERSION                             => new IfdEntry(ExifTag::EXIF_VERSION, 7, 4, '0300'),
            ExifTag::PHOTOGRAPHIC_SENSITIVITY                 => new IfdEntry(ExifTag::PHOTOGRAPHIC_SENSITIVITY, 3, 1, 125),
            ExifTag::EXPOSURE_TIME                            => new IfdEntry(ExifTag::EXPOSURE_TIME, 5, 1, [[1, 120]]),
            ExifTag::F_NUMBER                                 => new IfdEntry(ExifTag::F_NUMBER, 5, 1, [[19, 10]]),
            ExifTag::COMPOSITE_IMAGE                          => new IfdEntry(ExifTag::COMPOSITE_IMAGE, 3, 1, CompositeImage::GENERAL_COMPOSITE->value),
            ExifTag::SOURCE_IMAGE_NUMBER_OF_COMPOSITE_IMAGE   => new IfdEntry(ExifTag::SOURCE_IMAGE_NUMBER_OF_COMPOSITE_IMAGE, 3, 2, new ExifNumericList([9, 4])),
            ExifTag::SOURCE_EXPOSURE_TIMES_OF_COMPOSITE_IMAGE => new IfdEntry(ExifTag::SOURCE_EXPOSURE_TIMES_OF_COMPOSITE_IMAGE, 5, 4, [[1, 120], [1, 60], [1, 30], [1, 15]]),
            ExifTag::DATETIME_ORIGINAL                        => new IfdEntry(ExifTag::DATETIME_ORIGINAL, 2, 19, '2024:02:01 20:45:00'),
            ExifTag::OFFSET_TIME_ORIGINAL                     => new IfdEntry(ExifTag::OFFSET_TIME_ORIGINAL, 2, 6, '+01:00'),
            ExifTag::OFFSET_TIME                              => new IfdEntry(ExifTag::OFFSET_TIME, 2, 6, '+01:30'),
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

        self::assertSame('3.0', $structured->standards->profile);

        self::assertSame('OffsetTimeOriginal', $structured->temporal->tzSource);
        self::assertInstanceOf(DateTimeImmutable::class, $structured->temporal->original);
        self::assertSame('+01:00', $structured->temporal->original?->format('P'));
    }

    /**
     * Ensures EXIF 2.2 markers derive the legacy profile identifier.
     */
    #[Test]
    public function derivesProfileForExif22(): void
    {
        $ifd0    = new Ifd([]);
        $exifIfd = new Ifd([
            ExifTag::EXIF_VERSION => new IfdEntry(ExifTag::EXIF_VERSION, 7, 4, '0220'),
        ]);

        $exifDocument = new ExifDocument($ifd0, $exifIfd, null, null, null);
        $metadata     = new Metadata(['primary'], null, $exifDocument);

        $structured = (new StructuredMetadataBuilder())->build($metadata);

        self::assertSame('2.20', $structured->standards->exifVersion);
        self::assertSame('2.2', $structured->standards->profile);
    }

    /**
     * Ensures EXIF 2.3 markers derive the enhanced profile identifier.
     */
    #[Test]
    public function derivesProfileForExif23(): void
    {
        $ifd0    = new Ifd([]);
        $exifIfd = new Ifd([
            ExifTag::EXIF_VERSION => new IfdEntry(ExifTag::EXIF_VERSION, 7, 4, '0231'),
        ]);

        $exifDocument = new ExifDocument($ifd0, $exifIfd, null, null, null);
        $metadata     = new Metadata(['primary'], null, $exifDocument);

        $structured = (new StructuredMetadataBuilder())->build($metadata);

        self::assertSame('2.31', $structured->standards->exifVersion);
        self::assertSame('2.3', $structured->standards->profile);
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
        self::assertSame('2.2', $structured->standards->profile);
    }

    /**
     * Ensures non-EXIF metadata no longer populates camera, lens or image fields.
     */
    #[Test]
    public function ignoresNonExifFallbacks(): void
    {
        $ifd0    = new Ifd([]);
        $exifIfd = new Ifd([]);

        $exifDocument = new ExifDocument($ifd0, $exifIfd, null, null, null);

        $xmpDocument = new XmpDocument([
            '{http://ns.adobe.com/tiff/1.0/}Make'          => 'XMP Make',
            '{http://ns.adobe.com/tiff/1.0/}DocumentName'  => 'Fallback Document',
            '{http://ns.adobe.com/exif/1.0/aux/}LensModel' => 'Fallback Lens',
        ]);

        $quickTime = new QuickTimeMeta([
            'com.apple.quicktime.make'  => 'QuickTime Make',
            'com.apple.quicktime.model' => 'QuickTime Model',
        ]);

        $metadata = new Metadata(['primary'], $quickTime, $exifDocument, ['<xmp/>'], $xmpDocument);

        $structured = (new StructuredMetadataBuilder())->build($metadata);

        self::assertNull($structured->camera->make);
        self::assertNull($structured->camera->model);
        self::assertNull($structured->lens->lensModel);
        self::assertNull($structured->image->documentName);
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

    /**
     * Ensures the TimeZoneOffset tag provides a timezone when offset strings are absent.
     */
    #[Test]
    public function usesTimeZoneOffsetWhenOffsetTagsMissing(): void
    {
        $ifd0 = new Ifd([
            ExifTag::DATETIME_ORIGINAL => new IfdEntry(ExifTag::DATETIME_ORIGINAL, 2, 1, '2024:05:02 12:00:00'),
        ]);

        $exifIfd = new Ifd([
            ExifTag::DATETIME_ORIGINAL => new IfdEntry(ExifTag::DATETIME_ORIGINAL, 2, 1, '2024:05:02 12:00:00'),
            ExifTag::TIME_ZONE_OFFSET  => new IfdEntry(ExifTag::TIME_ZONE_OFFSET, 8, 1, new ExifNumericList([-120])),
        ]);

        $exifDocument = new ExifDocument($ifd0, $exifIfd, null, null, null);
        $metadata     = new Metadata(['primary'], null, $exifDocument);

        $structured = (new StructuredMetadataBuilder())->build($metadata);

        self::assertSame('TimeZoneOffset', $structured->temporal->tzSource);
        self::assertSame('-02:00', $structured->temporal->original?->format('P'));
    }

    /**
     * Ensures DateTimeOriginal does not leak the PHP default timezone when offset metadata is missing.
     */
    #[Test]
    public function leavesTimezoneUnsetWhenOffsetTagsMissing(): void
    {
        $ifd0 = new Ifd([
            ExifTag::DATETIME_ORIGINAL => new IfdEntry(ExifTag::DATETIME_ORIGINAL, 2, 1, '2024:05:02 12:34:56'),
        ]);

        $exifIfd = new Ifd([
            ExifTag::DATETIME_ORIGINAL => new IfdEntry(ExifTag::DATETIME_ORIGINAL, 2, 1, '2024:05:02 12:34:56'),
        ]);

        $exifDocument = new ExifDocument($ifd0, $exifIfd, null, null, null);
        $metadata     = new Metadata(['primary'], null, $exifDocument);

        $structured = (new StructuredMetadataBuilder())->build($metadata);

        self::assertInstanceOf(DateTimeImmutable::class, $structured->temporal->original);
        self::assertNull($structured->temporal->tz);
        self::assertNull($structured->temporal->tzSource);
    }

    /**
     * Validates that EXIF 2.2 style payloads populate ISO and dimensions while leaving the timezone unset.
     */
    #[Test]
    public function buildsExif22SampleWithoutTimezone(): void
    {
        $ifd0 = new Ifd([
            ExifTag::IMAGE_WIDTH  => new IfdEntry(ExifTag::IMAGE_WIDTH, 4, 1, 4000),
            ExifTag::IMAGE_HEIGHT => new IfdEntry(ExifTag::IMAGE_HEIGHT, 4, 1, 3000),
        ]);

        $exifIfd = new Ifd([
            ExifTag::EXIF_VERSION             => new IfdEntry(ExifTag::EXIF_VERSION, 7, 4, '0220'),
            ExifTag::PHOTOGRAPHIC_SENSITIVITY => new IfdEntry(ExifTag::PHOTOGRAPHIC_SENSITIVITY, 3, 1, 200),
        ]);

        $exifDocument = new ExifDocument($ifd0, $exifIfd, null, null, null);
        $metadata     = new Metadata(['primary'], null, $exifDocument);

        $structured = (new StructuredMetadataBuilder())->build($metadata);

        self::assertSame(200, $structured->exposure->iso);
        self::assertSame(4000, $structured->image->width);
        self::assertSame(3000, $structured->image->height);
        self::assertNull($structured->temporal->tz);
        self::assertNull($structured->temporal->tzSource);
    }

    /**
     * Ensures legacy PhotographicSensitivity data stored in IFD0 is used when EXIF tags are absent.
     */
    #[Test]
    public function fallsBackToIfd0PhotographicSensitivityForIso(): void
    {
        $ifd0 = new Ifd([
            ExifTag::PHOTOGRAPHIC_SENSITIVITY => new IfdEntry(ExifTag::PHOTOGRAPHIC_SENSITIVITY, 3, 1, 160),
        ]);

        $exifDocument = new ExifDocument($ifd0, new Ifd([]), null, null, null);
        $metadata     = new Metadata(['primary'], null, $exifDocument);

        $structured = (new StructuredMetadataBuilder())->build($metadata);

        self::assertSame(160, $structured->exposure->iso);
    }

    /**
     * Verifies that image dimensions are resolved from IFD0 when EXIF pixel dimension tags are missing.
     */
    #[Test]
    public function fallsBackToIfd0DimensionsWhenExifPixelDimensionsMissing(): void
    {
        $ifd0 = new Ifd([
            ExifTag::IMAGE_WIDTH  => new IfdEntry(ExifTag::IMAGE_WIDTH, 4, 1, 2048),
            ExifTag::IMAGE_HEIGHT => new IfdEntry(ExifTag::IMAGE_HEIGHT, 4, 1, 1536),
        ]);

        $exifDocument = new ExifDocument($ifd0, new Ifd([]), null, null, null);
        $metadata     = new Metadata(['primary'], null, $exifDocument);

        $structured = (new StructuredMetadataBuilder())->build($metadata);

        self::assertSame(2048, $structured->image->width);
        self::assertSame(1536, $structured->image->height);
    }

    /**
     * Ensures EXIF 2.3 inputs derive timezone information from the TimeZoneOffset list.
     */
    #[Test]
    public function buildsExif23SampleWithTimeZoneOffset(): void
    {
        $exifIfd = new Ifd([
            ExifTag::EXIF_VERSION      => new IfdEntry(ExifTag::EXIF_VERSION, 7, 4, '0230'),
            ExifTag::ISO_SPEED         => new IfdEntry(ExifTag::ISO_SPEED, 4, 1, 320),
            ExifTag::DATETIME_ORIGINAL => new IfdEntry(ExifTag::DATETIME_ORIGINAL, 2, 1, '2024:03:10 10:15:30'),
            ExifTag::TIME_ZONE_OFFSET  => new IfdEntry(ExifTag::TIME_ZONE_OFFSET, 8, 1, new ExifNumericList([-90])),
        ]);

        $exifDocument = new ExifDocument(new Ifd([]), $exifIfd, null, null, null);
        $metadata     = new Metadata(['primary'], null, $exifDocument);

        $structured = (new StructuredMetadataBuilder())->build($metadata);

        self::assertSame(320, $structured->exposure->iso);
        self::assertSame('TimeZoneOffset', $structured->temporal->tzSource);
        self::assertSame('-01:30', $structured->temporal->original?->format('P'));
        self::assertSame([-90], $structured->temporal->timeZoneOffsetMinutes);
    }

    /**
     * Covers EXIF 3.0 features including fractional seconds, offset tags and composite image metrics.
     */
    #[Test]
    public function buildsExif30SampleWithOffsetAndComposite(): void
    {
        $ifd0 = new Ifd([
            ExifTag::DATETIME_ORIGINAL => new IfdEntry(ExifTag::DATETIME_ORIGINAL, 2, 1, '2024:07:21 14:05:33'),
        ]);

        $exifIfd = new Ifd([
            ExifTag::EXIF_VERSION                             => new IfdEntry(ExifTag::EXIF_VERSION, 7, 4, '0300'),
            ExifTag::OFFSET_TIME_ORIGINAL                     => new IfdEntry(ExifTag::OFFSET_TIME_ORIGINAL, 2, 6, '+02:30'),
            ExifTag::OFFSET_TIME_DIGITIZED                    => new IfdEntry(ExifTag::OFFSET_TIME_DIGITIZED, 2, 6, '+02:30'),
            ExifTag::SUB_SEC_TIME                             => new IfdEntry(ExifTag::SUB_SEC_TIME, 2, 3, '321'),
            ExifTag::SUB_SEC_TIME_ORIGINAL                    => new IfdEntry(ExifTag::SUB_SEC_TIME_ORIGINAL, 2, 3, '654'),
            ExifTag::SUB_SEC_TIME_DIGITIZED                   => new IfdEntry(ExifTag::SUB_SEC_TIME_DIGITIZED, 2, 3, '987'),
            ExifTag::COMPOSITE_IMAGE                          => new IfdEntry(ExifTag::COMPOSITE_IMAGE, 3, 1, CompositeImage::GENERAL_COMPOSITE->value),
            ExifTag::SOURCE_IMAGE_NUMBER_OF_COMPOSITE_IMAGE   => new IfdEntry(ExifTag::SOURCE_IMAGE_NUMBER_OF_COMPOSITE_IMAGE, 3, 2, new ExifNumericList([5, 2])),
            ExifTag::SOURCE_EXPOSURE_TIMES_OF_COMPOSITE_IMAGE => new IfdEntry(ExifTag::SOURCE_EXPOSURE_TIMES_OF_COMPOSITE_IMAGE, 5, 3, [[1, 30], [1, 15], [1, 8]]),
        ]);

        $exifDocument = new ExifDocument($ifd0, $exifIfd, null, null, null);
        $metadata     = new Metadata(['primary'], null, $exifDocument);

        $structured = (new StructuredMetadataBuilder())->build($metadata);

        self::assertSame('OffsetTimeOriginal', $structured->temporal->tzSource);
        self::assertSame('+02:30', $structured->temporal->offsetTimeOriginal);
        self::assertSame('321', $structured->temporal->subSecTime);
        self::assertSame('654', $structured->temporal->subSecTimeOriginal);
        self::assertSame('987', $structured->temporal->subSecTimeDigitized);

        self::assertSame(CompositeImage::GENERAL_COMPOSITE, $structured->composite->type);
        self::assertSame([5, 2], $structured->composite->counts);
        self::assertEqualsWithDelta(0.0333333333, $structured->composite->exposureTimesTotal[0], 1e-10);
        self::assertEqualsWithDelta(0.0666666666, $structured->composite->exposureTimesTotal[1], 1e-10);
        self::assertEqualsWithDelta(0.125, $structured->composite->exposureTimesTotal[2], 1e-10);
    }

    /**
     * Verifies that the color space upgrades to Adobe RGB when tagged as uncalibrated with an R03 interop index.
     */
    #[Test]
    public function normalizesUncalibratedColorSpaceViaInteropR03(): void
    {
        $ifd0 = new Ifd([
            ExifTag::IMAGE_WIDTH  => new IfdEntry(ExifTag::IMAGE_WIDTH, 4, 1, 1024),
            ExifTag::IMAGE_HEIGHT => new IfdEntry(ExifTag::IMAGE_HEIGHT, 4, 1, 768),
        ]);

        $exifIfd = new Ifd([
            ExifTag::COLOR_SPACE => new IfdEntry(ExifTag::COLOR_SPACE, 3, 1, ColorSpace::UNCALIBRATED->value),
        ]);

        $interopIfd = new Ifd([
            ExifTag::INTEROPERABILITY_INDEX => new IfdEntry(ExifTag::INTEROPERABILITY_INDEX, 2, 3, 'R03'),
        ]);

        $exifDocument = new ExifDocument($ifd0, $exifIfd, null, $interopIfd, null);
        $metadata     = new Metadata(['primary'], null, $exifDocument);

        $structured = (new StructuredMetadataBuilder())->build($metadata);

        self::assertSame(ColorSpace::ADOBE_RGB, $structured->image->colorSpace);
    }

    /**
     * Confirms that lenses providing only MAX_APERTURE_VALUE still expose the derived f-number.
     */
    #[Test]
    public function derivesLensMaxApertureFromApex(): void
    {
        $exifIfd = new Ifd([
            ExifTag::MAX_APERTURE_VALUE => new IfdEntry(ExifTag::MAX_APERTURE_VALUE, 5, 1, [[4, 1]]),
        ]);

        $exifDocument = new ExifDocument(new Ifd([]), $exifIfd, null, null, null);
        $metadata     = new Metadata(['primary'], null, $exifDocument);

        $structured = (new StructuredMetadataBuilder())->build($metadata);

        self::assertNotNull($structured->lens->maxApertureFNumber);
        self::assertEqualsWithDelta(4.0, $structured->lens->maxApertureFNumber, 0.0001);
    }

    /**
     * Ensures GPS speed values are converted from kilometres per hour to metres per second.
     */
    #[Test]
    public function convertsGpsSpeedToMetresPerSecond(): void
    {
        $gpsIfd = new Ifd([
            ExifTag::GPS_SPEED_REF => new IfdEntry(ExifTag::GPS_SPEED_REF, 2, 2, 'K'),
            ExifTag::GPS_SPEED     => new IfdEntry(ExifTag::GPS_SPEED, 5, 1, [[120, 1]]),
        ]);

        $exifDocument = new ExifDocument(new Ifd([]), null, $gpsIfd, null, null);
        $metadata     = new Metadata(['primary'], null, $exifDocument);

        $structured = (new StructuredMetadataBuilder())->build($metadata);

        self::assertEqualsWithDelta(33.3333333, $structured->gps->speedMs, 1e-6);
    }

    /**
     * Verifies that empty metadata still instantiates every value object with null/default state.
     */
    #[Test]
    public function instantiatesValueObjectsWithNullStateWhenMetadataMissing(): void
    {
        $structured = (new StructuredMetadataBuilder())->build(new Metadata([], null, null, []));

        foreach (get_object_vars($structured) as $name => $value) {
            self::assertIsObject($value, sprintf('Expected %s to be an object value object', $name));

            foreach (get_object_vars($value) as $field => $fieldValue) {
                if ($fieldValue === null) {
                    continue;
                }

                if ($name === 'standards' && $field === 'profile') {
                    self::assertSame('2.2', $fieldValue, 'standards::profile should fall back to the default EXIF capability profile');
                    continue;
                }

                if (is_array($fieldValue)) {
                    self::assertSame([], $fieldValue, sprintf('%s::%s should be an empty array when metadata is missing', $name, $field));
                    continue;
                }

                self::fail(sprintf('%s::%s expected null/empty, got %s', $name, $field, var_export($fieldValue, true)));
            }
        }
    }

    /**
     * Builds a Classic TIFF blob with EXIF/Flashpix version tags encoded as printable UNDEFINED values.
     */
    private function buildClassicVersionBlob(): string
    {
        $header = 'II' . pack('v', 0x002A) . pack('V', 8);

        $exifIfdOffset = 8 + 2 + (1 * 12) + 4;

        $ifd0Entry = pack('v', ExifTag::EXIF_IFD_POINTER)
            . pack('v', 4)
            . pack('V', 1)
            . pack('V', $exifIfdOffset);

        $ifd0 = pack('v', 1) . $ifd0Entry . pack('V', 0);

        $exifEntries = [
            pack('v', ExifTag::EXIF_VERSION)
                . pack('v', 7)
                . pack('V', 4)
                . pack('V', $this->inlineAsciiToInt('0232')),
            pack('v', ExifTag::FLASHPIX_VERSION)
                . pack('v', 7)
                . pack('V', 4)
                . pack('V', $this->inlineAsciiToInt('0100')),
        ];

        $exifIfd = pack('v', count($exifEntries)) . implode('', $exifEntries) . pack('V', 0);

        return $header . $ifd0 . $exifIfd;
    }

    /**
     * Converts printable ASCII into an inline Classic TIFF integer representation.
     */
    private function inlineAsciiToInt(string $ascii): int
    {
        $bytes = str_pad($ascii, 4, "\0");

        $value = unpack('V', $bytes);

        return (int) ($value[1] ?? 0);
    }
}
