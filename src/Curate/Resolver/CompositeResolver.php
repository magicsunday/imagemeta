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
     * Selects the first integer value from the candidate list applying numeric normalisation.
     *
     * @param array<int, (Closure(): (int|null))|float|int|string|null> $candidates
     */
    public static function firstInt(array $candidates): ?int
    {
        foreach ($candidates as $candidate) {
            $value      = $candidate instanceof Closure ? $candidate() : $candidate;
            $normalized = self::normalizeInteger($value);

            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * Resolves the ISO sensitivity using EXIF fallbacks.
     */
    public static function intISO(ExifTagResolver $resolver): ?int
    {
        return self::firstInt([
            static fn (): ?int => $resolver->int('ISO'),
            static fn (): ?int => $resolver->int('ISOSpeed'),
            static fn (): ?int => $resolver->int('StandardOutputSensitivity'),
            static fn (): ?int => $resolver->int('RecommendedExposureIndex'),
        ]);
    }

    /**
     * Resolves the image dimensions using EXIF fallbacks.
     *
     * @return array{0:?int,1:?int}
     */
    public static function dimensions(ExifTagResolver $resolver): array
    {
        $width = self::firstInt([
            static fn (): ?int => $resolver->int('ExifImageWidth'),
            static fn (): ?int => $resolver->int('ImageWidth'),
        ]);

        $height = self::firstInt([
            static fn (): ?int => $resolver->int('ExifImageHeight'),
            static fn (): ?int => $resolver->int('ImageLength'),
        ]);

        return [$width, $height];
    }

    /**
     * Resolves the original capture timestamp along with timezone and sub-second components.
     *
     * @param class-string<ValueConverters> $converterClass
     *
     * @return array{0:?DateTimeImmutable,1:?DateTimeZone,2:?string}
     */
    public static function dateOriginal(ExifTagResolver $resolver, string $converterClass): array
    {
        $dateTime = $resolver->date('DateTimeOriginal');

        $offset = $resolver->string('OffsetTimeOriginal');
        if ($offset === null) {
            $zoneOffsets = $resolver->ints('TimeZoneOffset');
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
            $resolver->string('SubSecTimeOriginal'),
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
}
