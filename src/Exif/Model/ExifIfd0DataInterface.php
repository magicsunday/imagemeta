<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Exif\Model;

use MagicSunday\ImageMeta\Value\Enum\Orientation;

/**
 * Read-only access contract for IFD0-oriented EXIF metadata.
 *
 * EXIF 3.0 §4.6.3 defines baseline image tags in IFD0.
 */
interface ExifIfd0DataInterface
{
    public function cameraMake(): ?string;

    public function cameraModel(): ?string;

    public function documentName(): ?string;

    public function imageDescription(): ?string;

    public function imageWidth(): ?int;

    public function imageHeight(): ?int;

    public function orientation(): Orientation;

    public function software(): ?string;

    public function copyright(): ?string;
}
