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
use MagicSunday\ImageMeta\Value\Gps;

/**
 * Resolves the best available capture timestamp for an image asset using EXIF sources only.
 */
final class CaptureDateResolver
{
    /**
     * Determines the most precise capture timestamp contained in the metadata.
     */
    public static function bestCaptureDateTime(Metadata $metadata): ?DateTimeImmutable
    {
        $exif = $metadata->exifDoc;
        if ($exif !== null) {
            $candidate = $exif->captureDateTime()
                ?? $exif->dateTimeOriginalBestEffort()
                ?? $exif->dateTimeDigitized();

            if ($candidate instanceof DateTimeImmutable) {
                return $candidate;
            }
        }

        return self::gpsFallback($metadata->structured()->gps);
    }

    private static function gpsFallback(Gps $gps): ?DateTimeImmutable
    {
        return $gps->timestamp;
    }
}
