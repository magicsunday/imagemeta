<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Parse\Tiff;

use MagicSunday\ImageMeta\Core\BitMask;
use MagicSunday\ImageMeta\Core\Endian;
use MagicSunday\ImageMeta\Core\MemoryBuffer;
use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\Util\UInt64;
use MagicSunday\ImageMeta\Core\Util\Unpack;
use MagicSunday\ImageMeta\Exif\Model\ExifNumericList;
use MagicSunday\ImageMeta\Exif\Model\ExifRational;
use MagicSunday\ImageMeta\Exif\Model\ExifRationalList;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Exif\Model\ParsedExif;
use MagicSunday\ImageMeta\Model\Dng\DngTag;
use MagicSunday\ImageMeta\Parse\Tiff\TiffConst;
use MagicSunday\ImageMeta\Parse\Tiff\TiffExifParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function pack;
use function str_pad;
use function strlen;
use function substr;

/**
 * Exercises TIFF EXIF parsing for fixed-length and inline value cases.
 * It verifies correct handling of inline vs. offset storage for various tag types.
 * The tests include rational, numeric list, and ASCII values across endian modes.
 * This keeps fixed-size parsing paths stable for common EXIF payloads.
 *
 * @internal
 */
#[CoversClass(TiffExifParser::class)]
#[UsesClass(MemoryBuffer::class)]
#[UsesClass(BitMask::class)]
#[UsesClass(Endian::class)]
#[UsesClass(UInt64::class)]
#[UsesClass(Unpack::class)]
#[UsesClass(ParseError::class)]
#[UsesClass(ExifRational::class)]
#[UsesClass(ExifRationalList::class)]
#[UsesClass(ExifNumericList::class)]
#[UsesClass(Ifd::class)]
#[UsesClass(IfdEntry::class)]
#[UsesClass(ParsedExif::class)]
#[UsesClass(TiffConst::class)]
#[UsesClass(DngTag::class)]
final class TiffExifParserFixedLengthTest extends TestCase
{
    /**
     * Uses fixed-length EXIF/GPS tags with valid component counts from the data provider.
     * Verifies the parser accepts these entries without raising a ParseError.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('validFixedLengthTagProvider')]
    public function acceptsFixedLengthTagsWithValidCounts(
        int $tag,
        int $type,
        int $count,
        string $valueBytes,
    ): void {
        $blob = $this->buildClassicTiffWithEntry($tag, $type, $count, $valueBytes);

        $reader = new TiffExifParser();

        $reader->parseFromBlob($blob);

        $this->addToAssertionCount(1);
    }

    /**
     * @return array<string, array{0:int,1:int,2:int,3:string}>
     */
    public static function validFixedLengthTagProvider(): array
    {
        return [
            'ExifVersion UNDEFINED count 4' => [
                ExifTag::EXIF_VERSION,
                TiffConst::TYPE_UNDEFINED,
                4,
                '0300',
            ],
            'ExifVersion ASCII count 4' => [
                ExifTag::EXIF_VERSION,
                TiffConst::TYPE_ASCII,
                4,
                "030\0",
            ],
            'FlashpixVersion UNDEFINED count 4' => [
                ExifTag::FLASHPIX_VERSION,
                TiffConst::TYPE_UNDEFINED,
                4,
                '0100',
            ],
            'FlashpixVersion ASCII count 4' => [
                ExifTag::FLASHPIX_VERSION,
                TiffConst::TYPE_ASCII,
                4,
                "010\0",
            ],
            'ComponentsConfiguration count 4' => [
                ExifTag::COMPONENTS_CONFIGURATION,
                TiffConst::TYPE_UNDEFINED,
                4,
                "\x01\x02\x03\x00",
            ],
            'GPSVersionID count 4' => [
                ExifTag::GPS_VERSION_ID,
                TiffConst::TYPE_BYTE,
                4,
                "\x02\x03\x00\x00",
            ],
            'LensSpecification count 4' => [
                ExifTag::LENS_SPECIFICATION,
                TiffConst::TYPE_RATIONAL,
                4,
                "\x00\x00\x00\x1C\x00\x00\x00\x01\x00\x00\x00\x46\x00\x00\x00\x01\x00\x00\x00\x18\x00\x00\x00\x0A\x00\x00\x00\x38\x00\x00\x00\x0A",
            ],
            'WhitePoint count 2' => [
                ExifTag::WHITE_POINT,
                TiffConst::TYPE_RATIONAL,
                2,
                str_repeat("\x00\x00\x00\x01\x00\x00\x00\x01", 2),
            ],
            'PrimaryChromaticities count 6' => [
                ExifTag::PRIMARY_CHROMATICITIES,
                TiffConst::TYPE_RATIONAL,
                6,
                str_repeat("\x00\x00\x00\x01\x00\x00\x00\x01", 6),
            ],
            'YCbCrCoefficients count 3' => [
                ExifTag::YCBCR_COEFFICIENTS,
                TiffConst::TYPE_RATIONAL,
                3,
                str_repeat("\x00\x00\x00\x01\x00\x00\x00\x01", 3),
            ],
            'ReferenceBlackWhite count 6' => [
                ExifTag::REFERENCE_BLACK_WHITE,
                TiffConst::TYPE_RATIONAL,
                6,
                str_repeat("\x00\x00\x00\x01\x00\x00\x00\x01", 6),
            ],
            'GPSTimeStamp count 3' => [
                ExifTag::GPS_TIME_STAMP,
                TiffConst::TYPE_RATIONAL,
                3,
                "\x00\x00\x00\x0C\x00\x00\x00\x01\x00\x00\x00\x22\x00\x00\x00\x01\x00\x00\x00\x38\x00\x00\x00\x01",
            ],
            'GPSDateStamp count 11' => [
                ExifTag::GPS_DATE_STAMP,
                TiffConst::TYPE_ASCII,
                11,
                "2024:05:06\0",
            ],
            'GPSStatus count 2' => [
                ExifTag::GPS_STATUS,
                TiffConst::TYPE_ASCII,
                2,
                "A\0",
            ],
            'GPSMeasureMode count 2' => [
                ExifTag::GPS_MEASURE_MODE,
                TiffConst::TYPE_ASCII,
                2,
                "2\0",
            ],
            'GPSSpeedRef count 2' => [
                ExifTag::GPS_SPEED_REF,
                TiffConst::TYPE_ASCII,
                2,
                "K\0",
            ],
            'GPSDOP count 1' => [
                ExifTag::GPS_DOP,
                TiffConst::TYPE_RATIONAL,
                1,
                "\x00\x00\x00\x19\x00\x00\x00\x0A",
            ],
            'GPSSpeed count 1' => [
                ExifTag::GPS_SPEED,
                TiffConst::TYPE_RATIONAL,
                1,
                "\x00\x00\x00\x46\x00\x00\x00\x01",
            ],
            'GPSTrackRef count 2' => [
                ExifTag::GPS_TRACK_REF,
                TiffConst::TYPE_ASCII,
                2,
                "T\0",
            ],
            'GPSTrack count 1' => [
                ExifTag::GPS_TRACK,
                TiffConst::TYPE_RATIONAL,
                1,
                "\x00\x00\x00\x7B\x00\x00\x00\x01",
            ],
            'GPSImgDirectionRef count 2' => [
                ExifTag::GPS_IMG_DIRECTION_REF,
                TiffConst::TYPE_ASCII,
                2,
                "M\0",
            ],
            'GPSImgDirection count 1' => [
                ExifTag::GPS_IMG_DIRECTION,
                TiffConst::TYPE_RATIONAL,
                1,
                "\x00\x00\x00\xB4\x00\x00\x00\x01",
            ],
            'GPSDestBearingRef count 2' => [
                ExifTag::GPS_DEST_BEARING_REF,
                TiffConst::TYPE_ASCII,
                2,
                "T\0",
            ],
            'GPSDestBearing count 1' => [
                ExifTag::GPS_DEST_BEARING,
                TiffConst::TYPE_RATIONAL,
                1,
                "\x00\x00\x00\x2D\x00\x00\x00\x01",
            ],
            'GPSDestDistanceRef count 2' => [
                ExifTag::GPS_DEST_DISTANCE_REF,
                TiffConst::TYPE_ASCII,
                2,
                "K\0",
            ],
            'GPSDestDistance count 1' => [
                ExifTag::GPS_DEST_DISTANCE,
                TiffConst::TYPE_RATIONAL,
                1,
                "\x00\x00\x00\x2A\x00\x00\x00\x01",
            ],
            'GPSMapDatum ASCII count 7' => [
                ExifTag::GPS_MAP_DATUM,
                TiffConst::TYPE_ASCII,
                7,
                "WGS-84\0",
            ],
            'GPSSatellites ASCII count 3' => [
                ExifTag::GPS_SATELLITES,
                TiffConst::TYPE_ASCII,
                3,
                "05\0",
            ],
            'FileSource count 1' => [
                ExifTag::FILE_SOURCE,
                TiffConst::TYPE_UNDEFINED,
                1,
                "\x03",
            ],
            'SceneType count 1' => [
                ExifTag::SCENE_TYPE,
                TiffConst::TYPE_UNDEFINED,
                1,
                "\x01",
            ],
            'SubjectLocation count 2' => [
                ExifTag::SUBJECT_LOCATION,
                TiffConst::TYPE_SHORT,
                2,
                "\x00\x64\x00\xC8",
            ],
            'GPSAltitudeRef count 1' => [
                ExifTag::GPS_ALTITUDE_REF,
                TiffConst::TYPE_BYTE,
                1,
                "\x02",
            ],
            'GPSDifferential count 1' => [
                ExifTag::GPS_DIFFERENTIAL,
                TiffConst::TYPE_SHORT,
                1,
                "\x01\x00",
            ],
            'GPSHPositioningError count 1' => [
                ExifTag::GPS_H_POSITIONING_ERROR,
                TiffConst::TYPE_RATIONAL,
                1,
                "\x00\x00\x00\x03\x00\x00\x00\x02",
            ],
            'CFALayout count 1' => [
                DngTag::CFA_LAYOUT,
                TiffConst::TYPE_SHORT,
                1,
                "\x01\x00",
            ],
            'BaselineExposure count 1' => [
                DngTag::BASELINE_EXPOSURE,
                TiffConst::TYPE_SRATIONAL,
                1,
                "\x00\x00\x00\x01\x00\x00\x00\x01",
            ],
            'BayerGreenSplit count 1' => [
                DngTag::BAYER_GREEN_SPLIT,
                TiffConst::TYPE_LONG,
                1,
                "\x00\x00\x00\x00",
            ],
            'MakerNoteSafety count 1' => [
                DngTag::MAKER_NOTE_SAFETY,
                TiffConst::TYPE_SHORT,
                1,
                "\x01\x00",
            ],
            'CalibrationIlluminant1 count 1' => [
                DngTag::CALIBRATION_ILLUMINANT_1,
                TiffConst::TYPE_SHORT,
                1,
                "\x15\x00",
            ],
            'CalibrationIlluminant2 count 1' => [
                DngTag::CALIBRATION_ILLUMINANT_2,
                TiffConst::TYPE_SHORT,
                1,
                "\x17\x00",
            ],
            'RawDataUniqueID count 16' => [
                DngTag::RAW_DATA_UNIQUE_ID,
                TiffConst::TYPE_BYTE,
                16,
                str_repeat("\xAB", 16),
            ],
            'NoiseReductionApplied count 1' => [
                DngTag::NOISE_REDUCTION_APPLIED,
                TiffConst::TYPE_RATIONAL,
                1,
                "\x00\x00\x00\x01\x00\x00\x00\x02",
            ],
            'ProfileEmbedPolicy count 1' => [
                DngTag::PROFILE_EMBED_POLICY,
                TiffConst::TYPE_LONG,
                1,
                "\x00\x00\x00\x00",
            ],
            'BaselineExposureOffset count 1' => [
                DngTag::BASELINE_EXPOSURE_OFFSET,
                TiffConst::TYPE_RATIONAL,
                1,
                "\x00\x00\x00\x01\x00\x00\x00\x01",
            ],
            'RawToPreviewGain count 1' => [
                DngTag::RAW_TO_PREVIEW_GAIN,
                TiffConst::TYPE_DOUBLE,
                1,
                pack('e', 1.0),
            ],
            'DefaultUserCrop count 4' => [
                DngTag::DEFAULT_USER_CROP,
                TiffConst::TYPE_RATIONAL,
                4,
                // Top=0/1, Left=0/1, Bottom=1/1, Right=1/1
                "\x00\x00\x00\x00\x00\x00\x00\x01"
                . "\x00\x00\x00\x00\x00\x00\x00\x01"
                . "\x00\x00\x00\x01\x00\x00\x00\x01"
                . "\x00\x00\x00\x01\x00\x00\x00\x01",
            ],
            'DepthFormat count 1' => [
                DngTag::DEPTH_FORMAT,
                TiffConst::TYPE_SHORT,
                1,
                "\x01\x00",
            ],
            'DepthNear count 1' => [
                DngTag::DEPTH_NEAR,
                TiffConst::TYPE_RATIONAL,
                1,
                "\x00\x00\x00\x01\x00\x00\x00\x01",
            ],
            'DepthFar count 1' => [
                DngTag::DEPTH_FAR,
                TiffConst::TYPE_RATIONAL,
                1,
                "\x00\x00\x00\x01\x00\x00\x00\x01",
            ],
            'DepthUnits count 1' => [
                DngTag::DEPTH_UNITS,
                TiffConst::TYPE_SHORT,
                1,
                "\x01\x00",
            ],
            'DepthMeasureType count 1' => [
                DngTag::DEPTH_MEASURE_TYPE,
                TiffConst::TYPE_SHORT,
                1,
                "\x01\x00",
            ],
        ];
    }

    /**
     * Rejects BaselineExposureOffset with wrong type (LONG instead of RATIONAL).
     *
     * @return void
     */
    #[Test]
    public function rejectsBaselineExposureOffsetWrongType(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1317);

        $blob = $this->buildClassicTiffWithEntry(
            DngTag::BASELINE_EXPOSURE_OFFSET,
            TiffConst::TYPE_LONG,
            1,
            "\x00\x00\x00\x01",
        );

        (new TiffExifParser())->parseFromBlob($blob);
    }

    /**
     * Rejects BaselineExposureOffset with wrong count (2 instead of 1).
     *
     * @return void
     */
    #[Test]
    public function rejectsBaselineExposureOffsetWrongCount(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1318);

        $blob = $this->buildClassicTiffWithEntry(
            DngTag::BASELINE_EXPOSURE_OFFSET,
            TiffConst::TYPE_RATIONAL,
            2,
            str_repeat("\x00\x00\x00\x01\x00\x00\x00\x01", 2),
        );

        (new TiffExifParser())->parseFromBlob($blob);
    }

    /**
     * Rejects RawToPreviewGain with wrong type (RATIONAL instead of DOUBLE).
     *
     * @return void
     */
    #[Test]
    public function rejectsRawToPreviewGainWrongType(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1317);

        $blob = $this->buildClassicTiffWithEntry(
            DngTag::RAW_TO_PREVIEW_GAIN,
            TiffConst::TYPE_RATIONAL,
            1,
            "\x00\x00\x00\x01\x00\x00\x00\x01",
        );

        (new TiffExifParser())->parseFromBlob($blob);
    }

    /**
     * Rejects RawDataUniqueID with wrong count (15 instead of 16).
     *
     * @return void
     */
    #[Test]
    public function rejectsRawDataUniqueIdWrongCount(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1318);

        $blob = $this->buildClassicTiffWithEntry(
            DngTag::RAW_DATA_UNIQUE_ID,
            TiffConst::TYPE_BYTE,
            15,
            str_repeat("\xAB", 15),
        );

        (new TiffExifParser())->parseFromBlob($blob);
    }

    /**
     * Rejects RawDataUniqueID with wrong type (UNDEFINED instead of BYTE).
     *
     * @return void
     */
    #[Test]
    public function rejectsRawDataUniqueIdWrongType(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1317);

        $blob = $this->buildClassicTiffWithEntry(
            DngTag::RAW_DATA_UNIQUE_ID,
            TiffConst::TYPE_UNDEFINED,
            16,
            str_repeat("\xAB", 16),
        );

        (new TiffExifParser())->parseFromBlob($blob);
    }

    /**
     * Rejects RawToPreviewGain with wrong count (2 instead of 1).
     *
     * @return void
     */
    #[Test]
    public function rejectsRawToPreviewGainWrongCount(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1318);

        $blob = $this->buildClassicTiffWithEntry(
            DngTag::RAW_TO_PREVIEW_GAIN,
            TiffConst::TYPE_DOUBLE,
            2,
            pack('e', 1.0) . pack('e', 2.0),
        );

        (new TiffExifParser())->parseFromBlob($blob);
    }

    /**
     * Rejects GPSHPositioningError with wrong type (LONG instead of RATIONAL).
     *
     * @return void
     */
    #[Test]
    public function rejectsGpsHPositioningErrorWrongType(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1317);

        $blob = $this->buildClassicTiffWithEntry(
            ExifTag::GPS_H_POSITIONING_ERROR,
            TiffConst::TYPE_LONG,
            1,
            "\x00\x00\x00\x01",
        );

        (new TiffExifParser())->parseFromBlob($blob);
    }

    /**
     * Rejects GPSHPositioningError with wrong count (2 instead of 1).
     *
     * @return void
     */
    #[Test]
    public function rejectsGpsHPositioningErrorWrongCount(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1318);

        $blob = $this->buildClassicTiffWithEntry(
            ExifTag::GPS_H_POSITIONING_ERROR,
            TiffConst::TYPE_RATIONAL,
            2,
            str_repeat("\x00\x00\x00\x01\x00\x00\x00\x01", 2),
        );

        (new TiffExifParser())->parseFromBlob($blob);
    }

    /**
     * Rejects GPSSpeedRef with wrong type (BYTE instead of ASCII).
     *
     * @return void
     */
    #[Test]
    public function rejectsGpsSpeedRefWrongType(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1317);

        $blob = $this->buildClassicTiffWithEntry(
            ExifTag::GPS_SPEED_REF,
            TiffConst::TYPE_BYTE,
            2,
            "K\0",
        );

        (new TiffExifParser())->parseFromBlob($blob);
    }

    /**
     * Rejects GPSSpeedRef with wrong count (1 instead of 2).
     *
     * @return void
     */
    #[Test]
    public function rejectsGpsSpeedRefWrongCount(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1318);

        $blob = $this->buildClassicTiffWithEntry(
            ExifTag::GPS_SPEED_REF,
            TiffConst::TYPE_ASCII,
            1,
            'K',
        );

        (new TiffExifParser())->parseFromBlob($blob);
    }

    /**
     * Rejects GPSSpeed with wrong type (LONG instead of RATIONAL).
     *
     * @return void
     */
    #[Test]
    public function rejectsGpsSpeedWrongType(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1317);

        $blob = $this->buildClassicTiffWithEntry(
            ExifTag::GPS_SPEED,
            TiffConst::TYPE_LONG,
            1,
            "\x00\x00\x00\x01",
        );

        (new TiffExifParser())->parseFromBlob($blob);
    }

    /**
     * Rejects GPSSpeed with wrong count (2 instead of 1).
     *
     * @return void
     */
    #[Test]
    public function rejectsGpsSpeedWrongCount(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1318);

        $blob = $this->buildClassicTiffWithEntry(
            ExifTag::GPS_SPEED,
            TiffConst::TYPE_RATIONAL,
            2,
            str_repeat("\x00\x00\x00\x01\x00\x00\x00\x01", 2),
        );

        (new TiffExifParser())->parseFromBlob($blob);
    }

    /**
     * Rejects GPSDOP with wrong type (LONG instead of RATIONAL).
     *
     * @return void
     */
    #[Test]
    public function rejectsGpsDopWrongType(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1317);

        $blob = $this->buildClassicTiffWithEntry(
            ExifTag::GPS_DOP,
            TiffConst::TYPE_LONG,
            1,
            "\x00\x00\x00\x01",
        );

        (new TiffExifParser())->parseFromBlob($blob);
    }

    /**
     * Rejects GPSDOP with wrong count (2 instead of 1).
     *
     * @return void
     */
    #[Test]
    public function rejectsGpsDopWrongCount(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1318);

        $blob = $this->buildClassicTiffWithEntry(
            ExifTag::GPS_DOP,
            TiffConst::TYPE_RATIONAL,
            2,
            str_repeat("\x00\x00\x00\x01\x00\x00\x00\x01", 2),
        );

        (new TiffExifParser())->parseFromBlob($blob);
    }

    /**
     * Rejects GPSStatus/GPSMeasureMode tags when encoded with non-ASCII TIFF type.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('provideGpsStatusAndMeasureModeTags')]
    public function rejectsGpsStatusAndMeasureModeWrongType(int $tag): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1317);

        $blob = $this->buildClassicTiffWithEntry(
            $tag,
            TiffConst::TYPE_BYTE,
            2,
            "A\0",
        );

        (new TiffExifParser())->parseFromBlob($blob);
    }

    /**
     * Rejects GPSStatus/GPSMeasureMode tags when encoded with wrong component count.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('provideGpsStatusAndMeasureModeTags')]
    public function rejectsGpsStatusAndMeasureModeWrongCount(int $tag): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1318);

        $blob = $this->buildClassicTiffWithEntry(
            $tag,
            TiffConst::TYPE_ASCII,
            1,
            'A',
        );

        (new TiffExifParser())->parseFromBlob($blob);
    }

    /**
     * @return iterable<string, array{0:int}>
     */
    public static function provideGpsStatusAndMeasureModeTags(): iterable
    {
        yield 'GPSStatus' => [ExifTag::GPS_STATUS];
        yield 'GPSMeasureMode' => [ExifTag::GPS_MEASURE_MODE];
    }

    /**
     * Rejects GPSDestDistanceRef with wrong type (BYTE instead of ASCII).
     *
     * @return void
     */
    #[Test]
    public function rejectsGpsDestDistanceRefWrongType(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1317);

        $blob = $this->buildClassicTiffWithEntry(
            ExifTag::GPS_DEST_DISTANCE_REF,
            TiffConst::TYPE_BYTE,
            2,
            "K\0",
        );

        (new TiffExifParser())->parseFromBlob($blob);
    }

    /**
     * Rejects GPSDestDistanceRef with wrong count (1 instead of 2).
     *
     * @return void
     */
    #[Test]
    public function rejectsGpsDestDistanceRefWrongCount(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1318);

        $blob = $this->buildClassicTiffWithEntry(
            ExifTag::GPS_DEST_DISTANCE_REF,
            TiffConst::TYPE_ASCII,
            1,
            'K',
        );

        (new TiffExifParser())->parseFromBlob($blob);
    }

    /**
     * Rejects GPSDestDistance with wrong type (LONG instead of RATIONAL).
     *
     * @return void
     */
    #[Test]
    public function rejectsGpsDestDistanceWrongType(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1317);

        $blob = $this->buildClassicTiffWithEntry(
            ExifTag::GPS_DEST_DISTANCE,
            TiffConst::TYPE_LONG,
            1,
            "\x00\x00\x00\x01",
        );

        (new TiffExifParser())->parseFromBlob($blob);
    }

    /**
     * Rejects GPSDestDistance with wrong count (2 instead of 1).
     *
     * @return void
     */
    #[Test]
    public function rejectsGpsDestDistanceWrongCount(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1318);

        $blob = $this->buildClassicTiffWithEntry(
            ExifTag::GPS_DEST_DISTANCE,
            TiffConst::TYPE_RATIONAL,
            2,
            str_repeat("\x00\x00\x00\x01\x00\x00\x00\x01", 2),
        );

        (new TiffExifParser())->parseFromBlob($blob);
    }

    /**
     * Rejects GPSMapDatum when encoded with non-ASCII TIFF type.
     *
     * @param int    $type
     * @param int    $count
     * @param string $valueBytes
     *
     * @return void
     */
    #[Test]
    #[DataProvider('provideInvalidGpsMapDatumTypes')]
    public function rejectsGpsMapDatumWithWrongType(int $type, int $count, string $valueBytes): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1317);
        $this->expectExceptionMessage('GPSMapDatum must use TIFF type ASCII');

        $blob = $this->buildClassicTiffWithEntry(
            ExifTag::GPS_MAP_DATUM,
            $type,
            $count,
            $valueBytes,
        );

        (new TiffExifParser())->parseFromBlob($blob);
    }

    /**
     * @return iterable<string, array{0:int,1:int,2:string}>
     */
    public static function provideInvalidGpsMapDatumTypes(): iterable
    {
        yield 'SHORT type' => [
            TiffConst::TYPE_SHORT,
            1,
            "\x2A\x00",
        ];

        yield 'UNDEFINED type' => [
            TiffConst::TYPE_UNDEFINED,
            6,
            'WGS-84',
        ];
    }

    /**
     * Rejects GPSSatellites when encoded with non-ASCII TIFF type.
     *
     * @param int    $type
     * @param int    $count
     * @param string $valueBytes
     *
     * @return void
     */
    #[Test]
    #[DataProvider('provideInvalidGpsSatellitesTypes')]
    public function rejectsGpsSatellitesWithWrongType(int $type, int $count, string $valueBytes): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1317);
        $this->expectExceptionMessage('GPSSatellites must use TIFF type ASCII');

        $blob = $this->buildClassicTiffWithEntry(
            ExifTag::GPS_SATELLITES,
            $type,
            $count,
            $valueBytes,
        );

        (new TiffExifParser())->parseFromBlob($blob);
    }

    /**
     * @return iterable<string, array{0:int,1:int,2:string}>
     */
    public static function provideInvalidGpsSatellitesTypes(): iterable
    {
        yield 'SHORT type' => [
            TiffConst::TYPE_SHORT,
            1,
            "\x05\x00",
        ];

        yield 'UNDEFINED type' => [
            TiffConst::TYPE_UNDEFINED,
            3,
            "05\0",
        ];
    }

    /**
     * Rejects GPS bearing reference tags when encoded with non-ASCII TIFF type.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('provideGpsBearingRefTags')]
    public function rejectsGpsBearingReferenceTagsWithWrongType(int $tag): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1317);

        $blob = $this->buildClassicTiffWithEntry(
            $tag,
            TiffConst::TYPE_BYTE,
            2,
            "T\0",
        );

        (new TiffExifParser())->parseFromBlob($blob);
    }

    /**
     * Rejects GPS bearing reference tags when encoded with wrong component count.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('provideGpsBearingRefTags')]
    public function rejectsGpsBearingReferenceTagsWithWrongCount(int $tag): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1318);

        $blob = $this->buildClassicTiffWithEntry(
            $tag,
            TiffConst::TYPE_ASCII,
            1,
            'T',
        );

        (new TiffExifParser())->parseFromBlob($blob);
    }

    /**
     * @return iterable<string, array{0:int}>
     */
    public static function provideGpsBearingRefTags(): iterable
    {
        yield 'GPSTrackRef' => [ExifTag::GPS_TRACK_REF];
        yield 'GPSImgDirectionRef' => [ExifTag::GPS_IMG_DIRECTION_REF];
        yield 'GPSDestBearingRef' => [ExifTag::GPS_DEST_BEARING_REF];
    }

    /**
     * Rejects GPS bearing value tags when encoded with non-RATIONAL TIFF type.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('provideGpsBearingValueTags')]
    public function rejectsGpsBearingValueTagsWithWrongType(int $tag): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1317);

        $blob = $this->buildClassicTiffWithEntry(
            $tag,
            TiffConst::TYPE_LONG,
            1,
            "\x00\x00\x00\x01",
        );

        (new TiffExifParser())->parseFromBlob($blob);
    }

    /**
     * Rejects GPS bearing value tags when encoded with wrong component count.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('provideGpsBearingValueTags')]
    public function rejectsGpsBearingValueTagsWithWrongCount(int $tag): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1318);

        $blob = $this->buildClassicTiffWithEntry(
            $tag,
            TiffConst::TYPE_RATIONAL,
            2,
            str_repeat("\x00\x00\x00\x01\x00\x00\x00\x01", 2),
        );

        (new TiffExifParser())->parseFromBlob($blob);
    }

    /**
     * @return iterable<string, array{0:int}>
     */
    public static function provideGpsBearingValueTags(): iterable
    {
        yield 'GPSTrack' => [ExifTag::GPS_TRACK];
        yield 'GPSImgDirection' => [ExifTag::GPS_IMG_DIRECTION];
        yield 'GPSDestBearing' => [ExifTag::GPS_DEST_BEARING];
    }

    private function buildClassicTiffWithEntry(int $tag, int $type, int $count, string $valueBytes): string
    {
        $ifdOffset     = 8;
        $entryCount    = 3; // ImageWidth + ImageLength + the requested tag
        $componentSize = $this->bytesPerComponent($type);
        $dataSize      = $componentSize * $count;

        if (strlen($valueBytes) < $dataSize) {
            $valueBytes = str_pad($valueBytes, $dataSize, "\0");
        }

        // Build entries as [tag => binary] sorted ascending by tag ID
        $entries = [];

        // ImageWidth SHORT[1] = 100
        $entries[ExifTag::IMAGE_WIDTH] = pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0);

        // ImageLength SHORT[1] = 100
        $entries[ExifTag::IMAGE_LENGTH] = pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0);

        // Requested tag — offset placeholder if out-of-line
        $outOfLine = $dataSize > 4;
        if ($outOfLine) {
            $valueOffset   = $ifdOffset + 2 + ($entryCount * 12) + 4;
            $entries[$tag] = pack('v', $tag) . pack('v', $type) . pack('V', $count) . pack('V', $valueOffset);
        } else {
            $inlineBytes   = str_pad(substr($valueBytes, 0, $dataSize), 4, "\0");
            $entries[$tag] = pack('v', $tag) . pack('v', $type) . pack('V', $count) . $inlineBytes;
        }

        ksort($entries);

        $blob = 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . pack('v', $entryCount);

        foreach ($entries as $entry) {
            $blob .= $entry;
        }

        $blob .= pack('V', 0); // next IFD

        if ($outOfLine) {
            $blob .= substr($valueBytes, 0, $dataSize);
        }

        return $blob;
    }

    private function bytesPerComponent(int $type): int
    {
        return match ($type) {
            TiffConst::TYPE_ASCII,
            TiffConst::TYPE_BYTE,
            TiffConst::TYPE_UNDEFINED => 1,
            TiffConst::TYPE_SHORT     => 2,
            TiffConst::TYPE_LONG,
            TiffConst::TYPE_SLONG => 4,
            TiffConst::TYPE_RATIONAL,
            TiffConst::TYPE_SRATIONAL => 8,
            default                   => 1,
        };
    }
}
