<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value\Enum;

use MagicSunday\ImageMeta\Value\Traits\EnumFromIntStringNullable;

/**
 * Enumerates the development characteristic of the capture device (high byte of
 * DevelopmentType); EXIF 3.1 §4.6.6.7.47.
 */
enum DevelopmentCharacteristic: int
{
    use EnumFromIntStringNullable;

    /** Development for the sameness with the "image at the time of capture". */
    case FaithfulReproduction = 0x01;

    /** Development not for sameness, but which won't make extreme difference. */
    case ModerateProcessing = 0x02;

    /** Development to make extreme difference (alteration of size/shape/removal of subject). */
    case ExtremeDifference = 0x04;
}
