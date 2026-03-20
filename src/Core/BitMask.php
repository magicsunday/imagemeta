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

    public const int LOW_NIBBLE = 0x0F;

    public const int INT31_MAX = 0x7FFF_FFFF;

    public const int SIGN_BIT_16 = 0x8000;

    public const int SIGN_BIT_32 = 0x8000_0000;

    public const int UINT16_BASE = 0x1_0000;

    public const int UINT32_MAX = 0xFFFF_FFFF;

    public const int UINT32_BASE = 0x1_0000_0000;

    /**
     * Prevents instantiation of the constants-only utility class.
     */
    private function __construct()
    {
    }
}
