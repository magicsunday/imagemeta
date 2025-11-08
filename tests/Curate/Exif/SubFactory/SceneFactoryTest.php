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
use MagicSunday\ImageMeta\Model\Exif\ParsedExif;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Model\QuickTimeMeta;
use MagicSunday\ImageMeta\Value\Enum\LightSource;
use MagicSunday\ImageMeta\Value\Enum\SceneCaptureType;
use MagicSunday\ImageMeta\Value\Enum\SubjectDistanceRange;
use MagicSunday\ImageMeta\Value\Scene;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SceneFactory::class)]
final class SceneFactoryTest extends TestCase
{
    #[Test]
    public function createsFromExifMetadata(): void
    {
        $exifDoc = $this->createMock(ParsedExif::class);
        $exifDoc->method('sceneCaptureType')->willReturn(SceneCaptureType::STANDARD);
        $exifDoc->method('sceneType')->willReturn(1);
        $exifDoc->method('lightSource')->willReturn(LightSource::DAYLIGHT);
        $exifDoc->method('subjectDistanceRange')->willReturn(SubjectDistanceRange::CLOSE_VIEW);

        $metadata       = new Metadata();
        $metadata->exifDoc = $exifDoc;

        $factory = new SceneFactory();
        $scene   = $factory->create($metadata, 2);

        self::assertInstanceOf(Scene::class, $scene);
        self::assertSame(SceneCaptureType::STANDARD, $scene->type);
        self::assertSame(1, $scene->sceneType);
        self::assertSame(LightSource::DAYLIGHT, $scene->light);
        self::assertSame(2, $scene->faceCount);
        self::assertSame(SubjectDistanceRange::CLOSE_VIEW, $scene->subjectDistanceRange);
    }

    #[Test]
    public function detectsHdrSceneFromApple(): void
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

        $metadata             = new Metadata();
        $metadata->makerNotes = new class ($apple) {
            public function __construct(public AppleMakerNotes $apple)
            {
            }
        };

        $factory = new SceneFactory();
        $scene   = $factory->create($metadata, null);

        self::assertInstanceOf(Scene::class, $scene);
        self::assertTrue($scene->hdrScene);
    }

    #[Test]
    public function detectsNightModeFromQuickTime(): void
    {
        $quickTime           = new QuickTimeMeta();
        $quickTime->metadata = ['NightMode' => true];

        $metadata           = new Metadata();
        $metadata->quickTime   = $quickTime;

        $factory = new SceneFactory();
        $scene   = $factory->create($metadata, null);

        self::assertInstanceOf(Scene::class, $scene);
        self::assertTrue($scene->nightMode);
    }

    #[Test]
    public function detectsHdrFromFlags(): void
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

        $metadata             = new Metadata();
        $metadata->makerNotes = new class ($apple) {
            public function __construct(public AppleMakerNotes $apple)
            {
            }
        };

        $factory = new SceneFactory();
        $scene   = $factory->create($metadata, null);

        self::assertInstanceOf(Scene::class, $scene);
        self::assertTrue($scene->hdrScene);
    }

    #[Test]
    public function createsWithNullMetadata(): void
    {
        $metadata = new Metadata();

        $factory = new SceneFactory();
        $scene   = $factory->create($metadata, null);

        self::assertInstanceOf(Scene::class, $scene);
        self::assertNull($scene->type);
        self::assertNull($scene->sceneType);
        self::assertNull($scene->light);
        self::assertNull($scene->faceCount);
        self::assertNull($scene->hdrScene);
        self::assertNull($scene->nightMode);
        self::assertNull($scene->subjectDistanceRange);
    }
}
