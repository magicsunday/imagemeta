<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Exif\Model;

use DateTimeImmutable;
use MagicSunday\ImageMeta\Value\Enum\ColorSpace;
use MagicSunday\ImageMeta\Value\Enum\ExposureProgram;
use MagicSunday\ImageMeta\Value\Enum\WhiteBalance;

/**
 * Read-only access contract for EXIF SubIFD metadata.
 *
 * EXIF 3.0 §4.6.6 defines Exif-specific tags stored in the SubIFD.
 */
interface ExifSubIfdDataInterface
{
    public function exifVersion(): ?string;

    public function flashpixVersion(): ?string;

    public function colorSpace(): ?ColorSpace;

    public function iso(): ?int;

    public function exposureTime(): ?float;

    public function fNumber(): ?float;

    public function focalLengthMm(): ?float;

    public function exposureProgram(): ?ExposureProgram;

    public function whiteBalance(): ?WhiteBalance;

    public function dateTimeOriginal(): ?DateTimeImmutable;

    public function dateTimeDigitized(): ?DateTimeImmutable;
}
