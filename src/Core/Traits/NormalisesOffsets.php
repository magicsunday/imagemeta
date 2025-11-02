<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Core\Traits;

use MagicSunday\ImageMeta\Core\BoundsError;
use MagicSunday\ImageMeta\Core\Util\UInt64;

/**
 * Reusable bounds checks for absolute and relative offset calculations.
 */
trait NormalisesOffsets
{
    abstract protected function offsetLimit(): int;

    private function normaliseAbsoluteOffset(int|UInt64 $offset, string $message): int
    {
        $limit = $this->offsetLimit();

        if ($offset instanceof UInt64) {
            if ($offset->compareInt($limit) > 0) {
                throw new BoundsError($message . ': ' . $offset->toHex());
            }

            $offset = $offset->toInt($message);
        }

        if (($offset < 0) || ($offset > $limit)) {
            throw new BoundsError($message . ': ' . $this->formatOffset($offset));
        }

        return $offset;
    }

    private function normaliseRelativeOffset(int|UInt64 $offset, int $base, string $message): int
    {
        $limit  = $this->offsetLimit();
        $delta  = $this->resolveOffsetValue($offset, $message);
        $target = $base + $delta;

        if (($target < 0) || ($target > $limit)) {
            throw new BoundsError($message . ': ' . $this->formatOffset($offset));
        }

        return $target;
    }

    private function resolveOffsetValue(int|UInt64 $offset, string $message): int
    {
        if ($offset instanceof UInt64) {
            return $offset->toInt($message);
        }

        return $offset;
    }

    /**
     * @return positive-int
     */
    private function normaliseReadLength(int|UInt64 $length, string $context): int
    {
        if ($length instanceof UInt64) {
            if ($length->isZero()) {
                throw new BoundsError($context . ': ' . $length->toHex());
            }

            $length = $length->toInt($context);
        }

        if ($length <= 0) {
            throw new BoundsError($context . ': ' . $length);
        }

        return $length;
    }

    private function formatOffset(int|UInt64 $offset): string
    {
        return $offset instanceof UInt64 ? $offset->toHex() : (string) $offset;
    }
}
