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
 * Collects various capture and modification timestamps.
 */
final readonly class Temporal
{
    /**
     * @param DateTimeImmutable|null $create                Creation timestamp.
     * @param DateTimeImmutable|null $modify                Modification timestamp.
     * @param DateTimeImmutable|null $original              Original capture timestamp.
     * @param DateTimeZone|null      $tz                    Time zone derived from the metadata.
     * @param string|null            $tzSource              Identifier of the metadata source providing the timezone.
     * @param string|null            $offsetTime            OffsetTime tag value.
     * @param string|null            $offsetTimeOriginal    OffsetTimeOriginal tag value.
     * @param string|null            $offsetTimeDigitized   OffsetTimeDigitized tag value.
     * @param string|null            $subSecTime            SubSecTime value from EXIF.
     * @param string|null            $subSecTimeOriginal    SubSecTimeOriginal value from EXIF.
     * @param string|null            $subSecTimeDigitized   SubSecTimeDigitized value from EXIF.
     * @param list<int>|null         $timeZoneOffsetMinutes TimeZoneOffset values expressed in minutes.
     */
    public function __construct(
        public ?DateTimeImmutable $create,
        public ?DateTimeImmutable $modify,
        public ?DateTimeImmutable $original,
        public ?DateTimeZone $tz,
        public ?string $tzSource,
        public ?string $offsetTime,
        public ?string $offsetTimeOriginal,
        public ?string $offsetTimeDigitized,
        public ?string $subSecTime,
        public ?string $subSecTimeOriginal,
        public ?string $subSecTimeDigitized,
        public ?array $timeZoneOffsetMinutes,
    ) {
    }
}
