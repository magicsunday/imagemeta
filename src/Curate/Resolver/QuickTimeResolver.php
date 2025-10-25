<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Curate\Resolver;

use MagicSunday\ImageMeta\Model\QuickTimeMeta;

use function is_bool;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function strtolower;
use function trim;

/**
 * Facilitates access to QuickTime metadata keys.
 */
final readonly class QuickTimeResolver
{
    /**
     * Wraps an optional QuickTime metadata container for convenient lookups.
     *
     * @param QuickTimeMeta|null $meta Parsed QuickTime metadata aggregate.
     */
    public function __construct(private ?QuickTimeMeta $meta)
    {
    }

    /**
     * Reads a string value from the metadata map.
     */
    public function string(string $key): ?string
    {
        $value = $this->meta?->keys[$key] ?? null;

        return is_string($value) ? trim($value) : null;
    }

    /**
     * Reads an integer value from the metadata map.
     */
    public function int(string $key): ?int
    {
        $value = $this->meta?->keys[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    /**
     * Reads a float value from the metadata map.
     */
    public function float(string $key): ?float
    {
        $value = $this->meta?->keys[$key] ?? null;

        if (is_float($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }

    /**
     * Interprets the metadata value as a boolean.
     */
    public function bool(string $key): ?bool
    {
        $value = $this->meta?->keys[$key] ?? null;

        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            return match ($normalized) {
                'true', '1' => true,
                'false', '0' => false,
                default => null,
            };
        }

        if (is_numeric($value)) {
            return (bool) $value;
        }

        return null;
    }
}
