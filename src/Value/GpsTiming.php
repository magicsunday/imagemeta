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
use DateTimeZone;

/**
 * Describes GPS timing data including date, time, and combined UTC timestamp.
 */
final readonly class GpsTiming
{
    public ?DateTimeImmutable $timestamp;

    /**
     * @param string|null            $date      GPS date stamp in ISO 8601 calendar format.
     * @param string|null            $dateRaw   Raw GPS date payload without normalisation.
     * @param string|null            $time      GPS time stamp in HH:MM:SS(.sss) format.
     * @param DateTimeImmutable|null $timestamp Combined UTC timestamp when available.
     */
    public function __construct(
        public ?string $date = null,
        public ?string $dateRaw = null,
        public ?string $time = null,
        ?DateTimeImmutable $timestamp = null,
    ) {
        $this->timestamp = $this->normaliseTimestamp($timestamp);
    }

    /**
     * Normalises the GPS timestamp to UTC when present.
     */
    private function normaliseTimestamp(?DateTimeImmutable $timestamp): ?DateTimeImmutable
    {
        if (!$timestamp instanceof DateTimeImmutable) {
            return null;
        }

        if ($timestamp->getTimezone()->getName() === 'UTC') {
            return $timestamp;
        }

        return $timestamp->setTimezone(new DateTimeZone('UTC'));
    }
}
