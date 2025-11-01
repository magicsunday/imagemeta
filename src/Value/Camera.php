<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value;

use MagicSunday\ImageMeta\Value\Enum\FileSource;
use MagicSunday\ImageMeta\Value\Enum\SensingMethod;

/**
 * Captures camera specific information.
 */
final readonly class Camera
{
    /**
     * @param string|null        $make          Camera manufacturer.
     * @param string|null        $model         Camera model name.
     * @param string|null        $ownerName     Camera owner name.
     * @param string|null        $serialNumber  Serial number reported by metadata.
     * @param string|null        $firmware      Camera firmware version string.
     * @param FileSource|null    $fileSource    Image acquisition source classification.
     * @param SensingMethod|null $sensingMethod Sensor sampling method.
     */
    public function __construct(
        public readonly ?string $make,
        public readonly ?string $model,
        public readonly ?string $ownerName,
        public readonly ?string $serialNumber,
        public readonly ?string $firmware,
        public readonly ?FileSource $fileSource,
        public readonly ?SensingMethod $sensingMethod,
    ) {
    }
}
