<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value;

use DateTimeImmutable;

/**
 * Holds capture specific timestamps.
 */
final readonly class Capture
{
    /**
     * @param DateTimeImmutable|null $dateTime Capture timestamp.
     */
    public function __construct(public ?DateTimeImmutable $dateTime)
    {
    }
}
