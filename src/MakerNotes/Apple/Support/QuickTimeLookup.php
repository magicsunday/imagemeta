<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\MakerNotes\Apple\Support;

use Closure;
use MagicSunday\ImageMeta\Model\QuickTime\QuickTimeMeta;

/**
 * Helper that resolves QuickTime metadata values with fallback support.
 */
final readonly class QuickTimeLookup
{
    /**
     * Creates a lookup helper that depends on QuickTime metadata for value resolution.
     *
     * @param QuickTimeMeta|null $quickTime Optional QuickTime metadata source used for lookups.
     */
    public function __construct(private ?QuickTimeMeta $quickTime)
    {
    }

    /**
     * Returns the first non-empty QuickTime string for the given keys.
     *
     * The lookup iterates over the provided keys in order and returns the first non-empty
     * string that exists in the QuickTime metadata. When the QuickTime metadata is not
     * available or none of the keys hold a value, null is returned.
     *
     * @param string ...$keys Ordered list of QuickTime metadata keys to resolve.
     *
     * @return non-empty-string|null Resolved string value or null when no matching metadata exists.
     *
     * @phpstan-return non-empty-string|null
     */
    public function string(string ...$keys): ?string
    {
        /** @var non-empty-string|null */
        return $this->firstNonNull(
            fn (string $key): ?string => ($this->quickTime?->stringValue($key) ?? null) !== ''
                ? $this->quickTime?->stringValue($key)
                : null,
            ...$keys,
        );
    }

    /**
     * Returns the first QuickTime float value for the given keys.
     *
     * The lookup iterates over the provided keys in order and returns the first float that
     * exists in the QuickTime metadata. When the QuickTime metadata is not available or none
     * of the keys hold a value, null is returned.
     *
     * @param string ...$keys Ordered list of QuickTime metadata keys to resolve.
     *
     * @return float|null Resolved float value or null when no matching metadata exists.
     */
    public function float(string ...$keys): ?float
    {
        /** @var float|null */
        return $this->firstNonNull(
            fn (string $key): ?float => $this->quickTime?->floatValue($key),
            ...$keys,
        );
    }

    /**
     * Returns the first QuickTime integer value for the given keys.
     *
     * The lookup iterates over the provided keys in order and returns the first integer that
     * exists in the QuickTime metadata. When the QuickTime metadata is not available or none
     * of the keys hold a value, null is returned.
     *
     * @param string ...$keys Ordered list of QuickTime metadata keys to resolve.
     *
     * @return int|null Resolved integer value or null when no matching metadata exists.
     */
    public function int(string ...$keys): ?int
    {
        /** @var int|null */
        return $this->firstNonNull(
            fn (string $key): ?int => $this->quickTime?->intValue($key),
            ...$keys,
        );
    }

    /**
     * Returns the first QuickTime boolean value for the given keys.
     *
     * The lookup iterates over the provided keys in order and returns the first boolean that
     * exists in the QuickTime metadata. When the QuickTime metadata is not available or none
     * of the keys hold a value, null is returned.
     *
     * @param string ...$keys Ordered list of QuickTime metadata keys to resolve.
     *
     * @return bool|null Resolved boolean value or null when no matching metadata exists.
     */
    public function bool(string ...$keys): ?bool
    {
        /** @var bool|null */
        return $this->firstNonNull(
            fn (string $key): ?bool => $this->quickTime?->boolValue($key),
            ...$keys,
        );
    }

    /**
     * Returns the first non-null value produced by applying the accessor to each key.
     *
     * @template T of string|int|float|bool
     *
     * @param Closure(string): (T|null) $accessor Accessor function that resolves a single key.
     * @param string                    ...$keys  Ordered list of QuickTime metadata keys to resolve.
     *
     * @return T|null First non-null value or null when no key resolves.
     */
    private function firstNonNull(Closure $accessor, string ...$keys): string|int|float|bool|null
    {
        if (!$this->quickTime instanceof QuickTimeMeta) {
            return null;
        }

        foreach ($keys as $key) {
            $value = $accessor($key);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }
}
