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
use Throwable;

use function is_array;
use function is_string;
use function preg_match;
use function trim;

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
            $createDate = self::readXmpCreateDate($metadata->xmpDoc);

            if ($createDate !== null) {
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

    /**
     * Extracts the ISO 8601 create date from the XMP document.
     *
     * @param XmpDocument $document XMP document holding metadata properties.
     *
     * @return string|null ISO 8601 timestamp or null when unavailable.
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
