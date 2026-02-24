<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Core;

use function sprintf;
use function strlen;

/**
 * Guard utility for minimum-length payload checks.
 */
final class PayloadGuard
{
    /**
     * Prevents instantiation of this utility class.
     */
    private function __construct()
    {
    }

    /**
     * Ensures that a binary payload meets a minimum byte length.
     *
     * @param string $payload   Raw binary payload bytes.
     * @param int    $minBytes  Minimum number of bytes required.
     * @param string $context   Human-readable context for the error message.
     * @param int    $errorCode ParseError error code.
     *
     * @throws ParseError If the payload is shorter than the required minimum.
     */
    public static function ensureMinimumLength(string $payload, int $minBytes, string $context, int $errorCode): void
    {
        if (strlen($payload) < $minBytes) {
            throw new ParseError(
                sprintf('%s is too short (need %d bytes, got %d)', $context, $minBytes, strlen($payload)),
                $errorCode,
            );
        }
    }
}
