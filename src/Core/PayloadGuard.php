<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Core;

use MagicSunday\ImageMeta\Core\Util\Unpack;

use function sprintf;
use function strlen;
use function substr;

/**
 * Guard utility for minimum-length payload checks.
 */
final class PayloadGuard
{
    /**
     * Prevents instantiation of this utility class.
     */
    private function __construct()
    {
    }

    /**
     * Ensures that a binary payload meets a minimum byte length.
     *
     * @param string $payload   Raw binary payload bytes.
     * @param int    $minBytes  Minimum number of bytes required.
     * @param string $context   Human-readable context for the error message.
     * @param int    $errorCode ParseError error code.
     *
     * @throws ParseError If the payload is shorter than the required minimum.
     */
    public static function ensureMinimumLength(string $payload, int $minBytes, string $context, int $errorCode): void
    {
        if (strlen($payload) < $minBytes) {
            throw new ParseError(
                sprintf('%s is too short (need %d bytes, got %d)', $context, $minBytes, strlen($payload)),
                $errorCode,
            );
        }
    }

    /**
     * Normalizes a raw Exif payload by reading the 4-byte TIFF-header offset,
     * validating the TIFF signature, and returning the trimmed payload.
     *
     * Used by both ISO BMFF Exif items and JXL Exif boxes, which share
     * the same 4-byte-offset layout.
     *
     * @param string $blob        Raw Exif payload starting with a 4-byte BE offset.
     * @param string $contextName Human-readable context for error messages.
     * @param int    $lengthCode  Error code for minimum length violation.
     * @param int    $rangeCode   Error code for offset out-of-range violation.
     * @param int    $sigCode     Error code for invalid TIFF signature.
     *
     * @return string Exif payload trimmed to the TIFF header.
     */
    public static function normalizeExifBlob(
        string $blob,
        string $contextName,
        int $lengthCode,
        int $rangeCode,
        int $sigCode,
    ): string {
        self::ensureMinimumLength($blob, 4, $contextName . ' payload', $lengthCode);

        $offset  = Unpack::int('N', substr($blob, 0, 4), $contextName . ' TIFF-header offset');

        if (($offset < 0) || ((4 + $offset + 2) > strlen($blob))) {
            throw new ParseError($contextName . ' TIFF-header offset out of range', $rangeCode);
        }

        $tiffSig = substr($blob, 4 + $offset, 2);

        if (($tiffSig !== 'II') && ($tiffSig !== 'MM')) {
            throw new ParseError($contextName . ' TIFF-header offset does not point to valid TIFF signature', $sigCode);
        }

        return substr($blob, 4 + $offset);
    }
}
