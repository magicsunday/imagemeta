<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Exif\Reader;

use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Exif\Model\IfdValueReader;
use MagicSunday\ImageMeta\Exif\Reader\SceneModeReader;
use MagicSunday\ImageMeta\Exif\ValueConverters;
use MagicSunday\ImageMeta\Value\Enum\Contrast;
use MagicSunday\ImageMeta\Value\Enum\CustomRendered;
use MagicSunday\ImageMeta\Value\Enum\LightSource;
use MagicSunday\ImageMeta\Value\Enum\MeteringMode;
use MagicSunday\ImageMeta\Value\Enum\Saturation;
use MagicSunday\ImageMeta\Value\Enum\SceneCaptureType;
use MagicSunday\ImageMeta\Value\Enum\SceneType;
use MagicSunday\ImageMeta\Value\Enum\Sharpness;
use MagicSunday\ImageMeta\Value\Enum\WhiteBalance;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
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
            ExifTag::CONTRAST    => new IfdEntry(ExifTag::CONTRAST, 3, 1, Contrast::Hard->value),
            ExifTag::SATURATION  => new IfdEntry(ExifTag::SATURATION, 3, 1, Saturation::HighSaturation->value),
            ExifTag::SHARPNESS   => new IfdEntry(ExifTag::SHARPNESS, 3, 1, Sharpness::Hard->value),
        ];

        $reader = $this->createReader($exifEntries);

        self::assertSame(Contrast::Hard, $reader->contrast());
        self::assertSame(Saturation::HighSaturation, $reader->saturation());
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
