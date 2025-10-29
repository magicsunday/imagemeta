<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Core;

/**
 * Provides reusable bit mask constants to avoid magic numbers.
 */
final class BitMask
{
    public const int BIT_0 = 0x01;

    public const int BIT_1 = 0x02;

    public const int BIT_2 = 0x04;

    public const int BIT_3 = 0x08;

    public const int BIT_4 = 0x10;

    public const int BIT_5 = 0x20;

    public const int BIT_6 = 0x40;

    public const int BIT_7 = 0x80;

    public const int LOW_NIBBLE = 0x0F;

    public const int HIGH_NIBBLE = 0xF0;

    public const int LOW_BYTE = 0xFF;

    public const int HIGH_BYTE = 0xFF00;

    public const int SIX_BIT_MASK = 0x3F;

    public const int SEVEN_BIT_MASK = 0x7F;

    public const int INT31_MAX = 0x7FFF_FFFF;

    public const int SIGN_BIT_16 = 0x8000;

    public const int SIGN_BIT_32 = 0x8000_0000;

    public const int UINT16_MAX = 0xFFFF;

    public const int UINT16_BASE = 0x1_0000;

    public const int UINT32_MAX = 0xFFFF_FFFF;

    public const int UINT32_BASE = 0x1_0000_0000;

    private function __construct()
    {
    }
}
