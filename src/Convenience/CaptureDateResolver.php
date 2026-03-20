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
use MagicSunday\ImageMeta\Core\Util\DateTimeUtil;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;
use MagicSunday\ImageMeta\Model\Xmp\XmpNamespace;
use MagicSunday\ImageMeta\Value\Temporal;

use function array_find;
use function is_array;
use function is_string;

/**
 * Resolves the best available capture timestamp for an image asset.
 */
final readonly class CaptureDateResolver
{
    /**
     * Determines the most precise capture timestamp contained in the metadata.
     */
    public function bestCaptureDateTime(Metadata $metadata): ?DateTimeImmutable
    {
        $structured = $metadata->structured();

        $capture  = $structured->locationTime->capture;
        $temporal = $structured->locationTime->temporal;
        $gps      = $structured->locationTime->gps;

        $candidate = $capture->dateTime
            ?? $this->temporalFallback($temporal)
            ?? $gps->timing?->timestamp;

        if ($candidate instanceof DateTimeImmutable) {
            return $candidate;
        }

        $xmpDocument = $metadata->xmpDoc;

        if (!$xmpDocument instanceof XmpDocument) {
            $xmpDocument = $metadata->selectiveXmpDocument();
        }

        if ($xmpDocument instanceof XmpDocument) {
            $createDate = $this->readXmpCreateDate($xmpDocument);

            if ($createDate instanceof DateTimeImmutable) {
                return $createDate;
            }
        }

        return null;
    }

    /**
     * Returns the first temporal timestamp candidate when available.
     *
     * @param Temporal $temporal Structured temporal data.
     *
     * @return DateTimeImmutable|null Temporal fallback timestamp or null.
     */
    private function temporalFallback(Temporal $temporal): ?DateTimeImmutable
    {
        $candidates = [
            $temporal->original,
            $temporal->create,
            $temporal->modify,
        ];

        return array_find(
            $candidates,
            fn ($candidate): bool => $candidate instanceof DateTimeImmutable
        );
    }

    /**
     * Extracts the ISO 8601 create date from the XMP document.
     */
    private function readXmpCreateDate(XmpDocument $document): ?DateTimeImmutable
    {
        $value = $document->get(XmpNamespace::XAP->value, 'CreateDate');

        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        if (!is_string($value)) {
            return null;
        }

        return DateTimeUtil::parseIso8601($value);
    }
}
