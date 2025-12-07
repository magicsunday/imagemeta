<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Curate\Exif\SubFactory;

use MagicSunday\ImageMeta\Curate\Exif\SubFactory\SceneFactory;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleMakerNotes;
use MagicSunday\ImageMeta\MakerNotes\MakerNotesRecord;
use MagicSunday\ImageMeta\Model\Exif\ExifTag;
use MagicSunday\ImageMeta\Model\Exif\Ifd;
use MagicSunday\ImageMeta\Model\Exif\IfdEntry;
use MagicSunday\ImageMeta\Model\Exif\ParsedExif;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Model\QuickTimeMeta;
use MagicSunday\ImageMeta\Value\Enum\LightSource;
use MagicSunday\ImageMeta\Value\Enum\SceneCaptureType;
use MagicSunday\ImageMeta\Value\Enum\SceneType;
use MagicSunday\ImageMeta\Value\Enum\SubjectDistanceRange;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SceneFactory::class)]
final class SceneFactoryTest extends TestCase
{
    #[Test]
    public function createsFromExifMetadata(): void
    {
        $parsedExif = $this->parsedExif(
            sceneCaptureType: SceneCaptureType::STANDARD,
            sceneType: SceneType::DIRECTLY_PHOTOGRAPHED_IMAGE->value,
            lightSource: LightSource::DAYLIGHT,
            subjectDistanceRange: SubjectDistanceRange::CLOSE,
        );

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            exifDoc: $parsedExif,
        );

        $factory = new SceneFactory();
        $scene   = $factory->create($metadata, 2);

        self::assertSame(SceneCaptureType::STANDARD, $scene->type);
        self::assertSame(SceneType::DIRECTLY_PHOTOGRAPHED_IMAGE, $scene->sceneType);
        self::assertSame(LightSource::DAYLIGHT, $scene->light);
        self::assertSame(2, $scene->faceCount);
        self::assertSame(SubjectDistanceRange::CLOSE, $scene->subjectDistanceRange);
    }

    #[Test]
    public function detectsHdrSceneFromAppleHeadroom(): void
    {
        $apple = new AppleMakerNotes(
            contentIdentifier: null,
            cameraType: null,
            hdrHeadroom: 2.5,
            hdrGain: null,
            snr: null,
            aeStable: null,
            aeTarget: null,
            aeAverage: null,
            afStable: null,
            afPerformance: null,
            signalToNoiseRatioType: null,
            luminanceNoiseAmplitude: null,
            focusPosition: null,
            livePhotoIndex: null,
            colorTemperature: null,
            semanticStylePreset: null,
            semanticStyleWarmth: null,
            semanticStyleTone: null,
            flags: [],
            accelerationVector: null,
        );

        $makerNotes = new MakerNotesRecord(
            vendor: 'APPLE',
            length: 0,
            sha1: str_repeat('0', 40),
            apple: $apple,
        );

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            makerNotes: $makerNotes,
        );

        $factory = new SceneFactory();
        $scene   = $factory->create($metadata);

        self::assertTrue($scene->hdrScene);
    }

    #[Test]
    public function detectsNightModeFromQuickTime(): void
    {
        $quickTime = new QuickTimeMeta([
            'NightMode' => true,
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: $quickTime,
        );

        $factory = new SceneFactory();
        $scene   = $factory->create($metadata);

        self::assertTrue($scene->nightMode);
    }

    #[Test]
    public function detectsHdrFromAppleFlags(): void
    {
        $apple = new AppleMakerNotes(
            contentIdentifier: null,
            cameraType: null,
            hdrHeadroom: null,
            hdrGain: null,
            snr: null,
            aeStable: null,
            aeTarget: null,
            aeAverage: null,
            afStable: null,
            afPerformance: null,
            signalToNoiseRatioType: null,
            luminanceNoiseAmplitude: null,
            focusPosition: null,
            livePhotoIndex: null,
            colorTemperature: null,
            semanticStylePreset: null,
            semanticStyleWarmth: null,
            semanticStyleTone: null,
            flags: ['hdrEnabled' => true],
            accelerationVector: null,
        );

        $makerNotes = new MakerNotesRecord(
            vendor: 'APPLE',
            length: 0,
            sha1: str_repeat('0', 40),
            apple: $apple,
        );

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            makerNotes: $makerNotes,
        );

        $factory = new SceneFactory();
        $scene   = $factory->create($metadata);

        self::assertTrue($scene->hdrScene);
    }

    #[Test]
    public function createsWithNullMetadata(): void
    {
        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
        );

        $factory = new SceneFactory();
        $scene   = $factory->create($metadata);

        self::assertNull($scene->type);
        self::assertNull($scene->sceneType);
        self::assertNull($scene->light);
        self::assertNull($scene->faceCount);
        self::assertNull($scene->hdrScene);
        self::assertNull($scene->nightMode);
        self::assertNull($scene->subjectDistanceRange);
    }

    private function parsedExif(
        ?SceneCaptureType $sceneCaptureType,
        ?int $sceneType,
        ?LightSource $lightSource,
        ?SubjectDistanceRange $subjectDistanceRange,
    ): ParsedExif {
        $exifEntries = [];

        if ($sceneCaptureType instanceof SceneCaptureType) {
            $exifEntries[ExifTag::SCENE_CAPTURE_TYPE] = new IfdEntry(
                ExifTag::SCENE_CAPTURE_TYPE,
                3,
                1,
                $sceneCaptureType->value,
            );
        }

        if ($sceneType !== null) {
            $exifEntries[ExifTag::SCENE_TYPE] = new IfdEntry(
                ExifTag::SCENE_TYPE,
                7,
                1,
                $sceneType,
            );
        }

        if ($lightSource instanceof LightSource) {
            $exifEntries[ExifTag::LIGHT_SOURCE] = new IfdEntry(
                ExifTag::LIGHT_SOURCE,
                3,
                1,
                $lightSource->value,
            );
        }

        if ($subjectDistanceRange instanceof SubjectDistanceRange) {
            $exifEntries[ExifTag::SUBJECT_DISTANCE_RANGE] = new IfdEntry(
                ExifTag::SUBJECT_DISTANCE_RANGE,
                3,
                1,
                $subjectDistanceRange->value,
            );
        }

        $ifd0    = new Ifd([]);
        $exifIfd = new Ifd($exifEntries);

        return new ParsedExif(
            ifd0: $ifd0,
            exifIfd: $exifIfd,
            gpsIfd: null,
            interopIfd: null,
            ifd1: null,
        );
    }
}
