<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model\Jpeg;

/**
 * Defines marker codes used by JPEG streams.
 */
final class Marker
{
    public const int TEM = 0x01;

    public const int SOI = 0xD8;

    public const int EOI = 0xD9;

    public const int SOS = 0xDA;

    public const int DQT = 0xDB;

    public const int DNL = 0xDC;

    public const int DRI = 0xDD;

    public const int DHP = 0xDE;

    public const int EXP = 0xDF;

    public const int DHT = 0xC4;

    public const int DAC = 0xCC;

    public const int SOF0 = 0xC0;

    public const int SOF1 = 0xC1;

    public const int SOF2 = 0xC2;

    public const int SOF3 = 0xC3;

    public const int SOF5 = 0xC5;

    public const int SOF6 = 0xC6;

    public const int SOF7 = 0xC7;

    public const int SOF9 = 0xC9;

    public const int SOF10 = 0xCA;

    public const int SOF11 = 0xCB;

    public const int SOF13 = 0xCD;

    public const int SOF14 = 0xCE;

    public const int SOF15 = 0xCF;

    public const int RST0 = 0xD0;

    public const int RST1 = 0xD1;

    public const int RST2 = 0xD2;

    public const int RST3 = 0xD3;

    public const int RST4 = 0xD4;

    public const int RST5 = 0xD5;

    public const int RST6 = 0xD6;

    public const int RST7 = 0xD7;

    public const int APP0 = 0xE0;

    public const int APP1 = 0xE1;

    public const int APP2 = 0xE2;

    public const int APP3 = 0xE3;

    public const int APP4 = 0xE4;

    public const int APP5 = 0xE5;

    public const int APP6 = 0xE6;

    public const int APP7 = 0xE7;

    public const int APP8 = 0xE8;

    public const int APP9 = 0xE9;

    public const int APP10 = 0xEA;

    public const int APP11 = 0xEB;

    public const int APP12 = 0xEC;

    public const int APP13 = 0xED;

    public const int APP14 = 0xEE;

    public const int APP15 = 0xEF;

    public const int COM = 0xFE;

    public const int RST_FIRST = self::RST0;

    public const int RST_LAST = self::RST7;

    public const int APP_FIRST = self::APP0;

    public const int APP_LAST = self::APP15;

    /**
     * Prevents instantiation of the constants-only utility class.
     */
    private function __construct()
    {
    }
}
