<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Tiff;

use MagicSunday\ImageMeta\Core\BinaryReadAccessInterface;
use MagicSunday\ImageMeta\Core\BoundsError;
use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\Util\UInt64;

use function is_int;
use function is_string;
use function ltrim;
use function sprintf;
use function strlen;
use function strspn;

/**
 * Validates TIFF blob offsets against buffer bounds.
 *
 * TIFF 6.0 §2 requires all offsets to lie within the file boundary. This validator
 * enforces strict bounds checks for both classic TIFF and BigTIFF offsets.
 */
final readonly class TiffOffsetValidator
{
    /**
     * @param BinaryReadAccessInterface $buffer   Seekable binary source for size queries.
     * @param UInt64                    $blobSize Total blob size for bounds checks.
     */
    public function __construct(
        private BinaryReadAccessInterface $buffer,
        private UInt64 $blobSize,
    ) {
    }

    /**
     * Ensures that an offset lies within the TIFF blob and returns it as an integer.
     *
     * @param int|UInt64|string $offset  Candidate offset value.
     * @param string            $context Description for error messages.
     * @param int               $length  Optional data length for bounds check.
     */
    public function ensureOffset(int|UInt64|string $offset, string $context, int $length = 0): int
    {
        if (is_string($offset)) {
            return $this->ensureDecimalOffset($offset, $context, $length);
        }

        $offset64 = $offset instanceof UInt64 ? $offset : UInt64::fromInt($offset);

        $this->assertOffsetRange($offset64, $length, $context);

        return $offset64->toInt($context);
    }

    /**
     * Normalizes an optional offset that may be zero.
     *
     * @param UInt64 $offset  Candidate offset.
     * @param string $context Description for error messages.
     */
    public function normalizeOptionalOffset(UInt64 $offset, string $context): int
    {
        if ($offset->isZero()) {
            return 0;
        }

        return $this->ensureOffset($offset, $context);
    }

    /**
     * Normalizes a BigTIFF optional offset according to the configured field width.
     *
     * @param int|UInt64|string $offset  Candidate offset.
     * @param string            $context Description for error messages.
     */
    public function normalizeBigTiffOptionalOffset(int|UInt64|string $offset, string $context): int
    {
        if ($offset instanceof UInt64) {
            return $this->normalizeOptionalOffset($offset, $context);
        }

        if (is_int($offset)) {
            if ($offset <= 0) {
                return 0;
            }

            return $this->ensureOffset($offset, $context);
        }

        if ($this->decimalStringIsZero($offset)) {
            return 0;
        }

        return $this->ensureOffset($offset, $context);
    }

    /**
     * Determines whether a decimal string represents zero.
     *
     * @param string $value Decimal string to check.
     */
    public function decimalStringIsZero(string $value): bool
    {
        $length = strlen($value);

        for ($i = 0; $i < $length; ++$i) {
            if ($value[$i] !== '0') {
                return false;
            }
        }

        return true;
    }

    /**
     * Verifies that an offset and optional length are contained within the TIFF blob.
     *
     * @param UInt64 $offset  Candidate offset.
     * @param int    $length  Data length for bounds check.
     * @param string $context Description for error messages.
     */
    private function assertOffsetRange(UInt64 $offset, int $length, string $context): void
    {
        if ($offset->compare($this->blobSize) > 0) {
            throw new BoundsError(sprintf('%s exceeds TIFF data length.', $context), 1333);
        }

        $size      = $this->buffer->size();

        if ($length > $size) {
            throw new BoundsError(sprintf('%s length %d exceeds TIFF data length.', $context, $length), 1334);
        }

        $offsetInt = $offset->toInt($context);

        if (($length > 0) && ($offsetInt > ($size - $length))) {
            throw new BoundsError(sprintf('%s exceeds TIFF data length.', $context), 1335);
        }
    }

    /**
     * Ensures that a decimal offset lies within the TIFF blob and returns it as an integer.
     *
     * @param string $offset  Decimal string offset.
     * @param string $context Description for error messages.
     * @param int    $length  Data length for bounds check.
     */
    private function ensureDecimalOffset(string $offset, string $context, int $length): int
    {
        $normalized = $this->normalizeDecimalString($offset);
        $size       = $this->buffer->size();

        if ($this->compareDecimalStringToInt($normalized, $size) > 0) {
            throw new BoundsError(sprintf('%s exceeds TIFF data length.', $context), 1344);
        }

        if ($length > $size) {
            throw new BoundsError(sprintf('%s length %d exceeds TIFF data length.', $context, $length), 1345);
        }

        if ($length > 0) {
            $limit = $size - $length;

            if ($this->compareDecimalStringToInt($normalized, $limit) > 0) {
                throw new BoundsError(sprintf('%s exceeds TIFF data length.', $context), 1346);
            }
        }

        return (int) $normalized;
    }

    /**
     * Normalizes a decimal string by validating its characters and removing leading zeros.
     *
     * @param string $value Decimal string to normalize.
     */
    private function normalizeDecimalString(string $value): string
    {
        if ($value === '') {
            throw new ParseError('Decimal offset must not be empty.', 1348);
        }

        if (strspn($value, '0123456789') !== strlen($value)) {
            throw new ParseError('Decimal offset contains invalid characters.', 1349);
        }

        $trimmed = ltrim($value, '0');

        return $trimmed === '' ? '0' : $trimmed;
    }

    /**
     * Compares a decimal string against a non-negative integer.
     *
     * @param string $decimal Normalized decimal string.
     * @param int    $int     Non-negative integer.
     */
    private function compareDecimalStringToInt(string $decimal, int $int): int
    {
        if ($int < 0) {
            return 1;
        }

        $intString = $int === 0 ? '0' : ltrim((string) $int, '0');
        $decLen    = strlen($decimal);
        $intLen    = strlen($intString);

        if ($decLen !== $intLen) {
            return $decLen <=> $intLen;
        }

        return $decimal <=> $intString;
    }
}
