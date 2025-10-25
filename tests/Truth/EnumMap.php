<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Truth;

/**
 * Maps ExifTool (-n) Rohwerte auf die Enum-Namen deiner Library.
 * Die Namen stammen aus deinem Dump und sind so gewählt, dass sie
 * mit MagicSunday\ImageMeta\Value\Enum\*::CASE->name übereinstimmen.
 */
return [
    'Orientation' => [
        1 => 'TOP_LEFT',
        2 => 'TOP_RIGHT',
        3 => 'BOTTOM_RIGHT',
        4 => 'BOTTOM_LEFT',
        5 => 'LEFT_TOP',
        6 => 'RIGHT_TOP',
        7 => 'RIGHT_BOTTOM',
        8 => 'LEFT_BOTTOM',
    ],
    'ExposureProgram' => [
        0 => 'NOT_DEFINED',
        1 => 'MANUAL',
        2 => 'NORMAL',            // ExifTool: Program AE
        3 => 'APERTURE_PRIORITY',
        4 => 'SHUTTER_PRIORITY',
        5 => 'CREATIVE_PROGRAM',
        6 => 'ACTION_PROGRAM',
        7 => 'PORTRAIT_MODE',
        8 => 'LANDSCAPE_MODE',
    ],
    'MeteringMode' => [
        0   => 'UNKNOWN',
        1   => 'AVERAGE',
        2   => 'CENTER_WEIGHTED_AVERAGE',
        3   => 'SPOT',
        4   => 'MULTI_SPOT',
        5   => 'PATTERN',
        6   => 'PARTIAL',
        255 => 'OTHER',
    ],
    'WhiteBalance' => [
        0 => 'AUTO',
        1 => 'MANUAL',
    ],
    'SceneCaptureType' => [
        0 => 'STANDARD',
        1 => 'LANDSCAPE',
        2 => 'PORTRAIT',
        3 => 'NIGHT_SCENE',
    ],
    'SceneType' => [
        1 => 'DIRECTLY_PHOTOGRAPHED_IMAGE',
    ],
    'ResolutionUnit' => [
        1 => 'NONE',      // selten verwendet
        2 => 'INCHES',
        3 => 'CENTIMETERS',
    ],
    'YCbCrPositioning' => [
        1 => 'CENTERED',
        2 => 'CO_SITED',
    ],
    'SensingMethod' => [
        1 => 'NOT_DEFINED',
        2 => 'ONE_CHIP_COLOR_AREA',
        3 => 'TWO_CHIP_COLOR_AREA',
        4 => 'THREE_CHIP_COLOR_AREA',
        5 => 'COLOR_SEQUENTIAL_AREA',
        7 => 'TRILINEAR_SENSOR',
        8 => 'COLOR_SEQUENTIAL_LINEAR',
    ],
    'ColorSpace' => [
        1      => 'SRGB',
        65535  => 'UNCALIBRATED',
    ],
    'ExposureMode' => [
        0 => 'AUTO',
        1 => 'MANUAL',
        2 => 'AUTO_BRACKET',
    ],

    // Optional: falls du die Namen später direkt brauchst (für Doku/Debug)
    'FlashMode' => [
        0 => 'UNKNOWN',
        1 => 'COMPULSORY_FIRE',
        2 => 'COMPULSORY_SUPPRESS',
        3 => 'AUTO',
    ],
    'FlashReturn' => [
        0 => 'NO_STROBE_DETECTION',
        2 => 'RETURN_NOT_DETECTED',
        3 => 'RETURN_DETECTED',
    ],
    'FlashFunction' => [
        0 => 'PRESENT',
        1 => 'ABSENT',
    ],
];
