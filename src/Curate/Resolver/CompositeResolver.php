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

use function array_key_exists;
use function is_array;
use function is_float;
use function is_int;
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
     * Resolves an integer ISO value from the resolver and optional fallback callbacks.
     *
     * @param Closure():int|float|string|null ...$fallbacks
     */
    public static function intISO(ExifTagResolver $resolver, Closure ...$fallbacks): ?int
    {
        $iso = $resolver->iso();

        if ($iso !== null) {
            return $iso;
        }

        foreach ($fallbacks as $fallback) {
            $candidate = $fallback();

            if ($candidate === null) {
                continue;
            }

            if (!is_int($candidate) && !is_float($candidate) && !is_string($candidate)) {
                continue;
            }

            $value = self::normalizeNumeric($candidate);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Resolves image dimensions using the resolver with optional fallback callbacks.
     *
     * @param Closure():array{width:int|float|string|null,height:int|float|string|null}|Closure():array{width:int|float|string|null}|Closure():array{height:int|float|string|null} ...$fallbacks
     *
     * @return array{width:?int,height:?int}
     */
    public static function dimensions(ExifTagResolver $resolver, Closure ...$fallbacks): array
    {
        $width  = $resolver->imageWidth();
        $height = $resolver->imageHeight();

        if ($width !== null && $height !== null) {
            return ['width' => $width, 'height' => $height];
        }

        foreach ($fallbacks as $fallback) {
            if ($width !== null && $height !== null) {
                break;
            }

            $candidate = $fallback();
            if (!is_array($candidate)) {
                continue;
            }

            if ($width === null && array_key_exists('width', $candidate)) {
                $widthValue = $candidate['width'] ?? null;

                if (is_int($widthValue) || is_float($widthValue) || is_string($widthValue)) {
                    $width = self::normalizeNumeric($widthValue);
                }
            }

            if ($height === null && array_key_exists('height', $candidate)) {
                $heightValue = $candidate['height'] ?? null;

                if (is_int($heightValue) || is_float($heightValue) || is_string($heightValue)) {
                    $height = self::normalizeNumeric($heightValue);
                }
            }
        }

        return ['width' => $width, 'height' => $height];
    }

    /**
     * Resolves the original capture date including fallback callbacks.
     *
     * @param Closure():array{date:?DateTimeImmutable,source:?string} ...$fallbacks
     *
     * @return array{date:?DateTimeImmutable,source:?string}
     */
    public static function dateOriginal(ExifTagResolver $resolver, Closure ...$fallbacks): array
    {
        $candidates = [
            'DateTimeOriginal'  => $resolver->captureDateTime(),
            'DateTimeDigitized' => $resolver->digitizedDateTime(),
            'DateTime'          => $resolver->fileDateTime(),
        ];

        foreach ($candidates as $source => $candidate) {
            if ($candidate instanceof DateTimeImmutable) {
                return ['date' => $candidate, 'source' => $source];
            }
        }

        foreach ($fallbacks as $fallback) {
            $candidate = $fallback();

            if (!is_array($candidate)) {
                continue;
            }

            $date   = $candidate['date'] ?? null;
            $source = $candidate['source'] ?? null;

            if ($date instanceof DateTimeImmutable) {
                return ['date' => $date, 'source' => is_string($source) ? $source : null];
            }
        }

        return ['date' => null, 'source' => null];
    }

    /**
     * Creates a fallback candidate array for {@see self::dateOriginal()}.
     *
     * @return array{date:?DateTimeImmutable,source:?string}
     */
    public static function dateCandidate(?DateTimeImmutable $date, ?string $source = null): array
    {
        return ['date' => $date, 'source' => $source];
    }

    /**
     * Normalises numeric candidates originating from callbacks.
     *
     * @param int|float|string|null $value
     */
    private static function normalizeNumeric(int|float|string|null $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }
}
