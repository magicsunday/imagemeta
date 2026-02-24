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
 * Exercises the Scene value object for scene classification and capture context.
 * It verifies scene capture type, scene type, and light source enums are preserved.
 * The suite covers optional HDR, night mode, and face count fields.
 * This keeps scene-related metadata consistent for presentation and filtering.
 */
#[CoversClass(Scene::class)]
final class SceneTest extends TestCase
{
    /**
     * Stores the scene capture type when provided.
     * It confirms the object preserves the supplied metadata.
     *
     * @return void
     */
    #[Test]
    public function constructsWithSceneCaptureType(): void
    {
        $scene = new Scene(
            type: SceneCaptureType::Standard,
            sceneType: null,
            light: null,
            faceCount: null,
            hdrScene: null,
            nightMode: null,
            subjectDistanceRange: null,
        );

        self::assertSame(SceneCaptureType::Standard, $scene->type);
    }

    /**
     * Stores full scene metadata fields and enums.
     * It confirms the object preserves the supplied metadata.
     *
     * @return void
     */
    #[Test]
    public function constructsWithAllSceneMetadata(): void
    {
        $scene = new Scene(
            type: SceneCaptureType::NightScene,
            sceneType: SceneType::DirectlyPhotographedImage,
            light: LightSource::Daylight,
            faceCount: 3,
            hdrScene: true,
            nightMode: true,
            subjectDistanceRange: SubjectDistanceRange::Close,
        );

        self::assertSame(SceneCaptureType::NightScene, $scene->type);
        self::assertSame(SceneType::DirectlyPhotographedImage, $scene->sceneType);
        self::assertSame(LightSource::Daylight, $scene->light);
        self::assertSame(3, $scene->faceCount);
        self::assertTrue($scene->hdrScene);
        self::assertTrue($scene->nightMode);
        self::assertSame(SubjectDistanceRange::Close, $scene->subjectDistanceRange);
    }

    /**
     * Accepts null scene metadata values.
     * It ensures missing or invalid inputs yield no value.
     *
     * @return void
     */
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
