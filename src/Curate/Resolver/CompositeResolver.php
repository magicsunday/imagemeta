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
use MagicSunday\ImageMeta\Core\ValueConverters;

use function abs;
use function intdiv;
use function is_array;
use function is_float;
use function is_int;
use function is_numeric;
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
     * Resolves the ISO sensitivity using the EXIF resolver and optional fallback providers.
     *
     * @param Closure():(int|float|string|null) ...$fallbacks
     */
    public static function intISO(ExifTagResolver $exif, Closure ...$fallbacks): ?int
    {
        $iso = $exif->iso();
        if (is_int($iso)) {
            return $iso;
        }

        foreach ($fallbacks as $fallback) {
            $candidate  = $fallback();
            $normalized = self::normalizeInteger($candidate);

            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * Resolves image dimensions falling back to optional providers when EXIF lacks values.
     *
     * @param Closure():(array{width:int|string|null,height:int|string|null}|null) ...$fallbacks
     *
     * @return array{width:?int,height:?int}
     */
    public static function dimensions(ExifTagResolver $exif, Closure ...$fallbacks): array
    {
        $width  = $exif->imageWidth();
        $height = $exif->imageHeight();

        if ($width !== null || $height !== null) {
            return ['width' => $width, 'height' => $height];
        }

        foreach ($fallbacks as $fallback) {
            $value = $fallback();
            if (!is_array($value)) {
                continue;
            }

            $widthCandidate  = self::normalizeInteger($value['width'] ?? null);
            $heightCandidate = self::normalizeInteger($value['height'] ?? null);

            if ($widthCandidate !== null || $heightCandidate !== null) {
                return ['width' => $widthCandidate, 'height' => $heightCandidate];
            }
        }

        return ['width' => null, 'height' => null];
    }

    /**
     * Resolves the primary capture date along with timezone metadata and fractional seconds.
     *
     * Each fallback closure may return either {@see DateTimeImmutable} directly or an associative
     * array containing the keys `date`, `tz`, `subSec`, `source`, and `tzSource`.
     *
     * @param Closure():(DateTimeImmutable|array{
     *     date:?DateTimeImmutable,
     *     tz:?DateTimeZone,
     *     subSec:?string,
     *     source?:string,
     *     tzSource?:string
     * }|null) ...$fallbacks
     *
     * @return array{
     *     date:?DateTimeImmutable,
     *     tz:?DateTimeZone,
     *     subSec:?string,
     *     source:?string,
     *     tzSource:?string
     * }
     */
    public static function dateOriginal(ExifTagResolver $exif, Closure ...$fallbacks): array
    {
        $offsetTimeOriginal  = $exif->offsetTimeOriginal();
        $offsetTimeDigitized = $exif->offsetTimeDigitized();
        $offsetTime          = $exif->offsetTime();

        $subSecOriginal  = self::normalizeSubSeconds($exif->subSecTimeOriginal());
        $subSecDigitized = self::normalizeSubSeconds($exif->subSecTimeDigitized());
        $subSec          = self::normalizeSubSeconds($exif->subSecTime());

        $candidates = [
            [
                'source'   => 'DateTimeOriginal',
                'date'     => $exif->captureDateTime(),
                'tz'       => ValueConverters::parseOffset($offsetTimeOriginal),
                'subSec'   => $subSecOriginal,
                'tzSource' => 'OffsetTimeOriginal',
            ],
            [
                'source'   => 'DateTimeDigitized',
                'date'     => $exif->digitizedDateTime(),
                'tz'       => ValueConverters::parseOffset($offsetTimeDigitized),
                'subSec'   => $subSecDigitized,
                'tzSource' => 'OffsetTimeDigitized',
            ],
            [
                'source'   => 'DateTime',
                'date'     => $exif->fileDateTime(),
                'tz'       => ValueConverters::parseOffset($offsetTime),
                'subSec'   => $subSec,
                'tzSource' => 'OffsetTime',
            ],
        ];

        $position = 0;
        foreach ($fallbacks as $fallback) {
            $position++;
            $value = $fallback();
            if ($value === null) {
                continue;
            }

            if ($value instanceof DateTimeImmutable) {
                $candidates[] = [
                    'source'   => sprintf('fallback-%d', $position),
                    'date'     => $value,
                    'tz'       => $value->getTimezone(),
                    'subSec'   => null,
                    'tzSource' => sprintf('fallback-%d', $position),
                ];

                continue;
            }

            $date = $value['date'] ?? null;
            $tz   = $value['tz'] ?? null;
            $sub  = $value['subSec'] ?? null;
            $src  = $value['source'] ?? null;
            $tzSrc = $value['tzSource'] ?? null;
            $label = sprintf('fallback-%d', $position);

            $candidates[] = [
                'source'   => is_string($src) && $src !== '' ? $src : $label,
                'date'     => $date instanceof DateTimeImmutable ? $date : null,
                'tz'       => $tz instanceof DateTimeZone ? $tz : null,
                'subSec'   => is_string($sub) ? self::normalizeSubSeconds($sub) : null,
                'tzSource' => is_string($tzSrc) && $tzSrc !== ''
                    ? $tzSrc
                    : (is_string($src) && $src !== '' ? $src : $label),
            ];
        }

        $selected = null;
        foreach ($candidates as $candidate) {
            if ($candidate['date'] instanceof DateTimeImmutable) {
                $selected = $candidate;
                break;
            }
        }

        if ($selected === null) {
            $selected = [
                'source'   => null,
                'date'     => null,
                'tz'       => null,
                'subSec'   => null,
                'tzSource' => null,
            ];
        }

        $tz       = $selected['tz'] instanceof DateTimeZone ? $selected['tz'] : null;
        $tzSource = $selected['tz'] instanceof DateTimeZone ? $selected['tzSource'] : null;

        if (!$tz instanceof DateTimeZone) {
            $offsetCandidates = [
                'OffsetTimeOriginal'  => $offsetTimeOriginal,
                'OffsetTimeDigitized' => $offsetTimeDigitized,
                'OffsetTime'          => $offsetTime,
            ];

            foreach ($offsetCandidates as $source => $offset) {
                $candidateTz = ValueConverters::parseOffset($offset);
                if ($candidateTz instanceof DateTimeZone) {
                    $tz       = $candidateTz;
                    $tzSource = $source;
                    break;
                }
            }
        }

        if (!$tz instanceof DateTimeZone) {
            $minutes = $exif->timeZoneOffsetMinutes();
            if (is_array($minutes) && isset($minutes[0])) {
                $candidateTz = self::timeZoneFromMinutes($minutes[0]);
                if ($candidateTz instanceof DateTimeZone) {
                    $tz       = $candidateTz;
                    $tzSource = 'TimeZoneOffset';
                }
            }
        }

        if (!$tz instanceof DateTimeZone && $selected['date'] instanceof DateTimeImmutable) {
            $tz       = $selected['date']->getTimezone();
            $tzSource = $selected['tzSource'];
        }

        $date = $selected['date'];
        if ($date instanceof DateTimeImmutable && $tz instanceof DateTimeZone) {
            $date = $date->setTimezone($tz);
        }

        return [
            'date'     => $date instanceof DateTimeImmutable ? $date : null,
            'tz'       => $tz instanceof DateTimeZone ? $tz : null,
            'subSec'   => $selected['subSec'] ?? $subSecOriginal,
            'source'   => $selected['source'],
            'tzSource' => $tzSource,
        ];
    }

    /**
     * Normalizes integer candidates coming from various metadata sources.
     */
    private static function normalizeInteger(int|float|string|null $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) $value;
        }

        if (is_string($value) && $value !== '' && is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    /**
     * Converts empty fractional second strings to null.
     */
    private static function normalizeSubSeconds(?string $value): ?string
    {
        return $value === null || $value === '' ? null : $value;
    }

    /**
     * Converts a minute offset into a timezone object while validating its bounds.
     */
    private static function timeZoneFromMinutes(?int $minutes): ?DateTimeZone
    {
        if (!is_int($minutes)) {
            return null;
        }

        if ($minutes < -14 * 60 || $minutes > 14 * 60) {
            return null;
        }

        $absolute = abs($minutes);
        $hours    = intdiv($absolute, 60);
        $mins     = $absolute % 60;
        $prefix   = $minutes < 0 ? '-' : '+';

        return ValueConverters::parseOffset(sprintf('%s%02d:%02d', $prefix, $hours, $mins));
    }
}
