<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value;

use MagicSunday\ImageMeta\Value\Contracts\CameraInterface;
use MagicSunday\ImageMeta\Value\Enum\FileSource;
use MagicSunday\ImageMeta\Value\Enum\SensingMethod;

/**
 * Captures camera specific information.
 */
final readonly class Camera implements CameraInterface
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
        public ?string $make,
        public ?string $model,
        public ?string $ownerName,
        public ?string $serialNumber,
        public ?string $firmware,
        public ?FileSource $fileSource,
        public ?SensingMethod $sensingMethod,
    ) {
    }

    public function make(): ?string
    {
        return $this->make;
    }

    public function model(): ?string
    {
        return $this->model;
    }

    public function ownerName(): ?string
    {
        return $this->ownerName;
    }

    public function serialNumber(): ?string
    {
        return $this->serialNumber;
    }

    public function firmware(): ?string
    {
        return $this->firmware;
    }

    public function fileSource(): ?FileSource
    {
        return $this->fileSource;
    }

    public function sensingMethod(): ?SensingMethod
    {
        return $this->sensingMethod;
    }
}
