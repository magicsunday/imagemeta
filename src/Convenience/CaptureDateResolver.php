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
use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;
use MagicSunday\ImageMeta\Value\Capture;
use MagicSunday\ImageMeta\Value\Gps;
use MagicSunday\ImageMeta\Value\Temporal;
use Throwable;

use function is_array;
use function is_string;
use function preg_match;
use function trim;

/**
 * Resolves the best available capture timestamp for an image asset.
 */
final class CaptureDateResolver
{
    /**
     * Determines the most precise capture timestamp contained in the metadata.
     */
    public static function bestCaptureDateTime(Metadata $metadata): ?DateTimeImmutable
    {
        $structured = $metadata->structured();

        $capture  = $structured->capture();
        $temporal = $structured->temporal();
        $gps      = $structured->gps;

        $candidate = self::captureDate($capture)
            ?? self::temporalFallback($temporal)
            ?? self::gpsFallback($gps);

        if ($candidate instanceof DateTimeImmutable) {
            return $candidate;
        }

        $xmpDocument = $metadata->xmpDoc;
        if (!$xmpDocument instanceof XmpDocument) {
            $xmpDocument = $metadata->selectiveXmpDocument();
        }

        if ($xmpDocument instanceof XmpDocument) {
            $createDate = self::readXmpCreateDate($xmpDocument);

            if ($createDate !== null) {
                try {
                    return new DateTimeImmutable($createDate);
                } catch (Throwable) {
                    // Ignore malformed timestamps and continue searching for fallbacks.
                }
            }
        }

        return null;
    }

    private static function captureDate(Capture $capture): ?DateTimeImmutable
    {
        return $capture->dateTime;
    }

    private static function temporalFallback(Temporal $temporal): ?DateTimeImmutable
    {
        $candidates = [
            $temporal->original,
            $temporal->create,
            $temporal->modify,
        ];

        foreach ($candidates as $candidate) {
            if ($candidate instanceof DateTimeImmutable) {
                return $candidate;
            }
        }

        return null;
    }

    private static function gpsFallback(Gps $gps): ?DateTimeImmutable
    {
        return $gps->timestamp;
    }

    /**
     * Extracts the ISO 8601 create date from the XMP document.
     */
    private static function readXmpCreateDate(XmpDocument $document): ?string
    {
        $value = $document->get('http://ns.adobe.com/xap/1.0/', 'CreateDate');

        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (preg_match(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+\-]\d{2}:?\d{2})$/',
            $value
        ) !== 1) {
            return null;
        }

        return $value;
    }
}
