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
use MagicSunday\ImageMeta\Parse\Tiff\DngValueNormalizer;
use MagicSunday\ImageMeta\Parse\Tiff\MakerNoteDispatcher;
use MagicSunday\ImageMeta\Parse\Tiff\TiffBinaryReader;
use MagicSunday\ImageMeta\Parse\Tiff\TiffConst;
use MagicSunday\ImageMeta\Parse\Tiff\TiffExifParser;
use MagicSunday\ImageMeta\Parse\Tiff\TiffExifTagValidator;
use MagicSunday\ImageMeta\Parse\Tiff\TiffIfdTraverser;
use MagicSunday\ImageMeta\Parse\Tiff\TiffJpegThumbnailValidator;
use MagicSunday\ImageMeta\Parse\Tiff\TiffOffsetValidator;
use MagicSunday\ImageMeta\Parse\Tiff\TiffValueDecoder;
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
#[UsesClass(BitMask::class)]
#[UsesClass(DngTag::class)]
#[UsesClass(DngValueNormalizer::class)]
#[UsesClass(Endian::class)]
#[UsesClass(ExifNumericList::class)]
#[UsesClass(ExifRational::class)]
#[UsesClass(ExifRationalList::class)]
#[UsesClass(Ifd::class)]
#[UsesClass(IfdEntry::class)]
#[UsesClass(MakerNoteDispatcher::class)]
#[UsesClass(MemoryBuffer::class)]
#[UsesClass(ParsedExif::class)]
#[UsesClass(ParseError::class)]
#[UsesClass(TiffBinaryReader::class)]
#[UsesClass(TiffConst::class)]
#[UsesClass(TiffExifTagValidator::class)]
#[UsesClass(TiffIfdTraverser::class)]
#[UsesClass(TiffJpegThumbnailValidator::class)]
#[UsesClass(TiffOffsetValidator::class)]
#[UsesClass(TiffValueDecoder::class)]
#[UsesClass(UInt64::class)]
#[UsesClass(Unpack::class)]
final class TiffExifParserFixedLengthTest extends TestCase
{
    /**
     * Uses fixed-length EXIF/GPS tags with valid component counts from the data provider.
     * Verifies the parser accepts these entries without raising a ParseError.
     */
    #[Test]
    #[DataProvider('validFixedLengthTagProvider')]
    public function acceptsFixedLengthTagsWithValidCounts(
        int $tag,
        int $type,
        int $count,
        string $valueBytes,
    ): void {
        $blob   = $this->buildClassicTiffWithEntry($tag, $type, $count, $valueBytes);

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
     * Tolerates BaselineExposureOffset with wrong type (LONG instead of RATIONAL).
     * Postel's Law: TIFF type mismatches are accepted.
     */
    #[Test]
    public function toleratesBaselineExposureOffsetWrongType(): void
    {
        $blob = $this->buildClassicTiffWithEntry(
            DngTag::BASELINE_EXPOSURE_OFFSET,
            TiffConst::TYPE_LONG,
            1,
            "\x00\x00\x00\x01",
        );

        try {
            (new TiffExifParser())->parseFromBlob($blob);
        } catch (ParseError $e) {
            self::assertNotSame(1317, $e->getCode(), 'Type mismatch must not be rejected');

            return;
        }

        $this->addToAssertionCount(1);
    }

    /**
     * Tolerates BaselineExposureOffset with wrong count (2 instead of 1).
     * Postel's Law: fixed-length byte-count deviations are accepted.
     */
    #[Test]
    public function toleratesBaselineExposureOffsetWrongCount(): void
    {
        $blob = $this->buildClassicTiffWithEntry(
            DngTag::BASELINE_EXPOSURE_OFFSET,
            TiffConst::TYPE_RATIONAL,
            2,
            str_repeat("\x00\x00\x00\x01\x00\x00\x00\x01", 2),
        );

        try {
            (new TiffExifParser())->parseFromBlob($blob);
        } catch (ParseError $e) {
            self::assertNotSame(1318, $e->getCode(), 'Byte-count deviation must not be rejected');

            return;
        }

        $this->addToAssertionCount(1);
    }

    /**
     * Tolerates RawToPreviewGain with wrong type (RATIONAL instead of DOUBLE).
     * Postel's Law: TIFF type mismatches are accepted.
     */
    #[Test]
    public function toleratesRawToPreviewGainWrongType(): void
    {
        $blob = $this->buildClassicTiffWithEntry(
            DngTag::RAW_TO_PREVIEW_GAIN,
            TiffConst::TYPE_RATIONAL,
            1,
            "\x00\x00\x00\x01\x00\x00\x00\x01",
        );

        try {
            (new TiffExifParser())->parseFromBlob($blob);
        } catch (ParseError $e) {
            self::assertNotSame(1317, $e->getCode(), 'Type mismatch must not be rejected');

            return;
        }

        $this->addToAssertionCount(1);
    }

    /**
     * Tolerates RawDataUniqueID with wrong count (15 instead of 16).
     * Postel's Law: fixed-length byte-count deviations are accepted.
     */
    #[Test]
    public function toleratesRawDataUniqueIdWrongCount(): void
    {
        $blob = $this->buildClassicTiffWithEntry(
            DngTag::RAW_DATA_UNIQUE_ID,
            TiffConst::TYPE_BYTE,
            15,
            str_repeat("\xAB", 15),
        );

        try {
            (new TiffExifParser())->parseFromBlob($blob);
        } catch (ParseError $e) {
            self::assertNotSame(1318, $e->getCode(), 'Byte-count deviation must not be rejected');

            return;
        }

        $this->addToAssertionCount(1);
    }

    /**
     * Tolerates RawDataUniqueID with wrong type (UNDEFINED instead of BYTE).
     * Postel's Law: TIFF type mismatches are accepted.
     */
    #[Test]
    public function toleratesRawDataUniqueIdWrongType(): void
    {
        $blob = $this->buildClassicTiffWithEntry(
            DngTag::RAW_DATA_UNIQUE_ID,
            TiffConst::TYPE_UNDEFINED,
            16,
            str_repeat("\xAB", 16),
        );

        try {
            (new TiffExifParser())->parseFromBlob($blob);
        } catch (ParseError $e) {
            self::assertNotSame(1317, $e->getCode(), 'Type mismatch must not be rejected');

            return;
        }

        $this->addToAssertionCount(1);
    }

    /**
     * Tolerates RawToPreviewGain with wrong count (2 instead of 1).
     * Postel's Law: fixed-length byte-count deviations are accepted.
     */
    #[Test]
    public function toleratesRawToPreviewGainWrongCount(): void
    {
        $blob = $this->buildClassicTiffWithEntry(
            DngTag::RAW_TO_PREVIEW_GAIN,
            TiffConst::TYPE_DOUBLE,
            2,
            pack('e', 1.0) . pack('e', 2.0),
        );

        try {
            (new TiffExifParser())->parseFromBlob($blob);
        } catch (ParseError $e) {
            self::assertNotSame(1318, $e->getCode(), 'Byte-count deviation must not be rejected');

            return;
        }

        $this->addToAssertionCount(1);
    }

    /**
     * Accepts GPSHPositioningError regardless of TIFF type (Postel's Law).
     */
    #[Test]
    public function acceptsGpsHPositioningErrorWithNonRationalType(): void
    {
        try {
            (new TiffExifParser())->parseFromBlob(
                $this->buildClassicTiffWithEntry(
                    ExifTag::GPS_H_POSITIONING_ERROR,
                    TiffConst::TYPE_LONG,
                    1,
                    "\x00\x00\x00\x01",
                ),
            );
        } catch (ParseError $e) {
            self::assertNotSame(1317, $e->getCode(), 'Type check must not reject non-RATIONAL GPSHPositioningError');

            return;
        }

        $this->addToAssertionCount(1);
    }

    /**
     * Tolerates GPSSpeedRef with wrong type (BYTE instead of ASCII).
     * Postel's Law: TIFF type mismatches are accepted.
     */
    #[Test]
    public function toleratesGpsSpeedRefWrongType(): void
    {
        $blob = $this->buildClassicTiffWithEntry(
            ExifTag::GPS_SPEED_REF,
            TiffConst::TYPE_BYTE,
            2,
            "K\0",
        );

        try {
            (new TiffExifParser())->parseFromBlob($blob);
        } catch (ParseError $e) {
            self::assertNotSame(1317, $e->getCode(), 'Type mismatch must not be rejected');

            return;
        }

        $this->addToAssertionCount(1);
    }

    /**
     * Tolerates GPSSpeedRef with wrong count (1 instead of 2).
     * Postel's Law: fixed-length byte-count deviations are accepted.
     */
    #[Test]
    public function toleratesGpsSpeedRefWrongCount(): void
    {
        $blob = $this->buildClassicTiffWithEntry(
            ExifTag::GPS_SPEED_REF,
            TiffConst::TYPE_ASCII,
            1,
            'K',
        );

        try {
            (new TiffExifParser())->parseFromBlob($blob);
        } catch (ParseError $e) {
            self::assertNotSame(1318, $e->getCode(), 'Byte-count deviation must not be rejected');

            return;
        }

        $this->addToAssertionCount(1);
    }

    /**
     * Accepts GPSSpeed regardless of TIFF type (Postel's Law).
     */
    #[Test]
    public function acceptsGpsSpeedWithNonRationalType(): void
    {
        try {
            (new TiffExifParser())->parseFromBlob(
                $this->buildClassicTiffWithEntry(
                    ExifTag::GPS_SPEED,
                    TiffConst::TYPE_LONG,
                    1,
                    "\x00\x00\x00\x01",
                ),
            );
        } catch (ParseError $e) {
            self::assertNotSame(1317, $e->getCode(), 'Type check must not reject non-RATIONAL GPSSpeed');

            return;
        }

        $this->addToAssertionCount(1);
    }

    /**
     * Accepts GPSDOP regardless of TIFF type (Postel's Law).
     */
    #[Test]
    public function acceptsGpsDopWithNonRationalType(): void
    {
        try {
            (new TiffExifParser())->parseFromBlob(
                $this->buildClassicTiffWithEntry(
                    ExifTag::GPS_DOP,
                    TiffConst::TYPE_LONG,
                    1,
                    "\x00\x00\x00\x01",
                ),
            );
        } catch (ParseError $e) {
            self::assertNotSame(1317, $e->getCode(), 'Type check must not reject non-RATIONAL GPSDOP');

            return;
        }

        $this->addToAssertionCount(1);
    }

    /**
     * Tolerates GPSStatus/GPSMeasureMode tags when encoded with non-ASCII TIFF type.
     * Postel's Law: TIFF type mismatches are accepted.
     */
    #[Test]
    #[DataProvider('provideGpsStatusAndMeasureModeTags')]
    public function toleratesGpsStatusAndMeasureModeWrongType(int $tag): void
    {
        $blob = $this->buildClassicTiffWithEntry(
            $tag,
            TiffConst::TYPE_BYTE,
            2,
            "A\0",
        );

        try {
            (new TiffExifParser())->parseFromBlob($blob);
        } catch (ParseError $e) {
            self::assertNotSame(1317, $e->getCode(), 'Type mismatch must not be rejected');

            return;
        }

        $this->addToAssertionCount(1);
    }

    /**
     * Tolerates GPSStatus/GPSMeasureMode tags when encoded with wrong component count.
     * Postel's Law: fixed-length byte-count deviations are accepted.
     */
    #[Test]
    #[DataProvider('provideGpsStatusAndMeasureModeTags')]
    public function toleratesGpsStatusAndMeasureModeWrongCount(int $tag): void
    {
        $blob = $this->buildClassicTiffWithEntry(
            $tag,
            TiffConst::TYPE_ASCII,
            1,
            'A',
        );

        try {
            (new TiffExifParser())->parseFromBlob($blob);
        } catch (ParseError $e) {
            self::assertNotSame(1318, $e->getCode(), 'Byte-count deviation must not be rejected');

            return;
        }

        $this->addToAssertionCount(1);
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
     * Tolerates GPSDestDistanceRef with wrong type (BYTE instead of ASCII).
     * Postel's Law: TIFF type mismatches are accepted.
     */
    #[Test]
    public function toleratesGpsDestDistanceRefWrongType(): void
    {
        $blob = $this->buildClassicTiffWithEntry(
            ExifTag::GPS_DEST_DISTANCE_REF,
            TiffConst::TYPE_BYTE,
            2,
            "K\0",
        );

        try {
            (new TiffExifParser())->parseFromBlob($blob);
        } catch (ParseError $e) {
            self::assertNotSame(1317, $e->getCode(), 'Type mismatch must not be rejected');

            return;
        }

        $this->addToAssertionCount(1);
    }

    /**
     * Tolerates GPSDestDistanceRef with wrong count (1 instead of 2).
     * Postel's Law: fixed-length byte-count deviations are accepted.
     */
    #[Test]
    public function toleratesGpsDestDistanceRefWrongCount(): void
    {
        $blob = $this->buildClassicTiffWithEntry(
            ExifTag::GPS_DEST_DISTANCE_REF,
            TiffConst::TYPE_ASCII,
            1,
            'K',
        );

        try {
            (new TiffExifParser())->parseFromBlob($blob);
        } catch (ParseError $e) {
            self::assertNotSame(1318, $e->getCode(), 'Byte-count deviation must not be rejected');

            return;
        }

        $this->addToAssertionCount(1);
    }

    /**
     * Accepts GPSDestDistance regardless of TIFF type (Postel's Law).
     */
    #[Test]
    public function acceptsGpsDestDistanceWithNonRationalType(): void
    {
        try {
            (new TiffExifParser())->parseFromBlob(
                $this->buildClassicTiffWithEntry(
                    ExifTag::GPS_DEST_DISTANCE,
                    TiffConst::TYPE_LONG,
                    1,
                    "\x00\x00\x00\x01",
                ),
            );
        } catch (ParseError $e) {
            self::assertNotSame(1317, $e->getCode(), 'Type check must not reject non-RATIONAL GPSDestDistance');

            return;
        }

        $this->addToAssertionCount(1);
    }

    /**
     * Tolerates GPSMapDatum when encoded with non-ASCII TIFF type.
     * Postel's Law: TIFF type mismatches are accepted.
     */
    #[Test]
    #[DataProvider('provideToleratedGpsMapDatumTypes')]
    public function toleratesGpsMapDatumWithWrongType(int $type, int $count, string $valueBytes): void
    {
        $blob = $this->buildClassicTiffWithEntry(
            ExifTag::GPS_MAP_DATUM,
            $type,
            $count,
            $valueBytes,
        );

        try {
            (new TiffExifParser())->parseFromBlob($blob);
        } catch (ParseError $e) {
            self::assertNotSame(1317, $e->getCode(), 'Type mismatch must not be rejected');

            return;
        }

        $this->addToAssertionCount(1);
    }

    /**
     * @return iterable<string, array{0:int,1:int,2:string}>
     */
    public static function provideToleratedGpsMapDatumTypes(): iterable
    {
        yield 'SHORT type' => [
            TiffConst::TYPE_SHORT,
            1,
            "\x2A\x00",
        ];
    }

    /**
     * Tolerates GPSSatellites when encoded with non-ASCII TIFF type.
     * Postel's Law: TIFF type mismatches are accepted.
     */
    #[Test]
    #[DataProvider('provideToleratedGpsSatellitesTypes')]
    public function toleratesGpsSatellitesWithWrongType(int $type, int $count, string $valueBytes): void
    {
        $blob = $this->buildClassicTiffWithEntry(
            ExifTag::GPS_SATELLITES,
            $type,
            $count,
            $valueBytes,
        );

        try {
            (new TiffExifParser())->parseFromBlob($blob);
        } catch (ParseError $e) {
            self::assertNotSame(1317, $e->getCode(), 'Type mismatch must not be rejected');

            return;
        }

        $this->addToAssertionCount(1);
    }

    /**
     * @return iterable<string, array{0:int,1:int,2:string}>
     */
    public static function provideToleratedGpsSatellitesTypes(): iterable
    {
        yield 'SHORT type' => [
            TiffConst::TYPE_SHORT,
            1,
            "\x05\x00",
        ];
    }

    /**
     * Tolerates GPS bearing reference tags when encoded with non-ASCII TIFF type.
     * Postel's Law: TIFF type mismatches are accepted.
     */
    #[Test]
    #[DataProvider('provideGpsBearingRefTags')]
    public function toleratesGpsBearingReferenceTagsWithWrongType(int $tag): void
    {
        $blob = $this->buildClassicTiffWithEntry(
            $tag,
            TiffConst::TYPE_BYTE,
            2,
            "T\0",
        );

        try {
            (new TiffExifParser())->parseFromBlob($blob);
        } catch (ParseError $e) {
            self::assertNotSame(1317, $e->getCode(), 'Type mismatch must not be rejected');

            return;
        }

        $this->addToAssertionCount(1);
    }

    /**
     * Tolerates GPS bearing reference tags when encoded with wrong component count.
     * Postel's Law: fixed-length byte-count deviations are accepted.
     */
    #[Test]
    #[DataProvider('provideGpsBearingRefTags')]
    public function toleratesGpsBearingReferenceTagsWithWrongCount(int $tag): void
    {
        $blob = $this->buildClassicTiffWithEntry(
            $tag,
            TiffConst::TYPE_ASCII,
            1,
            'T',
        );

        try {
            (new TiffExifParser())->parseFromBlob($blob);
        } catch (ParseError $e) {
            self::assertNotSame(1318, $e->getCode(), 'Byte-count deviation must not be rejected');

            return;
        }

        $this->addToAssertionCount(1);
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
     * Accepts GPS bearing value tags regardless of TIFF type (Postel's Law).
     */
    #[Test]
    #[DataProvider('provideGpsBearingValueTags')]
    public function acceptsGpsBearingValueTagsWithNonRationalType(int $tag): void
    {
        try {
            (new TiffExifParser())->parseFromBlob(
                $this->buildClassicTiffWithEntry(
                    $tag,
                    TiffConst::TYPE_LONG,
                    1,
                    "\x00\x00\x00\x01",
                ),
            );
        } catch (ParseError $e) {
            self::assertNotSame(1317, $e->getCode(), 'Type check must not reject non-RATIONAL GPS bearing value');

            return;
        }

        $this->addToAssertionCount(1);
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

    /**
     * Tolerates SLONG type for SHORT Orientation tag (Postel's Law).
     */
    #[Test]
    public function toleratesSlongForShortOrientationTag(): void
    {
        $blob = $this->buildClassicTiffWithEntry(
            ExifTag::ORIENTATION,
            TiffConst::TYPE_SLONG,
            1,
            pack('V', 1),
        );

        (new TiffExifParser())->parseFromBlob($blob);

        $this->addToAssertionCount(1);
    }

    /**
     * Tolerates SHORT type for BYTE GPSAltitudeRef tag (Postel's Law).
     */
    #[Test]
    public function toleratesShortForByteGpsAltitudeRefTag(): void
    {
        $blob = $this->buildClassicTiffWithEntry(
            ExifTag::GPS_ALTITUDE_REF,
            TiffConst::TYPE_SHORT,
            1,
            pack('v', 0),
        );

        (new TiffExifParser())->parseFromBlob($blob);

        $this->addToAssertionCount(1);
    }

    /**
     * Tolerates ASCII type for SHORT Orientation tag (Postel's Law).
     * Previously rejected as cross-family mismatch, now tolerated.
     */
    #[Test]
    public function toleratesAsciiForShortOrientationTag(): void
    {
        $blob = $this->buildClassicTiffWithEntry(
            ExifTag::ORIENTATION,
            TiffConst::TYPE_ASCII,
            1,
            '1',
        );

        try {
            (new TiffExifParser())->parseFromBlob($blob);
        } catch (ParseError $e) {
            self::assertNotSame(1317, $e->getCode(), 'Type mismatch must not be rejected');

            return;
        }

        $this->addToAssertionCount(1);
    }

    private function buildClassicTiffWithEntry(int $tag, int $type, int $count, string $valueBytes): string
    {
        $ifdOffset                      = 8;
        $entryCount                     = 3; // ImageWidth + ImageLength + the requested tag
        $componentSize                  = $this->bytesPerComponent($type);
        $dataSize                       = $componentSize * $count;

        if (strlen($valueBytes) < $dataSize) {
            $valueBytes = str_pad($valueBytes, $dataSize, "\0");
        }

        // Build entries as [tag => binary] sorted ascending by tag ID
        $entries                        = [];

        // ImageWidth SHORT[1] = 100
        $entries[ExifTag::IMAGE_WIDTH]  = pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0);

        // ImageLength SHORT[1] = 100
        $entries[ExifTag::IMAGE_LENGTH] = pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0);

        // Requested tag — offset placeholder if out-of-line
        $outOfLine                      = $dataSize > 4;

        if ($outOfLine) {
            $valueOffset   = $ifdOffset + 2 + ($entryCount * 12) + 4;
            $entries[$tag] = pack('v', $tag) . pack('v', $type) . pack('V', $count) . pack('V', $valueOffset);
        } else {
            $inlineBytes   = str_pad(substr($valueBytes, 0, $dataSize), 4, "\0");
            $entries[$tag] = pack('v', $tag) . pack('v', $type) . pack('V', $count) . $inlineBytes;
        }

        ksort($entries);

        $blob                           = 'II'
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
            TiffConst::TYPE_SBYTE,
            TiffConst::TYPE_UNDEFINED => 1,
            TiffConst::TYPE_SHORT,
            TiffConst::TYPE_SSHORT => 2,
            TiffConst::TYPE_LONG,
            TiffConst::TYPE_SLONG => 4,
            TiffConst::TYPE_RATIONAL,
            TiffConst::TYPE_SRATIONAL => 8,
            TiffConst::TYPE_DOUBLE    => 8,
            default                   => 1,
        };
    }
}
