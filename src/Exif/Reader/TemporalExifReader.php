<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Exif\Reader;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\ImageMeta\Core\Util\UInt64;
use MagicSunday\ImageMeta\Exif\Model\ExifNumericList;
use MagicSunday\ImageMeta\Exif\Model\ExifRational;
use MagicSunday\ImageMeta\Exif\Model\ExifRationalList;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\FallbackIfdSet;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdValueReader;
use MagicSunday\ImageMeta\Exif\ValueConverters;

use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function preg_match;
use function preg_replace;
use function rtrim;
use function str_pad;
use function str_replace;
use function strlen;
use function substr;
use function trim;

/**
 * Reads temporal/date-time metadata from EXIF IFDs.
 *
 * EXIF 3.0 §4.6.5.4.5 / §4.6.6.6.1–§4.6.6.6.5 define the DateTime, DateTimeOriginal,
 * DateTimeDigitized, OffsetTime*, and SubSecTime* tags decoded by this reader.
 */
final readonly class TemporalExifReader
{
    /**
     * @param IfdValueReader  $reader       Value reader for IFD tag extraction.
     * @param ValueConverters $converters   Value converter facade for EXIF type normalization.
     * @param Ifd|null        $exifIfd      Sub IFD containing EXIF-specific tags.
     * @param Ifd             $ifd0         Root IFD of the TIFF structure.
     * @param FallbackIfdSet  $fallbackIfds Fallback IFD resolution set.
     * @param GpsExifReader   $gpsReader    GPS reader for timestamp fallback.
     */
    public function __construct(
        private IfdValueReader $reader,
        private ValueConverters $converters,
        private ?Ifd $exifIfd,
        private Ifd $ifd0,
        private FallbackIfdSet $fallbackIfds,
        private GpsExifReader $gpsReader,
    ) {
    }

    /**
     * Returns the raw DateTimeOriginal tag value.
     *
     * EXIF 3.0 §4.6.6.6.1 describes DateTimeOriginal as a 20-byte ASCII
     * timestamp (including the terminating NULL) formatted as
     * "YYYY:MM:DD HH:MM:SS".
     */
    public function dateTimeOriginalRaw(): ?string
    {
        return $this->resolveWithFallback($this->reader->str(...), ExifTag::DATETIME_ORIGINAL);
    }

    /**
     * Returns the DateTimeOriginal tag combined with fractional seconds and offsets when available.
     */
    public function dateTimeOriginal(): ?DateTimeImmutable
    {
        $dateTime = $this->parseExifDateTime(
            $this->dateTimeOriginalRaw(),
            $this->offsetTimeOriginalRaw(),
            $this->subSecTimeOriginal(),
        );

        if ($dateTime instanceof DateTimeImmutable) {
            return $dateTime;
        }

        $digitized = $this->dateTimeDigitized();

        if ($digitized instanceof DateTimeImmutable) {
            return $digitized;
        }

        return $this->captureDateTime();
    }

    /**
     * Returns the most appropriate capture timestamp prioritising DateTimeOriginal metadata.
     */
    public function dateTimeOriginalBestEffort(): ?DateTimeImmutable
    {
        $original = $this->dateTimeOriginal();

        if ($original instanceof DateTimeImmutable) {
            return $original;
        }

        $digitized = $this->dateTimeDigitized();

        if ($digitized instanceof DateTimeImmutable) {
            return $digitized;
        }

        return $this->captureDateTime();
    }

    /**
     * Returns the fractional seconds associated with DateTimeOriginal.
     */
    public function subSecTimeOriginal(): ?string
    {
        return $this->reader->sanitizedSubSec($this->exifIfd, ExifTag::SUB_SEC_TIME_ORIGINAL);
    }

    /**
     * Returns the raw DateTimeDigitized tag value.
     *
     * EXIF 3.0 §4.6.6.6.2 documents DateTimeDigitized as a 20-byte ASCII
     * timestamp (including the terminating NULL) formatted as
     * "YYYY:MM:DD HH:MM:SS".
     */
    public function dateTimeDigitizedRaw(): ?string
    {
        return $this->resolveWithFallback($this->reader->str(...), ExifTag::DATETIME_DIGITIZED);
    }

    /**
     * Returns the fractional seconds for DateTimeDigitized.
     */
    public function subSecTimeDigitized(): ?string
    {
        return $this->reader->sanitizedSubSec($this->exifIfd, ExifTag::SUB_SEC_TIME_DIGITIZED);
    }

    /**
     * Returns the raw ModifyDate (legacy DateTime) tag value from IFD0.
     */
    public function dateTimeRaw(): ?string
    {
        return $this->reader->str($this->ifd0, ExifTag::DATETIME);
    }

    /**
     * Returns the fractional seconds for the ModifyDate/DateTime tag.
     */
    public function subSecTime(): ?string
    {
        return $this->reader->sanitizedSubSec($this->exifIfd, ExifTag::SUB_SEC_TIME);
    }

    /**
     * Returns the normalized offset time for DateTimeOriginal.
     *
     * EXIF 3.0 §4.6.6.6.4 defines OffsetTimeOriginal as an ASCII string of
     * length 7 (including the terminating NULL) using the format "±HH:MM".
     */
    public function offsetTimeOriginal(): ?string
    {
        return $this->resolveWithFallback($this->normalizedOffset(...), ExifTag::OFFSET_TIME_ORIGINAL);
    }

    /**
     * Returns the normalized offset time for DateTimeDigitized.
     *
     * EXIF 3.0 §4.6.6.6.5 defines OffsetTimeDigitized as an ASCII string of
     * length 7 (including the terminating NULL) using the format "±HH:MM".
     */
    public function offsetTimeDigitized(): ?string
    {
        return $this->resolveWithFallback($this->normalizedOffset(...), ExifTag::OFFSET_TIME_DIGITIZED);
    }

    /**
     * Returns the normalized offset time for the IFD0 ModifyDate/DateTime tag.
     *
     * EXIF 3.0 §4.6.6.6.3 defines OffsetTime as an ASCII string of length 7
     * (including the terminating NULL) using the format "±HH:MM".
     */
    public function offsetTime(): ?string
    {
        return $this->resolveWithFallback($this->normalizedOffset(...), ExifTag::OFFSET_TIME);
    }

    /**
     * Returns a best-effort absolute capture timestamp.
     *
     * EXIF DateTime* values without OffsetTime* remain local/offset-unknown and
     * are therefore not converted into an absolute instant here.
     */
    public function captureDateTime(): ?DateTimeImmutable
    {
        $offsetOriginal  = $this->offsetTimeOriginalRaw();
        $offsetDigitized = $this->offsetTimeDigitizedRaw();
        $offset          = $this->offsetTimeRaw();

        $attempts = [
            [
                $this->dateTimeOriginalRaw(),
                $offsetOriginal,
                $this->subSecTimeOriginal(),
            ],
            [
                $this->dateTimeDigitizedRaw(),
                $offsetDigitized,
                $this->subSecTimeDigitized(),
            ],
            [
                $this->dateTimeRaw(),
                $offset,
                $this->subSecTime(),
            ],
        ];

        foreach ($attempts as [$raw, $rawOffset, $subSeconds]) {
            $dateTime = $this->parseExifDateTime($raw, $rawOffset, $subSeconds);

            if ($dateTime instanceof DateTimeImmutable) {
                return $dateTime;
            }
        }

        return $this->gpsReader->gpsTimestamp();
    }

    /**
     * Returns the digitised timestamp combining the raw value and offset tags.
     */
    public function dateTimeDigitized(): ?DateTimeImmutable
    {
        return $this->parseExifDateTime(
            $this->dateTimeDigitizedRaw(),
            $this->offsetTimeDigitizedRaw(),
            $this->subSecTimeDigitized(),
        );
    }

    /**
     * Returns the ModifyDate/DateTime tag combined with its optional offset.
     *
     * EXIF 3.0 §4.6.5.4.5 defines DateTime as "YYYY:MM:DD HH:MM:SS" with
     * blank-filled placeholders treated as unknown values.
     */
    public function dateTime(): ?DateTimeImmutable
    {
        return $this->parseExifDateTime(
            $this->dateTimeRaw(),
            $this->offsetTimeRaw(),
            $this->subSecTime(),
        );
    }

    /**
     * Normalizes EXIF timestamp strings and optional offsets into immutable datetime instances.
     *
     * @param string|null $rawDateTime Raw EXIF datetime formatted as "YYYY:MM:DD HH:MM:SS".
     * @param string|null $rawOffset   Optional timezone offset such as "+01:00".
     * @param string|null $subSeconds  Optional fractional seconds.
     */
    private function parseExifDateTime(?string $rawDateTime, ?string $rawOffset, ?string $subSeconds): ?DateTimeImmutable
    {
        $rawDateTime = rtrim($rawDateTime ?? '', " \0");

        if ($rawDateTime === '' || strlen($rawDateTime) < 19) {
            return null;
        }

        $rawDateTime = substr($rawDateTime, 0, 19);

        // EXIF 3.0 §4.6.5.4.5 / §4.6.6.6.1 / §4.6.6.6.2: strict "YYYY:MM:DD HH:MM:SS"
        if (preg_match('/\A\d{4}:\d{2}:\d{2} \d{2}:\d{2}:\d{2}\z/', $rawDateTime) !== 1) {
            return null;
        }

        // EXIF DateTime* tags are local date/time values; without OffsetTime*
        // the absolute instant is undefined and is intentionally not inferred.
        if ($rawOffset === null || trim($rawOffset) === '') {
            return null;
        }

        $timeZone = $this->converters->parseOffset($rawOffset);

        if (!$timeZone instanceof DateTimeZone) {
            return null;
        }

        $normalized = str_replace(':', '-', substr($rawDateTime, 0, 10)) . substr($rawDateTime, 10);
        $format     = 'Y-m-d H:i:s';

        if (($subSeconds !== null) && ($subSeconds !== '')) {
            $digits = preg_replace('/\D/', '', $subSeconds);

            if (($digits !== null) && ($digits !== '')) {
                $digits = substr($digits, 0, 6);
                $digits = str_pad($digits, 6, '0');
                $normalized .= '.' . $digits;
                $format .= '.u';
            }
        }

        $dt = DateTimeImmutable::createFromFormat($format, $normalized, $timeZone);

        if ($dt === false) {
            return null;
        }

        $lastErrors = DateTimeImmutable::getLastErrors();

        if (is_array($lastErrors) && (
            $lastErrors['warning_count'] > 0
            || $lastErrors['error_count'] > 0
        )) {
            return null;
        }

        return $dt;
    }

    /**
     * Returns the raw OffsetTimeOriginal tag value without EXIF normalization.
     */
    private function offsetTimeOriginalRaw(): ?string
    {
        return $this->resolveWithFallback($this->reader->rawOffset(...), ExifTag::OFFSET_TIME_ORIGINAL);
    }

    /**
     * Returns the raw OffsetTimeDigitized tag value without EXIF normalization.
     */
    private function offsetTimeDigitizedRaw(): ?string
    {
        return $this->resolveWithFallback($this->reader->rawOffset(...), ExifTag::OFFSET_TIME_DIGITIZED);
    }

    /**
     * Returns the raw OffsetTime tag value without EXIF normalization.
     */
    private function offsetTimeRaw(): ?string
    {
        return $this->resolveWithFallback($this->reader->rawOffset(...), ExifTag::OFFSET_TIME);
    }

    /**
     * Tries the exifIfd first, then iterates fallback IFDs, returning the first non-null result.
     *
     * @param Closure(Ifd|null, int): ?string $extractor Tag value extraction callback.
     * @param int                             $tag       EXIF tag constant.
     */
    private function resolveWithFallback(Closure $extractor, int $tag): ?string
    {
        $value = $extractor($this->exifIfd, $tag);

        if ($value !== null) {
            return $value;
        }

        foreach ($this->fallbackIfds->resolve(includeIfd0: true) as $ifd) {
            $candidate = $extractor($ifd, $tag);

            if ($candidate !== null) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Normalizes textual and numeric offset encodings to a canonical string representation.
     */
    private function normalizedOffset(?Ifd $ifd, int $tag): ?string
    {
        $value = $this->reader->value($ifd, $tag);

        if ($value instanceof ExifNumericList) {
            $value = $value->values[0] ?? null;

            if ($value instanceof UInt64) {
                if (!$value->fitsSignedInt()) {
                    return null;
                }

                $value = $value->toInt('EXIF offset normalisation');
            }
        } elseif ($value instanceof ExifRationalList || $value instanceof ExifRational) {
            $value = $this->converters->rationalToFloat($value);
        }

        if (is_string($value)) {
            $trimmed = rtrim(trim($value), "\0");

            if ($trimmed === '') {
                return null;
            }

            // EXIF 3.0 §4.6.6.6.3–§4.6.6.6.5: OffsetTime tags are ASCII strings
            // formatted as "±HH:MM". Reject non-conformant string encodings.
            if (preg_match('/\A[+-]\d{2}:\d{2}\z/', $trimmed) !== 1) {
                return null;
            }

            $value = $trimmed;
        }

        if (!is_int($value) && !is_float($value) && !is_string($value)) {
            return null;
        }

        return $this->converters->parseOffsetString($value);
    }
}
