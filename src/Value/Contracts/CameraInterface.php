<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value\Contracts;

use MagicSunday\ImageMeta\Value\Enum\FileSource;
use MagicSunday\ImageMeta\Value\Enum\SensingMethod;

interface CameraInterface
{
    public function make(): ?string;

    public function model(): ?string;

    public function ownerName(): ?string;

    public function serialNumber(): ?string;

    public function firmware(): ?string;

    public function fileSource(): ?FileSource;

    public function sensingMethod(): ?SensingMethod;
}
