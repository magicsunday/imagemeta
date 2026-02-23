<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Exif\Factory;

use DateMalformedStringException;
use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\ImageMeta\Exif\Model\ParsedExif;
use MagicSunday\ImageMeta\Exif\ValueConverters;
use MagicSunday\ImageMeta\MakerNotes\Apple\Support\QuickTimeLookup;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Model\QuickTime\QuickTimeMeta;
use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;
use MagicSunday\ImageMeta\Model\Xmp\XmpNamespace;
use MagicSunday\ImageMeta\Value\Temporal;

use function abs;
use function intdiv;
use function is_string;
use function preg_match;
use function preg_replace;
use function sprintf;
use function str_pad;
use function substr;
use function trim;

/**
 * Factory for creating Temporal value objects from EXIF, QuickTime and XMP metadata.
 */
final readonly class TemporalFactory
{
    public function __construct(
        private ValueConverters $converters = new ValueConverters(),
    ) {
    }

    /**
     * Creates a Temporal value object from EXIF, QuickTime and XMP metadata.
     *
     * Fractional seconds are mirrored into the generic field to keep display values consistent
     * whenever only the original or digitized timestamp carries sub-second precision.
     *
     * @param Metadata $metadata Metadata container with decoded EXIF, XMP and QuickTime data.
     *
     * @return Temporal Normalised temporal metadata aggregate.
     */
    public function create(Metadata $metadata): Temporal
    {
        $exifDocument = $metadata->exifDoc;
        $quickTime    = $metadata->quickTime;
        $xmpDocument  = $metadata->xmpDoc ?? $metadata->selectiveXmpDocument();

        return $this->buildTemporal($exifDocument, $quickTime, $xmpDocument);
    }

    /**
     * Builds the temporal value object derived from EXIF, QuickTime and XMP data.
     *
     * @param ParsedExif|null    $exifDocument EXIF document exposing timestamps and offsets.
     * @param QuickTimeMeta|null $quickTime    QuickTime metadata used for time fallbacks.
     * @param XmpDocument|null   $xmpDocument  XMP document providing timestamp fields.
     *
     * @return Temporal Normalised temporal metadata aggregate.
     */
    private function buildTemporal(?ParsedExif $exifDocument, ?QuickTimeMeta $quickTime, ?XmpDocument $xmpDocument): Temporal
    {
        $exifCreate = $exifDocument?->dateTimeDigitized();
        $exifModify = $exifDocument?->dateTime();

        $xmpCreate = $this->parseFlexibleDate($xmpDocument?->string(XmpNamespace::XAP->value, 'CreateDate'))
            ?? $this->parseFlexibleDate($xmpDocument?->string(XmpNamespace::EXIF->value, 'CreateDate'));
        $xmpModify = $this->parseFlexibleDate($xmpDocument?->string(XmpNamespace::XAP->value, 'ModifyDate'))
            ?? $this->parseFlexibleDate($xmpDocument?->string(XmpNamespace::EXIF->value, 'ModifyDate'));
        $xmpDateCreated  = $this->parseFlexibleDate($xmpDocument?->string(XmpNamespace::PHOTOSHOP->value, 'DateCreated'));
        $lookup          = new QuickTimeLookup($quickTime);
        $quickTimeCreate = $this->parseFlexibleDate($lookup->string('CreationDate'));
        $quickTimeModify = $this->parseFlexibleDate($lookup->string('ModifyDate'));

        $create = $exifCreate ?? $xmpCreate ?? $quickTimeCreate ?? $xmpDateCreated;
        $modify = $exifModify ?? $xmpModify ?? $quickTimeModify;

        [$original, $tz, $subOriginalRaw] = $this->originalTimestampComponents($exifDocument);

        $originalWithTz = $original;
        if ($original instanceof DateTimeImmutable && $tz instanceof DateTimeZone) {
            $originalWithTz = $original->setTimezone($tz);
        }

        $offsetTime          = $exifDocument?->offsetTime();
        $offsetTimeOriginal  = $exifDocument?->offsetTimeOriginal();
        $offsetTimeDigitized = $exifDocument?->offsetTimeDigitized();

        $subSecTime          = $this->sanitizeSubSeconds($exifDocument?->subSecTime());
        $subSecTimeDigitized = $this->sanitizeSubSeconds($exifDocument?->subSecTimeDigitized());
        $subSecOriginal      = $this->sanitizeSubSeconds($subOriginalRaw);

        if ($subSecTime === null) {
            $subSecTime = $subSecOriginal ?? $subSecTimeDigitized;
        }

        $tzSource = null;

        if (
            ($tz instanceof DateTimeZone)
            && ($offsetTimeOriginal !== null)
            && ($this->converters->parseOffset($offsetTimeOriginal) instanceof DateTimeZone)
        ) {
            $tzSource = 'OffsetTimeOriginal';
        }

        return new Temporal(
            create: $create,
            modify: $modify,
            original: $originalWithTz,
            tz: $tz,
            tzSource: $tzSource,
            offsetTime: $offsetTime,
            offsetTimeOriginal: $offsetTimeOriginal,
            offsetTimeDigitized: $offsetTimeDigitized,
            subSecTime: $subSecTime,
            subSecTimeOriginal: $subSecOriginal,
            subSecTimeDigitized: $subSecTimeDigitized,
        );
    }

    /**
     * Extracts the original capture timestamp components from the EXIF document.
     *
     * @return array{0:?DateTimeImmutable,1:?DateTimeZone,2:?string}
     */
    private function originalTimestampComponents(?ParsedExif $document): array
    {
        if (!$document instanceof ParsedExif) {
            return [null, null, null];
        }

        $original = $document->dateTimeOriginalBestEffort();
        $offset   = $document->offsetTimeOriginal();

        if ($offset === null && $this->dateTimeStringEmpty($document->dateTimeOriginalRaw())) {
            $offset = $document->offsetTimeDigitized();
        }

        if (
            $offset === null
            && $this->dateTimeStringEmpty($document->dateTimeOriginalRaw())
            && $this->dateTimeStringEmpty($document->dateTimeDigitizedRaw())
        ) {
            $offset = $document->offsetTime();
        }

        $offsetString = $this->normaliseOffsetValue($offset);
        $timezone     = $this->converters->parseOffset($offsetString);

        if ($timezone instanceof DateTimeZone && $original instanceof DateTimeImmutable) {
            $original = $original->setTimezone($timezone);
        }

        $subSeconds = $document->subSecTimeOriginal();

        return [
            $original,
            $timezone instanceof DateTimeZone ? $timezone : null,
            $subSeconds,
        ];
    }

    /**
     * Determines whether an EXIF date/time string is empty after trimming whitespace.
     */
    private function dateTimeStringEmpty(?string $value): bool
    {
        return $value === null || trim($value) === '';
    }

    /**
     * Normalises textual or numeric offsets to a canonical ±HH:MM representation.
     */
    private function normaliseOffsetValue(int|string|null $offset): ?string
    {
        if ($offset === null) {
            return null;
        }

        if (is_string($offset)) {
            $trimmed = trim($offset);

            return $trimmed === '' ? null : $trimmed;
        }

        $absOffset = abs($offset);
        $hours     = $absOffset;
        $minutes   = 0;

        if ($absOffset > 14) {
            $hours   = intdiv($absOffset, 60);
            $minutes = $absOffset % 60;
        }

        if ($hours > 14 || ($hours === 14 && $minutes !== 0)) {
            return null;
        }

        $sign = $offset < 0 ? '-' : '+';

        return sprintf('%s%02d:%02d', $sign, $hours, $minutes);
    }

    /**
     * Normalises EXIF fractional second strings.
     *
     * @param string|null $value Raw fractional second string as stored in EXIF tags.
     *
     * @return string|null Cleaned fractional second string or null when empty.
     */
    private function sanitizeSubSeconds(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $digits = preg_replace('/\\D+/', '', $value);

        if ($digits === null || $digits === '') {
            return null;
        }

        $digits = substr($digits, 0, 3);

        return str_pad($digits, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Attempts to parse various ISO 8601 date representations.
     *
     * @param string|null $value Timestamp string in ISO 8601 format.
     *
     * @return DateTimeImmutable|null Parsed timestamp or null when parsing fails.
     */
    private function parseFlexibleDate(?string $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        // XMP Date value type: ISO 8601 subset (YYYY through YYYY-MM-DDThh:mm:ss.sTZD)
        if (preg_match('/^\d{4}(-\d{2}(-\d{2}(T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:\d{2})?)?)?)?$/', $value) !== 1) {
            return null;
        }

        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (DateMalformedStringException) {
            // XMP date formats may be incomplete or malformed; yield null for graceful degradation.
            return null;
        }
    }
}
