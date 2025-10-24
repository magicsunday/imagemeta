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

use function is_array;
use function is_numeric;
use function is_string;

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
     * Resolves an integer ISO value from the provided candidate callbacks.
     *
     * @param list<Closure():int|string|null> $candidates
     */
    public static function intISO(array $candidates): ?int
    {
        $value = self::first($candidates);

        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Resolves image dimensions through deferred callbacks.
     *
     * @param Closure():int|string|null $widthResolver
     * @param Closure():int|string|null $heightResolver
     *
     * @return array{width:?int,height:?int}
     */
    public static function dimensions(Closure $widthResolver, Closure $heightResolver): array
    {
        $widthValue  = $widthResolver();
        $heightValue = $heightResolver();

        $width = is_int($widthValue)
            ? $widthValue
            : (is_numeric($widthValue) ? (int) $widthValue : null);

        $height = is_int($heightValue)
            ? $heightValue
            : (is_numeric($heightValue) ? (int) $heightValue : null);

        return ['width' => $width, 'height' => $height];
    }

    /**
     * Resolves the original capture date from the provided callbacks.
     *
     * @param array<string,Closure():array{date:?DateTimeImmutable,tz:?DateTimeZone,subSec:?string}> $candidates
     *
     * @return array{date:?DateTimeImmutable,tz:?DateTimeZone,subSec:?string,source:?string}
     */
    public static function dateOriginal(array $candidates): array
    {
        foreach ($candidates as $source => $resolver) {
            $value = $resolver();

            if (!is_array($value)) {
                continue;
            }

            $date = $value['date'] ?? null;
            if (!$date instanceof DateTimeImmutable) {
                continue;
            }

            $tz = $value['tz'] ?? null;
            if (!$tz instanceof DateTimeZone) {
                $tz = null;
            }

            $subSec = $value['subSec'] ?? null;
            if (!is_string($subSec) || $subSec === '') {
                $subSec = null;
            }

            return [
                'date' => $date,
                'tz' => $tz,
                'subSec' => $subSec,
                'source' => $source,
            ];
        }

        return [
            'date' => null,
            'tz' => null,
            'subSec' => null,
            'source' => null,
        ];
    }
}
