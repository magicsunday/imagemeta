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

use const PHP_INT_MAX;

/**
 * Reusable bounds checks for absolute and relative offset calculations.
 */
trait NormalizesOffsets
{
    /**
     * Returns the maximum allowed absolute offset for the current data source.
     *
     * @return int Upper bound for offsets.
     */
    abstract protected function offsetLimit(): int;

    /**
     * Normalizes an absolute offset and validates it against the upper bound.
     *
     * @param int|UInt64 $offset  Offset to validate.
     * @param string     $message Error context for bounds violations.
     *
     * @return int Validated absolute offset.
     *
     * @throws BoundsError When the offset exceeds bounds.
     */
    private function normalizeAbsoluteOffset(int|UInt64 $offset, string $message): int
    {
        $limit = $this->offsetLimit();

        if ($offset instanceof UInt64) {
            if ($offset->compareInt($limit) > 0) {
                throw new BoundsError($message . ': ' . $offset->toHex(), 1018);
            }

            $offset = $offset->toInt($message);
        }

        if (($offset < 0) || ($offset > $limit)) {
            throw new BoundsError($message . ': ' . $this->formatOffset($offset), 1019);
        }

        return $offset;
    }

    /**
     * Normalizes a relative offset against a base position and validates bounds.
     *
     * @param int|UInt64 $offset  Relative offset to apply.
     * @param int        $base    Base position to offset from.
     * @param string     $message Error context for bounds violations.
     *
     * @return int Resolved absolute offset.
     *
     * @throws BoundsError When the resolved offset exceeds bounds.
     */
    private function normalizeRelativeOffset(int|UInt64 $offset, int $base, string $message): int
    {
        $limit  = $this->offsetLimit();
        $delta  = $this->resolveOffsetValue($offset, $message);

        if (($delta > 0) && ($delta > PHP_INT_MAX - $base)) {
            throw new BoundsError($message . ': ' . $this->formatOffset($offset), 1097);
        }

        $target = $base + $delta;

        if (($target < 0) || ($target > $limit)) {
            throw new BoundsError($message . ': ' . $this->formatOffset($offset), 1020);
        }

        return $target;
    }

    /**
     * Resolves a mixed offset value into a signed integer.
     *
     * @param int|UInt64 $offset  Offset to resolve.
     * @param string     $message Error context for bounds violations.
     *
     * @return int Resolved offset value.
     */
    private function resolveOffsetValue(int|UInt64 $offset, string $message): int
    {
        if ($offset instanceof UInt64) {
            return $offset->toInt($message);
        }

        return $offset;
    }

    /**
     * Determines whether a requested read length represents zero bytes.
     *
     * @param int|UInt64 $length Requested length.
     *
     * @return bool True when the requested length is zero.
     */
    protected function isZeroLength(int|UInt64 $length): bool
    {
        if ($length instanceof UInt64) {
            return $length->isZero();
        }

        return $length === 0;
    }

    /**
     * Normalizes a read length and enforces positive bounds.
     *
     * @param int|UInt64 $length  Requested length.
     * @param string     $context Error context for bounds violations.
     *
     * @return positive-int Validated positive length.
     *
     * @throws BoundsError When the length is zero, negative, or out of range.
     */
    private function normalizeReadLength(int|UInt64 $length, string $context): int
    {
        if ($length instanceof UInt64) {
            if ($length->isZero()) {
                throw new BoundsError($context . ': ' . $length->toHex(), 1021);
            }

            $length = $length->toInt($context);
        }

        if ($length <= 0) {
            throw new BoundsError($context . ': ' . $length, 1022);
        }

        return $length;
    }

    /**
     * Formats an offset value for diagnostics.
     *
     * @param int|UInt64 $offset Offset to format.
     *
     * @return string Formatted offset string.
     */
    private function formatOffset(int|UInt64 $offset): string
    {
        return $offset instanceof UInt64 ? $offset->toHex() : (string) $offset;
    }
}
