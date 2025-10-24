<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value;

use MagicSunday\ImageMeta\Value\Enum\LightSource;
use MagicSunday\ImageMeta\Value\Enum\SceneCaptureType;
use MagicSunday\ImageMeta\Value\Enum\SubjectDistanceRange;

/**
 * Describes scene information inferred during capture.
 */
final readonly class Scene
{
    /**
     * @param SceneCaptureType|null     $type                 Scene capture type classification.
     * @param LightSource|null          $light                Dominant light source as reported by the camera.
     * @param int|null                  $faceCount            Number of detected faces.
     * @param bool|null                 $hdrScene             Indicates whether HDR scene processing was applied.
     * @param bool|null                 $nightMode            Whether night mode or low light processing was used.
     * @param SubjectDistanceRange|null $subjectDistanceRange Subject distance classification.
     */
    public function __construct(
        public ?SceneCaptureType $type,
        public ?LightSource $light,
        public ?int $faceCount,
        public ?bool $hdrScene,
        public ?bool $nightMode,
        public ?SubjectDistanceRange $subjectDistanceRange,
    ) {
    }
}
