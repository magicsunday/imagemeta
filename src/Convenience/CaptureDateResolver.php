<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Convenience;

use DateTimeImmutable;
use MagicSunday\ImageMeta\Model\Metadata;
use Throwable;

/**
 * Resolves the best available capture timestamp for an image asset.
 *
 * The resolver prefers EXIF information as it typically offers the most
 * accurate capture metadata, but it can fall back to XMP when EXIF data is
 * missing or incomplete.
 */
final class CaptureDateResolver
{
    /**
     * Determines the most precise capture timestamp contained in the metadata.
     *
     * @param Metadata $metadata structured metadata extracted from the asset
     *
     * @return DateTimeImmutable|null a normalised timestamp or null when no usable
     *                                value is available
     */
    public static function bestCaptureDateTime(Metadata $metadata): ?DateTimeImmutable
    {
        if ($metadata->exifDoc !== null) {
            $dateTime = ExifConvenience::captureDateTime($metadata->exifDoc);

            if ($dateTime instanceof DateTimeImmutable) {
                return $dateTime;
            }
        }

        if ($metadata->xmpDoc !== null) {
            $createDate = $metadata->xmpDoc->createDate();

            if (is_string($createDate) && $createDate !== '') {
                try {
                    // XMP createDate is already ISO 8601, so we can consume it directly.
                    return new DateTimeImmutable($createDate);
                } catch (Throwable) {
                    // Ignore malformed timestamps and continue searching for fallbacks.
                }
            }
        }

        return null;
    }
}
