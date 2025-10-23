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
     * @param Ifd      $ifd0       Root IFD of the TIFF structure.
     * @param Ifd|null $exifIfd    Sub IFD containing EXIF-specific tags.
     * @param Ifd|null $gpsIfd     Sub IFD containing GPS-related tags.
     * @param Ifd|null $interopIfd Sub IFD containing interoperability tags.
     * @param Ifd|null $ifd1       Optional next IFD, typically thumbnails.
     */
    public function __construct(
        public readonly Ifd $ifd0,
        public readonly ?Ifd $exifIfd,
        public readonly ?Ifd $gpsIfd,
        public readonly ?Ifd $interopIfd,
        public readonly ?Ifd $ifd1,
    ) {
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
        $v = $this->exifIfd?->get(ExifTag::EXPOSURE_TIME)?->value ?? null;

        return ValueConverters::rationalToFloat($v);
    }

    /**
     * Returns the aperture (f-number) if available.
     *
     * @return float|null
     */
    public function fNumber(): ?float
    {
        // Tag ExifTag::F_NUMBER (RATIONAL)
        $v = $this->exifIfd?->get(ExifTag::F_NUMBER)?->value ?? null;

        return ValueConverters::rationalToFloat($v);
    }

    /**
     * Returns the focal length in millimetres if available.
     *
     * @return float|null
     */
    public function focalLengthMm(): ?float
    {
        // Tag ExifTag::FOCAL_LENGTH (RATIONAL)
        $v = $this->exifIfd?->get(ExifTag::FOCAL_LENGTH)?->value ?? null;

        return ValueConverters::rationalToFloat($v);
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
        if (!$this->gpsIfd) {
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
        if (!$raw) {
            return null;
        }
        $offset = $this->offsetTimeOriginalRaw(); // like "+01:00"
        $tz     = $offset ? new DateTimeZone($offset) : new DateTimeZone('UTC');

        // EXIF uses "YYYY:MM:DD HH:MM:SS"
        $normalized = str_replace(':', '-', substr($raw, 0, 10)) . substr($raw, 10); // YYYY-MM-DD HH:MM:SS
        $dt         = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $normalized, $tz);

        return $dt ?: null;
    }

    /**
     * Returns a string value from the given IFD if present.
     *
     * @return string|null
     */
    private function str(?Ifd $ifd, int $tag): ?string
    {
        $e = $ifd?->get($tag);

        return is_string($e?->value) ? rtrim($e->value, "\0") : null;
    }

    /**
     * Returns an integer value from the given IFD if present.
     *
     * @return int|null
     */
    private function int(?Ifd $ifd, int $tag): ?int
    {
        $v = $ifd?->get($tag)?->value ?? null;

        return is_int($v) ? $v : (is_float($v) ? (int) $v : null);
    }
}
