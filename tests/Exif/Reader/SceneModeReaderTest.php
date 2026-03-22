<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Exif\Reader;

use MagicSunday\ImageMeta\Exif\Converters\ApexConverter;
use MagicSunday\ImageMeta\Exif\Converters\ComponentsConverter;
use MagicSunday\ImageMeta\Exif\Converters\ConverterFactory;
use MagicSunday\ImageMeta\Exif\Converters\EnumConverter;
use MagicSunday\ImageMeta\Exif\Converters\FlashConverter;
use MagicSunday\ImageMeta\Exif\Converters\GpsConverter;
use MagicSunday\ImageMeta\Exif\Converters\GpsCoordinateConverter;
use MagicSunday\ImageMeta\Exif\Converters\GpsDirectionConverter;
use MagicSunday\ImageMeta\Exif\Converters\GpsTimestampConverter;
use MagicSunday\ImageMeta\Exif\Converters\GpsUnitConverter;
use MagicSunday\ImageMeta\Exif\Converters\MatrixConverter;
use MagicSunday\ImageMeta\Exif\Converters\NumericConverter;
use MagicSunday\ImageMeta\Exif\Converters\RationalConverter;
use MagicSunday\ImageMeta\Exif\Model\ExifNumericList;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Exif\Model\IfdValueReader;
use MagicSunday\ImageMeta\Exif\Reader\SceneModeReader;
use MagicSunday\ImageMeta\Exif\ValueConverters;
use MagicSunday\ImageMeta\Value\Enum\Contrast;
use MagicSunday\ImageMeta\Value\Enum\CorrectionApplied;
use MagicSunday\ImageMeta\Value\Enum\CustomRendered;
use MagicSunday\ImageMeta\Value\Enum\DevelopmentCharacteristic;
use MagicSunday\ImageMeta\Value\Enum\DevelopmentDefault;
use MagicSunday\ImageMeta\Value\Enum\LightSource;
use MagicSunday\ImageMeta\Value\Enum\MeteringMode;
use MagicSunday\ImageMeta\Value\Enum\NoiseReduction;
use MagicSunday\ImageMeta\Value\Enum\Saturation;
use MagicSunday\ImageMeta\Value\Enum\SceneCaptureType;
use MagicSunday\ImageMeta\Value\Enum\SceneType;
use MagicSunday\ImageMeta\Value\Enum\Sharpness;
use MagicSunday\ImageMeta\Value\Enum\WhiteBalance;
use MagicSunday\ImageMeta\Value\Traits\EnumFromIntStringNullable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesTrait;
use PHPUnit\Framework\TestCase;

/**
 * Exercises SceneModeReader for reading flash, metering, scene capture, white balance,
 * light source, contrast, saturation, sharpness, and subject distance metadata.
 *
 * @internal
 */
#[CoversClass(SceneModeReader::class)]
#[UsesClass(Ifd::class)]
#[UsesClass(IfdEntry::class)]
#[UsesClass(IfdValueReader::class)]
#[UsesClass(ValueConverters::class)]
#[UsesClass(ApexConverter::class)]
#[UsesClass(ComponentsConverter::class)]
#[UsesClass(ConverterFactory::class)]
#[UsesClass(EnumConverter::class)]
#[UsesClass(FlashConverter::class)]
#[UsesClass(GpsConverter::class)]
#[UsesClass(GpsCoordinateConverter::class)]
#[UsesClass(GpsDirectionConverter::class)]
#[UsesClass(GpsTimestampConverter::class)]
#[UsesClass(GpsUnitConverter::class)]
#[UsesClass(MatrixConverter::class)]
#[UsesClass(NumericConverter::class)]
#[UsesClass(RationalConverter::class)]
#[UsesClass(ExifNumericList::class)]
#[UsesTrait(EnumFromIntStringNullable::class)]
final class SceneModeReaderTest extends TestCase
{
    /**
     * Supplies ExifIFD entries for scene-related metadata.
     * Verifies flash, metering mode, scene capture type, and white balance values.
     */
    #[Test]
    public function readsSceneMetadata(): void
    {
        $exifEntries = [
            ExifTag::FLASH              => new IfdEntry(ExifTag::FLASH, 3, 1, 0x0F),
            ExifTag::METERING_MODE      => new IfdEntry(ExifTag::METERING_MODE, 3, 1, MeteringMode::Pattern->value),
            ExifTag::SCENE_CAPTURE_TYPE => new IfdEntry(ExifTag::SCENE_CAPTURE_TYPE, 3, 1, SceneCaptureType::Landscape->value),
            ExifTag::WHITE_BALANCE      => new IfdEntry(ExifTag::WHITE_BALANCE, 3, 1, WhiteBalance::Auto->value),
            ExifTag::LIGHT_SOURCE       => new IfdEntry(ExifTag::LIGHT_SOURCE, 3, 1, LightSource::Daylight->value),
        ];

        $reader = $this->createReader($exifEntries);

        self::assertSame(0x0F, $reader->flash());
        self::assertSame(MeteringMode::Pattern, $reader->meteringMode());
        self::assertSame(SceneCaptureType::Landscape, $reader->sceneCaptureType());
        self::assertSame(WhiteBalance::Auto, $reader->whiteBalance());
        self::assertSame(LightSource::Daylight, $reader->lightSource());
    }

    /**
     * Supplies ExifIFD entries with contrast, saturation, and sharpness.
     * Verifies in-camera processing settings are read correctly.
     */
    #[Test]
    public function readsInCameraProcessingSettings(): void
    {
        $exifEntries = [
            ExifTag::CONTRAST   => new IfdEntry(ExifTag::CONTRAST, 3, 1, Contrast::Hard->value),
            ExifTag::SATURATION => new IfdEntry(ExifTag::SATURATION, 3, 1, Saturation::High->value),
            ExifTag::SHARPNESS  => new IfdEntry(ExifTag::SHARPNESS, 3, 1, Sharpness::Hard->value),
        ];

        $reader = $this->createReader($exifEntries);

        self::assertSame(Contrast::Hard, $reader->contrast());
        self::assertSame(Saturation::High, $reader->saturation());
        self::assertSame(Sharpness::Hard, $reader->sharpness());
    }

    /**
     * Verifies defaults are returned when no ExifIFD entries are present.
     */
    #[Test]
    public function returnsDefaultsWhenNoEntriesPresent(): void
    {
        $reader = $this->createReader([]);

        self::assertNull($reader->flash());
        self::assertSame(MeteringMode::Unknown, $reader->meteringMode());
        self::assertSame(SceneCaptureType::Standard, $reader->sceneCaptureType());
        self::assertSame(LightSource::Unknown, $reader->lightSource());
        self::assertSame(Contrast::Normal, $reader->contrast());
        self::assertSame(Saturation::Normal, $reader->saturation());
        self::assertSame(Sharpness::Normal, $reader->sharpness());
        self::assertSame(CustomRendered::NormalProcess, $reader->customRendered());
        self::assertSame(SceneType::DirectlyPhotographedImage, $reader->sceneType());
    }

    /**
     * Supplies a SceneType tag with integer value 1.
     * Verifies the enum is correctly resolved.
     */
    #[Test]
    public function readsSceneTypeFromIntegerValue(): void
    {
        $exifEntries = [
            ExifTag::SCENE_TYPE => new IfdEntry(ExifTag::SCENE_TYPE, 7, 1, 1),
        ];

        $reader = $this->createReader($exifEntries);

        self::assertSame(SceneType::DirectlyPhotographedImage, $reader->sceneType());
    }

    /**
     * Supplies a SubjectLocation tag with two SHORT values.
     * Verifies the location coordinates are parsed correctly.
     */
    #[Test]
    public function readsSubjectLocation(): void
    {
        $exifEntries = [
            ExifTag::SUBJECT_LOCATION => new IfdEntry(ExifTag::SUBJECT_LOCATION, 3, 2, [100, 200]),
        ];

        $reader = $this->createReader($exifEntries);

        $location = $reader->subjectLocation();
        self::assertSame([100, 200], $location);
    }

    /**
     * Verifies null is returned for absent subject metadata.
     */
    #[Test]
    public function returnsNullForAbsentSubjectMetadata(): void
    {
        $reader = $this->createReader([]);

        self::assertNull($reader->subjectDistance());
        self::assertNull($reader->subjectDistanceRange());
        self::assertNull($reader->subjectArea());
        self::assertNull($reader->subjectLocation());
        self::assertNull($reader->flashInfo());
        self::assertNull($reader->flashEnergy());
        self::assertNull($reader->whiteBalance());
        self::assertNull($reader->gainControl());
    }

    /**
     * Supplies ExifIFD entries for all three EXIF 3.1 lens correction tags.
     * EXIF 3.1 §4.6.6.7.49–51: DistortionCorrection, ChromaticAberrationCorrection, ShadingCorrection.
     */
    #[Test]
    public function readsLensCorrectionTags(): void
    {
        $exifEntries = [
            ExifTag::DISTORTION_CORRECTION           => new IfdEntry(ExifTag::DISTORTION_CORRECTION, 3, 1, 1),
            ExifTag::CHROMATIC_ABERRATION_CORRECTION => new IfdEntry(ExifTag::CHROMATIC_ABERRATION_CORRECTION, 3, 1, 0),
            ExifTag::SHADING_CORRECTION              => new IfdEntry(ExifTag::SHADING_CORRECTION, 3, 1, 1),
        ];

        $reader = $this->createReader($exifEntries);

        self::assertSame(CorrectionApplied::Applied, $reader->distortionCorrection());
        self::assertSame(CorrectionApplied::NotApplied, $reader->chromaticAberrationCorrection());
        self::assertSame(CorrectionApplied::Applied, $reader->shadingCorrection());
    }

    /**
     * Verifies null is returned when lens correction tags are absent.
     * EXIF 3.1 §4.6.6.7.49–51: no default value defined.
     */
    #[Test]
    public function returnsNullForAbsentLensCorrectionTags(): void
    {
        $reader = $this->createReader([]);

        self::assertNull($reader->distortionCorrection());
        self::assertNull($reader->chromaticAberrationCorrection());
        self::assertNull($reader->shadingCorrection());
    }

    /**
     * Supplies an ExifIFD entry for the NoiseReduction tag.
     * EXIF 3.1 §4.6.6.7.52: 0–3 defined.
     */
    #[Test]
    public function readsNoiseReductionTag(): void
    {
        $exifEntries = [
            ExifTag::NOISE_REDUCTION => new IfdEntry(ExifTag::NOISE_REDUCTION, 3, 1, 2),
        ];

        $reader = $this->createReader($exifEntries);

        self::assertSame(NoiseReduction::NormalStrength, $reader->noiseReduction());
    }

    /**
     * Verifies null is returned when the NoiseReduction tag is absent.
     * EXIF 3.1 §4.6.6.7.52: no default value defined.
     */
    #[Test]
    public function returnsNullForAbsentNoiseReductionTag(): void
    {
        $reader = $this->createReader([]);

        self::assertNull($reader->noiseReduction());
    }

    /**
     * Supplies a packed DevelopmentType SHORT with faithful reproduction and factory default.
     * EXIF 3.1 §4.6.6.7.47: high byte=0x01 (faithful), low byte=0x01 (factory default).
     */
    #[Test]
    public function readsDevelopmentTypeComponents(): void
    {
        $packed      = (0x01 << 8) | 0x02;
        $exifEntries = [
            ExifTag::DEVELOPMENT_TYPE => new IfdEntry(ExifTag::DEVELOPMENT_TYPE, 3, 1, $packed),
        ];

        $reader = $this->createReader($exifEntries);

        self::assertSame(DevelopmentCharacteristic::FaithfulReproduction, $reader->developmentCharacteristic());
        self::assertSame(DevelopmentDefault::Different, $reader->developmentDefault());
    }

    /**
     * Supplies a DevelopmentTypeDescription tag with a UTF-8 string.
     * EXIF 3.1 §4.6.6.7.48.
     */
    #[Test]
    public function readsDevelopmentTypeDescription(): void
    {
        $exifEntries = [
            ExifTag::DEVELOPMENT_TYPE_DESCRIPTION => new IfdEntry(
                ExifTag::DEVELOPMENT_TYPE_DESCRIPTION,
                7,
                18,
                "Standard process\0",
            ),
        ];

        $reader = $this->createReader($exifEntries);

        self::assertSame('Standard process', $reader->developmentTypeDescription());
    }

    /**
     * Verifies null is returned when development type tags are absent.
     * EXIF 3.1 §4.6.6.7.47–48: no default value defined.
     */
    #[Test]
    public function returnsNullForAbsentDevelopmentTypeTags(): void
    {
        $reader = $this->createReader([]);

        self::assertNull($reader->developmentCharacteristic());
        self::assertNull($reader->developmentDefault());
        self::assertNull($reader->developmentTypeDescription());
    }

    /**
     * @param array<int, IfdEntry> $exifEntries
     */
    private function createReader(array $exifEntries): SceneModeReader
    {
        $exifIfd = $exifEntries !== [] ? new Ifd($exifEntries) : null;

        return new SceneModeReader(
            new IfdValueReader(new ValueConverters()),
            new ValueConverters(),
            $exifIfd,
        );
    }
}
