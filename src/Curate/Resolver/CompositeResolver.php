<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Curate\Resolver;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\ImageMeta\Model\Exif\ValueConverters;
use MagicSunday\ImageMeta\Model\Exif\ExifDocument;

use function abs;
use function intdiv;
use function is_array;
use function is_int;
use function is_string;
use function sprintf;

/**
 * Selects the first non-null value from a list of resolver callables.
 */
final readonly class CompositeResolver
{
    /**
     * @param list<Closure():T|T> $candidates
     *
     * @return T|null
     *
     * @template T
     */
    public static function first(array $candidates)
    {
        foreach ($candidates as $candidate) {
            $value = $candidate instanceof Closure ? $candidate() : $candidate;

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Resolves the original capture timestamp along with timezone and sub-second components.
     *
     * @param class-string<ValueConverters> $converterClass
     *
     * @return array{0:?DateTimeImmutable,1:?DateTimeZone,2:?string}
     */
    public static function dateOriginal(?ExifDocument $document, string $converterClass): array
    {
        if (!$document instanceof ExifDocument) {
            return [null, null, null];
        }

        $dateTime = $document->captureDateTime();
        $offset   = $document->offsetTimeOriginal();
        if ($offset === null) {
            $zoneOffsets = $document->timeZoneOffsetMinutes();
            $offset      = is_array($zoneOffsets) && isset($zoneOffsets[0]) ? $zoneOffsets[0] : null;
        }

        if (is_int($offset)) {
            $absOffset = abs($offset);
            $hours     = $absOffset;
            $minutes   = 0;

            if ($absOffset > 14) {
                $hours   = intdiv($absOffset, 60);
                $minutes = $absOffset % 60;
            }

            if ($hours > 14 || ($hours === 14 && $minutes !== 0)) {
                $offset = null;
            } else {
                $sign   = $offset < 0 ? '-' : '+';
                $offset = sprintf('%s%02d:%02d', $sign, $hours, $minutes);
            }
        }

        $timezone = $converterClass::parseOffset(is_string($offset) ? $offset : null);

        return [
            $dateTime,
            $timezone,
            $document->subSecTimeOriginal(),
        ];
    }

}
