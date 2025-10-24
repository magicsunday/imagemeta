<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value;

use MagicSunday\ImageMeta\Value\Enum\DepthFormat;
use MagicSunday\ImageMeta\Value\Enum\DepthMeasureType;
use MagicSunday\ImageMeta\Value\Enum\DepthUnits;

/**
 * Represents depth map related information from EXIF/DNG metadata.
 */
final readonly class Depth
{
    /**
     * @param DepthFormat|null      $format      Depth map format as defined by DNG/EXIF.
     * @param float|null            $near        Near plane distance for the depth map.
     * @param float|null            $far         Far plane distance for the depth map.
     * @param DepthUnits|null       $units       Reported units for depth distances.
     * @param DepthMeasureType|null $measureType Type of measurement used for the depth values.
     */
    public function __construct(
        public ?DepthFormat $format,
        public ?float $near,
        public ?float $far,
        public ?DepthUnits $units,
        public ?DepthMeasureType $measureType,
    ) {
    }
}
