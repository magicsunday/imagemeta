<?php
declare(strict_types=1);

namespace MagicSunday\ImageMeta\Convenience;

use DateTimeImmutable;
use MagicSunday\ImageMeta\Model\Metadata;

final class CaptureDateResolver
{
    public static function bestCaptureDateTime(Metadata $m): ?DateTimeImmutable
    {
        if ($m->exifDoc) {
            $dt = ExifConvenience::captureDateTime($m->exifDoc);
            if ($dt) return $dt;
        }
        if ($m->xmpDoc && ($iso = $m->xmpDoc->createDate())) {
            try { return new DateTimeImmutable($iso); } catch (\Throwable) {}
        }
        return null;
    }
}
