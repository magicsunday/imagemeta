<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Core\Util;

use function strtoupper;
use function trim;

/**
 * Shared string normalization utilities.
 */
final class StringUtil
{
    /**
     * Trims whitespace and returns null for empty strings.
     */
    public static function trimToNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Trims whitespace, converts to uppercase, and returns null for empty strings.
     */
    public static function trimToUpperNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : strtoupper($trimmed);
    }

    /**
     * Prevents instantiation of the utility class.
     */
    private function __construct()
    {
    }
}
