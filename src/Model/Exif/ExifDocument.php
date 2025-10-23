<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model\Exif;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\ImageMeta\MakerNotes\MakerNotesMetadata;

use function is_float;
use function is_int;
use function is_string;
use function rtrim;
use function str_replace;
use function substr;

/**
 * Represents a parsed EXIF payload and exposes convenience accessors.
 */
final readonly class ExifDocument
{
    /**
     * @param Ifd                     $ifd0       Root IFD of the TIFF structure.
     * @param Ifd|null                $exifIfd    Sub IFD containing EXIF-specific tags.
     * @param Ifd|null                $gpsIfd     Sub IFD containing GPS-related tags.
     * @param Ifd|null                $interopIfd Sub IFD containing interoperability tags.
     * @param Ifd|null                $ifd1       Optional next IFD, typically thumbnails.
     * @param MakerNotesMetadata|null $makerNotes Decoded maker note metadata provided by vendor decoders.
     */
    public function __construct(
        public Ifd $ifd0,
        public ?Ifd $exifIfd,
        public ?Ifd $gpsIfd,
        public ?Ifd $interopIfd,
        public ?Ifd $ifd1,
        public ?MakerNotesMetadata $makerNotes = null,
    ) {
    }

    /**
     * Returns the decoded maker note metadata when a decoder is available.
     */
    public function makerNotes(): ?MakerNotesMetadata
    {
        return $this->makerNotes;
    }

    /**
     * Returns the camera manufacturer string if present.
     *
     * @return string|null
     */
    public function cameraMake(): ?string
    {
        return $this->str($this->ifd0, ExifTag::MAKE);
    }

    /**
     * Returns the camera model string if present.
     *
     * @return string|null
     */
    public function cameraModel(): ?string
    {
        return $this->str($this->ifd0, ExifTag::MODEL);
    }

    /**
     * Returns the lens model string if present.
     *
     * @return string|null
     */
    public function lensModel(): ?string
    {
        return $this->str($this->exifIfd, ExifTag::LENS_MODEL);
    }

    /**
     * Returns the EXIF orientation value if present.
     *
     * @return int|null
     */
    public function orientation(): ?int
    {
        return $this->int($this->ifd0, ExifTag::ORIENTATION);
    }

    /**
     * Returns the ISO sensitivity value if present.
     *
     * @return int|null
     */
    public function iso(): ?int
    {
        // EXIF ISO tag (PhotographicSensitivity)
        return $this->int($this->exifIfd, ExifTag::PHOTOGRAPHIC_SENSITIVITY);
    }

    /**
     * Returns the exposure time in seconds if available.
     *
     * @return float|null
     */
    public function exposureTime(): ?float
    {
        // Tag ExifTag::EXPOSURE_TIME (RATIONAL)
        $entry = $this->exifIfd?->get(ExifTag::EXPOSURE_TIME);

        return $entry instanceof IfdEntry ? ValueConverters::rationalToFloat($entry->value) : null;
    }

    /**
     * Returns the aperture (f-number) if available.
     *
     * @return float|null
     */
    public function fNumber(): ?float
    {
        // Tag ExifTag::F_NUMBER (RATIONAL)
        $entry = $this->exifIfd?->get(ExifTag::F_NUMBER);

        return $entry instanceof IfdEntry ? ValueConverters::rationalToFloat($entry->value) : null;
    }

    /**
     * Returns the focal length in millimetres if available.
     *
     * @return float|null
     */
    public function focalLengthMm(): ?float
    {
        // Tag ExifTag::FOCAL_LENGTH (RATIONAL)
        $entry = $this->exifIfd?->get(ExifTag::FOCAL_LENGTH);

        return $entry instanceof IfdEntry ? ValueConverters::rationalToFloat($entry->value) : null;
    }

    /**
     * Returns the raw DateTimeOriginal tag value.
     *
     * @return string|null
     */
    public function dateTimeOriginalRaw(): ?string
    {
        return $this->str($this->exifIfd, ExifTag::DATETIME_ORIGINAL);
    }

    /**
     * Returns the raw offset time for DateTimeOriginal.
     *
     * @return string|null
     */
    public function offsetTimeOriginalRaw(): ?string
    {
        return $this->str($this->exifIfd, ExifTag::OFFSET_TIME_ORIGINAL);
    }

    /**
     * Returns the parsed GPS coordinates if present.
     *
     * @return array{lat:?float, lon:?float, alt:?float}
     */
    public function gps(): array
    {
        if (!$this->gpsIfd instanceof Ifd) {
            return ['lat' => null, 'lon' => null, 'alt' => null];
        }

        return ValueConverters::gpsFromIfd($this->gpsIfd);
    }

    /**
     * Returns a best-effort capture timestamp. Defaults to UTC when no offset tag is provided.
     *
     * @return DateTimeImmutable|null
     */
    public function captureDateTime(): ?DateTimeImmutable
    {
        $raw = $this->dateTimeOriginalRaw();
        if ($raw === null || $raw === '') {
            return null;
        }

        $offset = $this->offsetTimeOriginalRaw(); // like "+01:00"
        $tz     = ($offset !== null && $offset !== '') ? new DateTimeZone($offset) : new DateTimeZone('UTC');

        // EXIF uses "YYYY:MM:DD HH:MM:SS"
        $normalized = str_replace(':', '-', substr($raw, 0, 10)) . substr($raw, 10); // YYYY-MM-DD HH:MM:SS
        $dt         = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $normalized, $tz);

        return $dt !== false ? $dt : null;
    }

    /**
     * Returns a string value from the given IFD if present.
     *
     * @return string|null
     */
    private function str(?Ifd $ifd, int $tag): ?string
    {
        if (!$ifd instanceof Ifd) {
            return null;
        }

        $entry = $ifd->get($tag);
        if (!$entry instanceof IfdEntry) {
            return null;
        }

        $value = $entry->value;
        if (!is_string($value)) {
            return null;
        }

        return rtrim($value, "\0");
    }

    /**
     * Returns an integer value from the given IFD if present.
     *
     * @return int|null
     */
    private function int(?Ifd $ifd, int $tag): ?int
    {
        if (!$ifd instanceof Ifd) {
            return null;
        }

        $entry = $ifd->get($tag);
        if (!$entry instanceof IfdEntry) {
            return null;
        }

        $v = $entry->value;

        if ($v instanceof ExifNumericList) {
            $first = $v->values[0] ?? null;
            if (is_int($first)) {
                return $first;
            }

            if (is_float($first)) {
                return (int) $first;
            }

            return null;
        }

        if (is_int($v)) {
            return $v;
        }

        if (is_float($v)) {
            return (int) $v;
        }

        return null;
    }
}
