<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Curate\Resolver;

use DateTimeImmutable;
use MagicSunday\ImageMeta\Curate\Resolver\ExifTagResolver;
use MagicSunday\ImageMeta\Model\Exif\ExifDocument;
use MagicSunday\ImageMeta\Model\Exif\ExifNumericList;
use MagicSunday\ImageMeta\Model\Exif\ExifRational;
use MagicSunday\ImageMeta\Model\Exif\ExifRationalList;
use MagicSunday\ImageMeta\Model\Exif\ExifTag;
use MagicSunday\ImageMeta\Model\Exif\Ifd;
use MagicSunday\ImageMeta\Model\Exif\IfdEntry;
use MagicSunday\ImageMeta\Parse\Tiff\TiffExifReader;
use MagicSunday\ImageMeta\Tests\Support\GpsTiffBuilder;
use MagicSunday\ImageMeta\Value\Enum\CfaPatternColor;
use MagicSunday\ImageMeta\Value\Enum\CustomRendered;
use MagicSunday\ImageMeta\Value\Enum\SceneType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use function sqrt;

/**
 * @covers \MagicSunday\ImageMeta\Curate\Resolver\ExifTagResolver
 */
#[CoversClass(ExifTagResolver::class)]
#[UsesClass(ExifDocument::class)]
#[UsesClass(Ifd::class)]
#[UsesClass(IfdEntry::class)]
#[UsesClass(ExifRational::class)]
#[UsesClass(ExifRationalList::class)]
#[UsesClass(ExifNumericList::class)]
#[UsesClass(TiffExifReader::class)]
#[UsesClass(GpsTiffBuilder::class)]
final class ExifTagResolverTest extends TestCase
{
    /**
     * Ensures the resolver exposes the extended GPS metadata decoded from the GPS IFD.
     */
    #[Test]
    public function exposesExtendedGpsMetadata(): void
    {
        $gpsIfd = new Ifd([
            ExifTag::GPS_VERSION_ID   => new IfdEntry(ExifTag::GPS_VERSION_ID, 1, 4, [3, 0, 0, 0]),
            ExifTag::GPS_LATITUDE_REF => new IfdEntry(ExifTag::GPS_LATITUDE_REF, 2, 2, 'N'),
            ExifTag::GPS_LATITUDE     => new IfdEntry(
                ExifTag::GPS_LATITUDE,
                5,
                3,
                new ExifRationalList([
                    new ExifRational(51, 1),
                    new ExifRational(30, 1),
                    new ExifRational(0, 1),
                ]),
            ),
            ExifTag::GPS_LONGITUDE_REF => new IfdEntry(ExifTag::GPS_LONGITUDE_REF, 2, 2, 'E'),
            ExifTag::GPS_LONGITUDE     => new IfdEntry(
                ExifTag::GPS_LONGITUDE,
                5,
                3,
                new ExifRationalList([
                    new ExifRational(8, 1),
                    new ExifRational(30, 1),
                    new ExifRational(0, 1),
                ]),
            ),
            ExifTag::GPS_ALTITUDE_REF => new IfdEntry(ExifTag::GPS_ALTITUDE_REF, 1, 1, 0),
            ExifTag::GPS_ALTITUDE     => new IfdEntry(ExifTag::GPS_ALTITUDE, 5, 1, new ExifRational(150, 1)),
            ExifTag::GPS_TIME_STAMP   => new IfdEntry(
                ExifTag::GPS_TIME_STAMP,
                5,
                3,
                new ExifRationalList([
                    new ExifRational(12, 1),
                    new ExifRational(34, 1),
                    new ExifRational(56789, 1000),
                ]),
            ),
            ExifTag::GPS_DATE_STAMP        => new IfdEntry(ExifTag::GPS_DATE_STAMP, 2, 10, '2024:05:06'),
            ExifTag::GPS_SATELLITES        => new IfdEntry(ExifTag::GPS_SATELLITES, 2, 2, '05'),
            ExifTag::GPS_STATUS            => new IfdEntry(ExifTag::GPS_STATUS, 2, 1, 'A'),
            ExifTag::GPS_MEASURE_MODE      => new IfdEntry(ExifTag::GPS_MEASURE_MODE, 2, 1, '3'),
            ExifTag::GPS_DOP               => new IfdEntry(ExifTag::GPS_DOP, 5, 1, new ExifRational(25, 10)),
            ExifTag::GPS_SPEED_REF         => new IfdEntry(ExifTag::GPS_SPEED_REF, 2, 1, 'K'),
            ExifTag::GPS_SPEED             => new IfdEntry(ExifTag::GPS_SPEED, 5, 1, new ExifRational(72000, 1000)),
            ExifTag::GPS_TRACK_REF         => new IfdEntry(ExifTag::GPS_TRACK_REF, 2, 1, 'T'),
            ExifTag::GPS_TRACK             => new IfdEntry(ExifTag::GPS_TRACK, 5, 1, new ExifRational(12345, 100)),
            ExifTag::GPS_IMG_DIRECTION_REF => new IfdEntry(ExifTag::GPS_IMG_DIRECTION_REF, 2, 1, 'M'),
            ExifTag::GPS_IMG_DIRECTION     => new IfdEntry(ExifTag::GPS_IMG_DIRECTION, 5, 1, new ExifRational(2500, 10)),
            ExifTag::GPS_MAP_DATUM         => new IfdEntry(ExifTag::GPS_MAP_DATUM, 2, 6, 'WGS-84'),
            ExifTag::GPS_DEST_LATITUDE_REF => new IfdEntry(ExifTag::GPS_DEST_LATITUDE_REF, 2, 1, 'N'),
            ExifTag::GPS_DEST_LATITUDE     => new IfdEntry(
                ExifTag::GPS_DEST_LATITUDE,
                5,
                3,
                new ExifRationalList([
                    new ExifRational(41, 1),
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
                    new ExifRational(8, 1),
                    new ExifRational(30, 1),
                    new ExifRational(0, 1),
                ]),
            ),
            ExifTag::GPS_DEST_BEARING_REF    => new IfdEntry(ExifTag::GPS_DEST_BEARING_REF, 2, 1, 'T'),
            ExifTag::GPS_DEST_BEARING        => new IfdEntry(ExifTag::GPS_DEST_BEARING, 5, 1, new ExifRational(123, 1)),
            ExifTag::GPS_DEST_DISTANCE_REF   => new IfdEntry(ExifTag::GPS_DEST_DISTANCE_REF, 2, 1, 'K'),
            ExifTag::GPS_DEST_DISTANCE       => new IfdEntry(ExifTag::GPS_DEST_DISTANCE, 5, 1, new ExifRational(42, 1)),
            ExifTag::GPS_PROCESSING_METHOD   => new IfdEntry(ExifTag::GPS_PROCESSING_METHOD, 7, 11, "ASCII\0\0\0NETWORK"),
            ExifTag::GPS_AREA_INFORMATION    => new IfdEntry(ExifTag::GPS_AREA_INFORMATION, 7, 13, "ASCII\0\0\0AreaName"),
            ExifTag::GPS_DIFFERENTIAL        => new IfdEntry(ExifTag::GPS_DIFFERENTIAL, 3, 1, 2),
            ExifTag::GPS_H_POSITIONING_ERROR => new IfdEntry(ExifTag::GPS_H_POSITIONING_ERROR, 5, 1, new ExifRational(15, 10)),
        ]);

        $document = new ExifDocument(new Ifd([]), null, $gpsIfd, null, null);
        $resolver = new ExifTagResolver($document);

        $gps = $resolver->gps();
        self::assertSame('N', $gps['lat_ref']);
        self::assertIsFloat($gps['lat']);
        self::assertEqualsWithDelta(51.5, $gps['lat'], 0.000001);
        self::assertSame('E', $gps['lon_ref']);
        self::assertIsFloat($gps['lon']);
        self::assertEqualsWithDelta(8.5, $gps['lon'], 0.000001);
        self::assertSame(0, $gps['alt_ref']);
        self::assertEqualsWithDelta(150.0, $gps['alt'], 0.000001);

        self::assertSame('N', $resolver->gpsLatitudeRef());
        self::assertSame('E', $resolver->gpsLongitudeRef());
        self::assertSame(0, $resolver->gpsAltitudeRef());
        self::assertSame('3.0.0.0', $resolver->gpsVersion());
        self::assertSame('05', $resolver->gpsSatellites());
        self::assertSame('A', $resolver->gpsStatus());
        self::assertSame('3', $resolver->gpsMeasureMode());
        $dop = $resolver->gpsDop();
        self::assertIsFloat($dop);
        self::assertEqualsWithDelta(2.5, $dop, 0.000001);
        self::assertSame('K', $resolver->gpsSpeedRef());
        $speed = $resolver->gpsSpeed();
        self::assertIsFloat($speed);
        self::assertEqualsWithDelta(20.0, $speed, 0.000001);
        self::assertSame('T', $resolver->gpsTrackRef());
        $track = $resolver->gpsTrack();
        self::assertIsFloat($track);
        self::assertEqualsWithDelta(123.45, $track, 0.000001);
        self::assertSame('M', $resolver->gpsImgDirectionRef());
        $imgDirection = $resolver->gpsImgDirection();
        self::assertIsFloat($imgDirection);
        self::assertEqualsWithDelta(250.0, $imgDirection, 0.000001);
        self::assertSame('WGS-84', $resolver->gpsMapDatum());
        self::assertSame('N', $resolver->gpsDestinationLatitudeRef());
        $destLat = $resolver->gpsDestinationLatitude();
        self::assertIsFloat($destLat);
        self::assertEqualsWithDelta(41.0, $destLat, 0.000001);
        self::assertSame('E', $resolver->gpsDestinationLongitudeRef());
        $destLon = $resolver->gpsDestinationLongitude();
        self::assertIsFloat($destLon);
        self::assertEqualsWithDelta(8.5, $destLon, 0.000001);
        self::assertSame('T', $resolver->gpsDestinationBearingRef());
        $destBearing = $resolver->gpsDestinationBearing();
        self::assertIsFloat($destBearing);
        self::assertEqualsWithDelta(123.0, $destBearing, 0.000001);
        self::assertSame('K', $resolver->gpsDestinationDistanceRef());
        $destDistance = $resolver->gpsDestinationDistance();
        self::assertIsFloat($destDistance);
        self::assertEqualsWithDelta(42000.0, $destDistance, 0.000001);
        self::assertSame('NETWORK', $resolver->gpsProcessingMethod());
        self::assertSame('AreaName', $resolver->gpsAreaInformation());
        self::assertSame('2024-05-06', $resolver->gpsDate());
        self::assertSame('12:34:56.789', $resolver->gpsTime());

        $timestamp = $resolver->gpsTimestamp();
        self::assertInstanceOf(DateTimeImmutable::class, $timestamp);
        self::assertSame('2024-05-06T12:34:56+00:00', $timestamp->format(DATE_ATOM));
        self::assertSame('12:34:56.789000', $timestamp->format('H:i:s.u'));

        self::assertSame(2, $resolver->gpsDifferential());
        $horizontalError = $resolver->gpsHorizontalPositioningError();
        self::assertIsFloat($horizontalError);
        self::assertEqualsWithDelta(1.5, $horizontalError, 0.000001);
    }

    /**
     * Ensures GPS data parsed from a TIFF blob is normalised and exposed via resolver helpers.
     */
    #[Test]
    public function resolvesGpsMetadataFromSyntheticTiff(): void
    {
        $document = (new TiffExifReader())->parseFromBlob(GpsTiffBuilder::buildClassicGpsTiff());
        $resolver = new ExifTagResolver($document);

        $gps = $resolver->gps();

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

        self::assertSame('K', $resolver->gpsSpeedRef());
        $speed = $resolver->gpsSpeed();
        self::assertIsFloat($speed);
        self::assertEqualsWithDelta(20.0, $speed, 0.000001);
        self::assertSame('T', $resolver->gpsTrackRef());
        $track = $resolver->gpsTrack();
        self::assertIsFloat($track);
        self::assertEqualsWithDelta(90.0, $track, 0.000001);
        self::assertSame('M', $resolver->gpsImgDirectionRef());
        $imgDirection = $resolver->gpsImgDirection();
        self::assertIsFloat($imgDirection);
        self::assertEqualsWithDelta(45.0, $imgDirection, 0.000001);
        self::assertSame('T', $resolver->gpsDestinationBearingRef());
        $destBearing = $resolver->gpsDestinationBearing();
        self::assertIsFloat($destBearing);
        self::assertEqualsWithDelta(45.0, $destBearing, 0.000001);
        self::assertSame('K', $resolver->gpsDestinationDistanceRef());
        $destDistance = $resolver->gpsDestinationDistance();
        self::assertIsFloat($destDistance);
        self::assertEqualsWithDelta(42000.0, $destDistance, 0.000001);
        self::assertSame('2024-05-06', $resolver->gpsDate());
        self::assertSame('12:34:56.789', $resolver->gpsTime());

        $timestamp = $resolver->gpsTimestamp();
        self::assertInstanceOf(DateTimeImmutable::class, $timestamp);
        self::assertSame('2024-05-06T12:34:56+00:00', $timestamp->format(DATE_ATOM));

        self::assertSame(2, $resolver->gpsDifferential());
        $horizontalError = $resolver->gpsHorizontalPositioningError();
        self::assertIsFloat($horizontalError);
        self::assertEqualsWithDelta(1.5, $horizontalError, 0.000001);
    }

    /**
     * Ensures temporal helper methods expose fractional seconds and offsets.
     */
    #[Test]
    public function exposesTemporalMetadata(): void
    {
        $exifIfd = new Ifd([
            ExifTag::SUB_SEC_TIME           => new IfdEntry(ExifTag::SUB_SEC_TIME, 2, 3, '987'),
            ExifTag::SUB_SEC_TIME_ORIGINAL  => new IfdEntry(ExifTag::SUB_SEC_TIME_ORIGINAL, 2, 3, '123'),
            ExifTag::SUB_SEC_TIME_DIGITIZED => new IfdEntry(ExifTag::SUB_SEC_TIME_DIGITIZED, 2, 3, '456'),
            ExifTag::OFFSET_TIME            => new IfdEntry(ExifTag::OFFSET_TIME, 2, 6, '+00:30'),
            ExifTag::OFFSET_TIME_ORIGINAL   => new IfdEntry(ExifTag::OFFSET_TIME_ORIGINAL, 2, 6, '-01:30'),
            ExifTag::OFFSET_TIME_DIGITIZED  => new IfdEntry(ExifTag::OFFSET_TIME_DIGITIZED, 2, 6, '+01:45'),
            ExifTag::TIME_ZONE_OFFSET       => new IfdEntry(ExifTag::TIME_ZONE_OFFSET, 8, 2, new ExifNumericList([-1, 2])),
            ExifTag::SELF_TIMER_MODE        => new IfdEntry(ExifTag::SELF_TIMER_MODE, 3, 1, 7),
            ExifTag::BATTERY_LEVEL          => new IfdEntry(ExifTag::BATTERY_LEVEL, 2, 3, '85%'),
            ExifTag::INTERLACE              => new IfdEntry(ExifTag::INTERLACE, 3, 1, 1),
        ]);

        $document = new ExifDocument(new Ifd([]), $exifIfd, null, null, null);
        $resolver = new ExifTagResolver($document);

        self::assertSame('987', $resolver->subSecTime());
        self::assertSame('123', $resolver->subSecTimeOriginal());
        self::assertSame('456', $resolver->subSecTimeDigitized());
        self::assertSame('+00:30', $resolver->offsetTime());
        self::assertSame('-01:30', $resolver->offsetTimeOriginal());
        self::assertSame('+01:45', $resolver->offsetTimeDigitized());
        self::assertSame([-60, 120], $resolver->timeZoneOffsetMinutes());
        self::assertSame(7, $resolver->selfTimerModeSeconds());
        $battery = $resolver->batteryLevelPercent();
        self::assertNotNull($battery);
        self::assertEqualsWithDelta(85.0, $battery, 0.0001);
        self::assertSame(1, $resolver->interlace());
    }

    /**
     * Ensures GPS conversions return null when unit references are unknown.
     */
    #[Test]
    public function gpsConversionsRequireKnownReferences(): void
    {
        $gpsIfd = new Ifd([
            ExifTag::GPS_SPEED_REF         => new IfdEntry(ExifTag::GPS_SPEED_REF, 2, 1, 'X'),
            ExifTag::GPS_SPEED             => new IfdEntry(ExifTag::GPS_SPEED, 5, 1, new ExifRational(72000, 1000)),
            ExifTag::GPS_DEST_DISTANCE_REF => new IfdEntry(ExifTag::GPS_DEST_DISTANCE_REF, 2, 1, 'Q'),
            ExifTag::GPS_DEST_DISTANCE     => new IfdEntry(ExifTag::GPS_DEST_DISTANCE, 5, 1, new ExifRational(42, 1)),
        ]);

        $resolver = new ExifTagResolver(new ExifDocument(new Ifd([]), null, $gpsIfd, null, null));

        self::assertNull($resolver->gpsSpeed());
        self::assertNull($resolver->gpsDestinationDistance());
    }

    /**
     * Ensures scene descriptors, CFA patterns, and software metadata resolve to typed structures.
     */
    #[Test]
    public function resolvesSceneDescriptorsAndSoftwareMetadata(): void
    {
        $ifd0 = new Ifd([
            ExifTag::IMAGE_DESCRIPTION => new IfdEntry(ExifTag::IMAGE_DESCRIPTION, 2, 1, 'Evening scene'),
            ExifTag::IMAGE_TITLE       => new IfdEntry(ExifTag::IMAGE_TITLE, 2, 1, 'Evening Glow'),
            ExifTag::PHOTOGRAPHER      => new IfdEntry(ExifTag::PHOTOGRAPHER, 2, 1, 'Jamie Doe'),
            ExifTag::IMAGE_EDITOR      => new IfdEntry(ExifTag::IMAGE_EDITOR, 2, 1, 'Casey Edit'),
            ExifTag::PROCESSING_SOFTWARE => new IfdEntry(ExifTag::PROCESSING_SOFTWARE, 2, 1, "ImageMeta Studio\0\0"),
            ExifTag::SOFTWARE            => new IfdEntry(ExifTag::SOFTWARE, 2, 1, 'Legacy Writer'),
        ]);

        $exifIfd = new Ifd([
            ExifTag::COMPONENTS_CONFIGURATION  => new IfdEntry(ExifTag::COMPONENTS_CONFIGURATION, 7, 4, new ExifNumericList([1, 2, 3, 0])),
            ExifTag::SCENE_TYPE                => new IfdEntry(ExifTag::SCENE_TYPE, 7, 1, chr(1)),
            ExifTag::CFA_REPEAT_PATTERN_DIM    => new IfdEntry(ExifTag::CFA_REPEAT_PATTERN_DIM, 3, 2, new ExifNumericList([6, 4])),
            ExifTag::CFA_PATTERN               => new IfdEntry(ExifTag::CFA_PATTERN, 7, 4, "\x00\x01\x02\x03"),
            ExifTag::CUSTOM_RENDERED           => new IfdEntry(ExifTag::CUSTOM_RENDERED, 3, 1, 1),
            ExifTag::CAMERA_FIRMWARE           => new IfdEntry(ExifTag::CAMERA_FIRMWARE, 2, 1, 'FW Main'),
            ExifTag::RAW_DEVELOPING_SOFTWARE   => new IfdEntry(ExifTag::RAW_DEVELOPING_SOFTWARE, 2, 1, 'Raw Studio'),
            ExifTag::IMAGE_EDITING_SOFTWARE    => new IfdEntry(ExifTag::IMAGE_EDITING_SOFTWARE, 2, 1, 'Pixel Edit'),
            ExifTag::METADATA_EDITING_SOFTWARE => new IfdEntry(ExifTag::METADATA_EDITING_SOFTWARE, 2, 1, 'Meta Desk'),
            ExifTag::CAMERA_FIRMWARE_LEGACY      => new IfdEntry(ExifTag::CAMERA_FIRMWARE_LEGACY, 2, 1, 'FW Main'),
            ExifTag::IMAGE_EDITING_SOFTWARE_LEGACY    => new IfdEntry(ExifTag::IMAGE_EDITING_SOFTWARE_LEGACY, 2, 1, 'Pixel Edit'),
            ExifTag::METADATA_EDITING_SOFTWARE_LEGACY => new IfdEntry(ExifTag::METADATA_EDITING_SOFTWARE_LEGACY, 2, 1, 'Meta Desk'),
        ]);

        $document = new ExifDocument($ifd0, $exifIfd, null, null, null);
        $resolver = new ExifTagResolver($document);

        self::assertSame(['Y', 'Cb', 'Cr', '-'], $resolver->componentsConfigurationLabels());
        self::assertSame('Y Cb Cr -', $resolver->componentsConfigurationDescription());
        self::assertSame(SceneType::DIRECTLY_PHOTOGRAPHED_IMAGE, $resolver->sceneType());
        self::assertSame([
            CfaPatternColor::RED,
            CfaPatternColor::GREEN,
            CfaPatternColor::BLUE,
            CfaPatternColor::CYAN,
        ], $resolver->cfaPatternColors());
        self::assertSame(6, $resolver->cfaRepeatPatternWidth());
        self::assertSame(4, $resolver->cfaRepeatPatternHeight());
        self::assertSame(CustomRendered::CUSTOM_PROCESS, $resolver->customRendered());
        self::assertSame('Evening Glow', $resolver->imageTitle());
        self::assertSame('Jamie Doe', $resolver->photographer());
        self::assertSame('Casey Edit', $resolver->imageEditor());
        self::assertSame('FW Main', $resolver->cameraFirmware());
        self::assertSame('Raw Studio', $resolver->rawDevelopingSoftware());
        self::assertSame('Pixel Edit', $resolver->imageEditingSoftware());
        self::assertSame('Meta Desk', $resolver->metadataEditingSoftware());
        self::assertSame('ImageMeta Studio', $resolver->processingSoftware());
        self::assertSame('ImageMeta Studio', $resolver->software());
    }

    #[Test]
    public function fallsBackToLegacySoftwareTag(): void
    {
        $ifd0 = new Ifd([
            ExifTag::SOFTWARE => new IfdEntry(ExifTag::SOFTWARE, 2, 1, 'Legacy Writer'),
        ]);

        $resolver = new ExifTagResolver(new ExifDocument($ifd0, null, null, null, null));

        self::assertSame('Legacy Writer', $resolver->software());
    }

    #[Test]
    public function resolvesLegacyTextualTags(): void
    {
        $ifd0 = new Ifd([
            ExifTag::IMAGE_TITLE_LEGACY  => new IfdEntry(ExifTag::IMAGE_TITLE_LEGACY, 2, 1, 'Legacy Image Title'),
            ExifTag::PHOTOGRAPHER_LEGACY => new IfdEntry(ExifTag::PHOTOGRAPHER_LEGACY, 2, 1, 'Legacy Photographer'),
            ExifTag::IMAGE_EDITOR_LEGACY => new IfdEntry(ExifTag::IMAGE_EDITOR_LEGACY, 2, 1, 'Legacy Image Editor'),
            ExifTag::ARTIST              => new IfdEntry(ExifTag::ARTIST, 2, 1, 'Artist Fallback'),
            ExifTag::IMAGE_DESCRIPTION   => new IfdEntry(ExifTag::IMAGE_DESCRIPTION, 2, 1, 'Description Fallback'),
        ]);

        $exifIfd = new Ifd([
            ExifTag::CAMERA_FIRMWARE_LEGACY           => new IfdEntry(ExifTag::CAMERA_FIRMWARE_LEGACY, 2, 1, 'Legacy FW'),
            ExifTag::RAW_DEVELOPING_SOFTWARE_LEGACY   => new IfdEntry(ExifTag::RAW_DEVELOPING_SOFTWARE_LEGACY, 2, 1, 'Legacy Raw'),
            ExifTag::IMAGE_EDITING_SOFTWARE_LEGACY    => new IfdEntry(ExifTag::IMAGE_EDITING_SOFTWARE_LEGACY, 2, 1, 'Legacy Edit'),
            ExifTag::METADATA_EDITING_SOFTWARE_LEGACY => new IfdEntry(ExifTag::METADATA_EDITING_SOFTWARE_LEGACY, 2, 1, 'Legacy Meta'),
        ]);

        $resolver = new ExifTagResolver(new ExifDocument($ifd0, $exifIfd, null, null, null));

        self::assertSame('Legacy Image Title', $resolver->imageTitle());
        self::assertSame('Legacy Photographer', $resolver->photographer());
        self::assertSame('Legacy Image Editor', $resolver->imageEditor());
        self::assertSame('Legacy FW', $resolver->cameraFirmware());
        self::assertSame('Legacy Raw', $resolver->rawDevelopingSoftware());
        self::assertSame('Legacy Edit', $resolver->imageEditingSoftware());
        self::assertSame('Legacy Meta', $resolver->metadataEditingSoftware());
    }

    #[Test]
    public function exifTwoResolverPrefersLegacySoftwareNamesOverVersionStrings(): void
    {
        $exifIfd = new Ifd([
            ExifTag::EXIF_VERSION                    => new IfdEntry(ExifTag::EXIF_VERSION, 7, 4, '0221'),
            ExifTag::CAMERA_FIRMWARE                 => new IfdEntry(ExifTag::CAMERA_FIRMWARE, 2, 1, 'FW 1.2.3'),
            ExifTag::IMAGE_EDITING_SOFTWARE          => new IfdEntry(ExifTag::IMAGE_EDITING_SOFTWARE, 2, 1, 'Edit Suite 4.5'),
            ExifTag::METADATA_EDITING_SOFTWARE       => new IfdEntry(ExifTag::METADATA_EDITING_SOFTWARE, 2, 1, 'Meta Suite 6.7'),
            ExifTag::CAMERA_FIRMWARE_LEGACY          => new IfdEntry(ExifTag::CAMERA_FIRMWARE_LEGACY, 2, 1, 'Legacy Firmware Name'),
            ExifTag::IMAGE_EDITING_SOFTWARE_LEGACY   => new IfdEntry(ExifTag::IMAGE_EDITING_SOFTWARE_LEGACY, 2, 1, 'Legacy Editor Name'),
            ExifTag::METADATA_EDITING_SOFTWARE_LEGACY => new IfdEntry(ExifTag::METADATA_EDITING_SOFTWARE_LEGACY, 2, 1, 'Legacy Metadata Name'),
        ]);

        $resolver = new ExifTagResolver(new ExifDocument(new Ifd([]), $exifIfd, null, null, null));

        self::assertSame('Legacy Firmware Name', $resolver->cameraFirmware());
        self::assertSame('Legacy Editor Name', $resolver->imageEditingSoftware());
        self::assertSame('Legacy Metadata Name', $resolver->metadataEditingSoftware());
    }

    #[Test]
    public function resolvesDocumentNameTag(): void
    {
        $ifd0 = new Ifd([
            ExifTag::DOCUMENT_NAME => new IfdEntry(ExifTag::DOCUMENT_NAME, 2, 1, 'Scan 42'),
        ]);

        $document = new ExifDocument($ifd0, null, null, null, null);
        $resolver = new ExifTagResolver($document);

        self::assertSame('Scan 42', $resolver->documentName());
    }

    #[Test]
    public function resolvesXpStringFallbacks(): void
    {
        $ifd0 = new Ifd([
            ExifTag::XP_TITLE    => new IfdEntry(ExifTag::XP_TITLE, 1, 1, 'XP Title'),
            ExifTag::XP_COMMENT  => new IfdEntry(ExifTag::XP_COMMENT, 1, 1, 'XP Comment'),
            ExifTag::XP_AUTHOR   => new IfdEntry(ExifTag::XP_AUTHOR, 1, 1, 'XP Author'),
            ExifTag::XP_KEYWORDS => new IfdEntry(ExifTag::XP_KEYWORDS, 1, 1, 'One;Two'),
            ExifTag::XP_SUBJECT  => new IfdEntry(ExifTag::XP_SUBJECT, 1, 1, 'XP Subject'),
        ]);

        $document = new ExifDocument($ifd0, null, null, null, null);
        $resolver = new ExifTagResolver($document);

        self::assertSame('XP Title', $resolver->imageTitle());
        self::assertSame('XP Subject', $resolver->documentName());
        self::assertSame('XP Comment', $resolver->imageDescription());
        self::assertSame('XP Author', $resolver->photographer());
        self::assertSame('XP Author', $resolver->imageEditor());
        self::assertSame('XP Title', $resolver->xpTitle());
        self::assertSame('XP Comment', $resolver->xpComment());
        self::assertSame('XP Author', $resolver->xpAuthor());
        self::assertSame('XP Subject', $resolver->xpSubject());
        self::assertSame(['One', 'Two'], $resolver->xpKeywords());
    }

    #[Test]
    public function resolvesLegacySoftwareVersions(): void
    {
        $exifIfd = new Ifd([
            ExifTag::CAMERA_FIRMWARE_VERSION_LEGACY           => new IfdEntry(ExifTag::CAMERA_FIRMWARE_VERSION_LEGACY, 2, 1, 'FW 3.1.0'),
            ExifTag::RAW_DEVELOPING_SOFTWARE_VERSION_LEGACY   => new IfdEntry(ExifTag::RAW_DEVELOPING_SOFTWARE_VERSION_LEGACY, 2, 1, 'RawLab 5.2.1'),
            ExifTag::IMAGE_EDITING_SOFTWARE_VERSION_LEGACY    => new IfdEntry(ExifTag::IMAGE_EDITING_SOFTWARE_VERSION_LEGACY, 2, 1, 'ImageLab 2.3'),
            ExifTag::METADATA_EDITING_SOFTWARE_VERSION_LEGACY => new IfdEntry(ExifTag::METADATA_EDITING_SOFTWARE_VERSION_LEGACY, 2, 1, 'MetaLab 1.0.0'),
        ]);

        $resolver = new ExifTagResolver(new ExifDocument(new Ifd([]), $exifIfd, null, null, null));

        self::assertSame('FW 3.1.0', $resolver->cameraFirmwareVersion());
        self::assertSame('RawLab 5.2.1', $resolver->rawDevelopingSoftwareVersion());
        self::assertSame('ImageLab 2.3', $resolver->imageEditingSoftwareVersion());
        self::assertSame('MetaLab 1.0.0', $resolver->metadataEditingSoftwareVersion());
    }

    #[Test]
    public function resolvesMakerNoteSafetyFlag(): void
    {
        $safeIfd = new Ifd([
            ExifTag::MAKER_NOTE_SAFETY => new IfdEntry(ExifTag::MAKER_NOTE_SAFETY, 3, 1, 1),
        ]);

        $unsafeIfd = new Ifd([
            ExifTag::MAKER_NOTE_SAFETY => new IfdEntry(ExifTag::MAKER_NOTE_SAFETY, 3, 1, 0),
        ]);

        $safeResolver   = new ExifTagResolver(new ExifDocument(new Ifd([]), $safeIfd, null, null, null));
        $unsafeResolver = new ExifTagResolver(new ExifDocument(new Ifd([]), $unsafeIfd, null, null, null));
        $missingResolver = new ExifTagResolver(new ExifDocument(new Ifd([]), null, null, null, null));

        self::assertTrue($safeResolver->makerNoteSafety());
        self::assertFalse($unsafeResolver->makerNoteSafety());
        self::assertNull($missingResolver->makerNoteSafety());
    }

    #[Test]
    public function exposesAccelerationVector(): void
    {
        $exifIfd = new Ifd([
            ExifTag::ACCELERATION => new IfdEntry(
                ExifTag::ACCELERATION,
                10,
                3,
                new ExifRationalList([
                    new ExifRational(1, 10),
                    new ExifRational(2, 10),
                    new ExifRational(-3, 10),
                ]),
            ),
        ]);

        $resolver = new ExifTagResolver(new ExifDocument(new Ifd([]), $exifIfd, null, null, null));

        $vector = $resolver->accelerationVector();
        self::assertNotNull($vector);
        self::assertSame([0.1, 0.2, -0.3], $vector);

        $magnitude = $resolver->accelerationMs2();
        self::assertNotNull($magnitude);
        self::assertEqualsWithDelta(sqrt(0.14), $magnitude, 0.000001);
    }
}
