<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Exif\Reconciliation;

/**
 * Value type descriptors for EXIF↔XMP property mappings per CIPA DC-X010-2017.
 */
enum ExifXmpValueType: string
{
    case Integer                    = 'Integer';
    case Rational                   = 'Rational';
    case Text                       = 'Text';
    case Date                       = 'Date';
    case LanguageAlternative        = 'LanguageAlternative';
    case ProperName                 = 'ProperName';
    case GpsCoordinate              = 'GPSCoordinate';
    case OrderedArrayOfInteger      = 'OrderedArrayOfInteger';
    case OrderedArrayOfRational     = 'OrderedArrayOfRational';
    case OrderedArrayOfProperName   = 'OrderedArrayOfProperName';
    case ClosedChoiceOfInteger      = 'ClosedChoiceOfInteger';
    case ClosedChoiceOfText         = 'ClosedChoiceOfText';
    case ClosedChoiceOfOrderedArray = 'ClosedChoiceOfOrderedArray';
}
