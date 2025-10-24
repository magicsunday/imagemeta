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

use function array_key_exists;
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
     * Selects the first integer value from the candidate list applying numeric normalisation.
     *
     * @param list<Closure():int|float|string|null|int|float|string|null> $candidates
     */
    public static function firstInt(array $candidates): ?int
    {
        foreach ($candidates as $candidate) {
            $value = $candidate instanceof Closure ? $candidate() : $candidate;
            $normalized = self::normalizeInteger($value);

            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * Resolves the ISO sensitivity using the EXIF resolver and optional fallback values.
     *
     * @param list<Closure():int|float|string|null|int|float|string|null> $fallbacks
     */
    public static function intISO(ExifTagResolver $exif, array $fallbacks = []): ?int
    {
        $candidates = [
            static fn () => $exif->iso(),
        ];

        foreach ($fallbacks as $fallback) {
            $candidates[] = $fallback;
        }

        return self::firstInt($candidates);
    }

    /**
     * Resolves image dimensions falling back to optional providers when EXIF lacks values.
     *
     * @param list<array{width:int|float|string|null|Closure,height:int|float|string|null|Closure}|Closure():array{
     *     width:int|float|string|null|Closure,
     *     height:int|float|string|null|Closure
     * }|null> $fallbacks
     *
     * @return array{width:?int,height:?int}
     */
    public static function dimensions(ExifTagResolver $exif, array $fallbacks = []): array
    {
        $widthCandidates = [
            static fn () => $exif->imageWidth(),
        ];

        $heightCandidates = [
            static fn () => $exif->imageHeight(),
        ];

        foreach ($fallbacks as $fallback) {
            if ($fallback instanceof Closure) {
                $fallback = $fallback();
            }

            if (!is_array($fallback)) {
                continue;
            }

            if (array_key_exists('width', $fallback)) {
                $widthCandidates[] = $fallback['width'];
            }

            if (array_key_exists('height', $fallback)) {
                $heightCandidates[] = $fallback['height'];
            }
        }

        return [
            'width' => self::firstInt($widthCandidates),
            'height' => self::firstInt($heightCandidates),
        ];
    }

    /**
     * Resolves the primary capture date along with timezone metadata and fractional seconds.
     *
     * Each fallback entry may be either {@see DateTimeImmutable} directly or an associative array
     * containing the keys `date`, `tz`, `subSec`, `source`, and `tzSource`.
     *
     * @param list<DateTimeImmutable|Closure():DateTimeImmutable|Closure():array{
     *     date:?DateTimeImmutable,
     *     tz:?DateTimeZone,
     *     subSec:?string,
     *     source?:string,
     *     tzSource?:string
     * }|array{
     *     date:?DateTimeImmutable,
     *     tz:?DateTimeZone,
     *     subSec:?string,
     *     source?:string,
     *     tzSource?:string
     * }> $fallbacks
     *
     * @return array{
     *     date:?DateTimeImmutable,
     *     tz:?DateTimeZone,
     *     subSec:?string,
     *     source:?string,
     *     tzSource:?string
     * }
     */
    public static function dateOriginal(ExifTagResolver $exif, array $fallbacks = []): array
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

        foreach ($fallbacks as $index => $fallback) {
            $label = sprintf('fallback-%d', $index + 1);

            if ($fallback instanceof Closure) {
                $fallback = $fallback();
            }

            if ($fallback instanceof DateTimeImmutable) {
                $candidates[] = [
                    'source'   => $label,
                    'date'     => $fallback,
                    'tz'       => $fallback->getTimezone(),
                    'subSec'   => null,
                    'tzSource' => $label,
                ];

                continue;
            }

            if (!is_array($fallback)) {
                continue;
            }

            $date   = $fallback['date'] ?? null;
            $tz     = $fallback['tz'] ?? null;
            $sub    = $fallback['subSec'] ?? null;
            $source = $fallback['source'] ?? null;
            $tzSrc  = $fallback['tzSource'] ?? null;
            $name   = is_string($source) && $source !== '' ? $source : $label;

            $candidates[] = [
                'source'   => $name,
                'date'     => $date instanceof DateTimeImmutable ? $date : null,
                'tz'       => $tz instanceof DateTimeZone ? $tz : null,
                'subSec'   => is_string($sub) ? self::normalizeSubSeconds($sub) : null,
                'tzSource' => is_string($tzSrc) && $tzSrc !== '' ? $tzSrc : $name,
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
