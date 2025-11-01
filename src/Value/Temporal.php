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
        public readonly ?DateTimeImmutable $create,
        public readonly ?DateTimeImmutable $modify,
        public readonly ?DateTimeImmutable $original,
        public readonly ?DateTimeZone $tz,
        public readonly ?string $tzSource,
        public readonly ?string $offsetTime,
        public readonly ?string $offsetTimeOriginal,
        public readonly ?string $offsetTimeDigitized,
        public readonly ?string $subSecTime,
        public readonly ?string $subSecTimeOriginal,
        public readonly ?string $subSecTimeDigitized,
        public readonly ?array $timeZoneOffsetMinutes,
    ) {
    }
}
