<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Value;

use MagicSunday\ImageMeta\Value\Enum\LightSource;
use MagicSunday\ImageMeta\Value\Enum\SceneCaptureType;
use MagicSunday\ImageMeta\Value\Enum\SceneType;
use MagicSunday\ImageMeta\Value\Enum\SubjectDistanceRange;
use MagicSunday\ImageMeta\Value\Scene;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Scene value object.
 */
#[CoversClass(Scene::class)]
final class SceneTest extends TestCase
{
    #[Test]
    public function constructsWithSceneCaptureType(): void
    {
        $scene = new Scene(
            type: SceneCaptureType::STANDARD,
            sceneType: null,
            light: null,
            faceCount: null,
            hdrScene: null,
            nightMode: null,
            subjectDistanceRange: null,
        );

        self::assertSame(SceneCaptureType::STANDARD, $scene->type);
    }

    #[Test]
    public function constructsWithAllSceneMetadata(): void
    {
        $scene = new Scene(
            type: SceneCaptureType::NIGHT_SCENE,
            sceneType: SceneType::DIRECTLY_PHOTOGRAPHED_IMAGE,
            light: LightSource::DAYLIGHT,
            faceCount: 3,
            hdrScene: true,
            nightMode: true,
            subjectDistanceRange: SubjectDistanceRange::CLOSE_VIEW,
        );

        self::assertSame(SceneCaptureType::NIGHT_SCENE, $scene->type);
        self::assertSame(SceneType::DIRECTLY_PHOTOGRAPHED_IMAGE, $scene->sceneType);
        self::assertSame(LightSource::DAYLIGHT, $scene->light);
        self::assertSame(3, $scene->faceCount);
        self::assertTrue($scene->hdrScene);
        self::assertTrue($scene->nightMode);
        self::assertSame(SubjectDistanceRange::CLOSE_VIEW, $scene->subjectDistanceRange);
    }

    #[Test]
    public function allowsNullValues(): void
    {
        $scene = new Scene(
            type: null,
            sceneType: null,
            light: null,
            faceCount: null,
            hdrScene: null,
            nightMode: null,
            subjectDistanceRange: null,
        );

        self::assertNull($scene->type);
        self::assertNull($scene->sceneType);
        self::assertNull($scene->light);
        self::assertNull($scene->faceCount);
        self::assertNull($scene->hdrScene);
        self::assertNull($scene->nightMode);
        self::assertNull($scene->subjectDistanceRange);
    }
}
