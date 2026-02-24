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
 * Enumerates the light source identifiers assigned to the LightSource tag in
 * EXIF 3.0 §4.6.6.7.20 (LightSource).
 */
enum LightSource: int
{
    use EnumFromIntStringNullable;

    case Unknown              = 0;
    case Daylight             = 1;
    case Fluorescent          = 2;
    case Tungsten             = 3;
    case Flash                = 4;
    case FineWeather          = 9;
    case Cloudy               = 10;
    case Shade                = 11;
    case DaylightFluorescent  = 12;
    case DayWhiteFluorescent  = 13;
    case CoolWhiteFluorescent = 14;
    case WhiteFluorescent     = 15;

    case WarmWhiteFluorescent = 16;
    case StandardLightA       = 17;
    case StandardLightB       = 18;
    case StandardLightC       = 19;
    case D55                  = 20;
    case D65                  = 21;
    case D75                  = 22;
    case D50                  = 23;
    case IsoStudioTungsten    = 24;
    case Other                = 255;
}
