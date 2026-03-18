<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Exif;

/**
 * EXIF-layer constants extracted from the Parse layer for cross-layer use.
 *
 * These constants are referenced by Exif readers and converters that must not
 * depend on the Parse namespace.
 */
final class ExifConst
{
    /**
     * 8-bit byte containing 7-bit ASCII code; last byte NUL.
     * TIFF 6.0 §2.2; EXIF 3.0 §4.5.2 Table 3.
     */
    public const int TYPE_ASCII               = 2;

    /**
     * EXIF 3.0 §4.6.6.8 — sentinel denominator value indicating an unknown measurement.
     */
    public const int EXIF_UNKNOWN_DENOMINATOR = 0xFFFFFFFF;

    /**
     * Prevents instantiation of the constants-only utility class.
     */
    private function __construct()
    {
    }
}
