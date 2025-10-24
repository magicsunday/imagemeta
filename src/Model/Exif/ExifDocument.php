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
use Exception;
use MagicSunday\ImageMeta\MakerNotes\MakerNotesMetadata;

use function is_float;
use function is_int;
use function is_string;
use function rtrim;
use function str_replace;
use function strlen;
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
     * Returns the camera owner name if present.
     *
     * @return string|null
     */
    public function ownerName(): ?string
    {
        return $this->str($this->exifIfd, ExifTag::CAMERA_OWNER_NAME);
    }

    /**
     * Returns the camera body serial number if present.
     *
     * @return string|null
     */
    public function bodySerialNumber(): ?string
    {
        return $this->str($this->exifIfd, ExifTag::BODY_SERIAL_NUMBER);
    }

    /**
     * Returns the lens serial number if present.
     *
     * @return string|null
     */
    public function lensSerialNumber(): ?string
    {
        return $this->str($this->exifIfd, ExifTag::LENS_SERIAL_NUMBER);
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
     * Returns the image width, preferring the EXIF-specific tag and falling back to IFD0.
     *
     * @return int|null
     */
    public function imageWidth(): ?int
    {
        $width = $this->int($this->exifIfd, ExifTag::EXIF_IMAGE_WIDTH);

        return $width ?? $this->int($this->ifd0, ExifTag::IMAGE_WIDTH);
    }

    /**
     * Returns the image height, preferring the EXIF-specific tag and falling back to IFD0.
     *
     * @return int|null
     */
    public function imageHeight(): ?int
    {
        $height = $this->int($this->exifIfd, ExifTag::EXIF_IMAGE_HEIGHT);

        return $height ?? $this->int($this->ifd0, ExifTag::IMAGE_HEIGHT);
    }

    /**
     * Returns the colour space identifier if present.
     *
     * @return int|null
     */
    public function colorSpace(): ?int
    {
        return $this->int($this->exifIfd, ExifTag::COLOR_SPACE);
    }

    /**
     * Returns the image unique identifier if present.
     *
     * @return string|null
     */
    public function imageUniqueId(): ?string
    {
        return $this->str($this->exifIfd, ExifTag::IMAGE_UNIQUE_ID);
    }

    /**
     * Returns the ISO sensitivity value if present.
     *
     * @return int|null
     */
    public function iso(): ?int
    {
        $iso = $this->int($this->exifIfd, ExifTag::ISO_SPEED);
        if ($iso !== null) {
            return $iso;
        }

        $iso = $this->int($this->exifIfd, ExifTag::PHOTOGRAPHIC_SENSITIVITY);
        if ($iso !== null) {
            return $iso;
        }

        return $this->int($this->ifd0, ExifTag::PHOTOGRAPHIC_SENSITIVITY);
    }

    /**
     * Returns the exposure time in seconds if available.
     *
     * @return float|null
     */
    public function exposureTime(): ?float
    {
        return $this->rational($this->exifIfd, ExifTag::EXPOSURE_TIME);
    }

    /**
     * Returns the aperture (f-number) if available.
     *
     * @return float|null
     */
    public function fNumber(): ?float
    {
        return $this->rational($this->exifIfd, ExifTag::F_NUMBER);
    }

    /**
     * Returns the focal length in millimetres if available.
     *
     * @return float|null
     */
    public function focalLengthMm(): ?float
    {
        return $this->rational($this->exifIfd, ExifTag::FOCAL_LENGTH);
    }

    /**
     * Returns the focal length in 35mm equivalent if available.
     *
     * @return int|null
     */
    public function focalLength35Mm(): ?int
    {
        return $this->int($this->exifIfd, ExifTag::FOCAL_LENGTH_IN_35MM_FILM);
    }

    /**
     * Returns the camera exposure program code if present.
     *
     * @return int|null
     */
    public function exposureProgram(): ?int
    {
        return $this->int($this->exifIfd, ExifTag::EXPOSURE_PROGRAM);
    }

    /**
     * Returns the metering mode code if present.
     *
     * @return int|null
     */
    public function meteringMode(): ?int
    {
        return $this->int($this->exifIfd, ExifTag::METERING_MODE);
    }

    /**
     * Returns the flash status flags if present.
     *
     * @return int|null
     */
    public function flash(): ?int
    {
        return $this->int($this->exifIfd, ExifTag::FLASH);
    }

    /**
     * Returns the white balance mode if present.
     *
     * @return int|null
     */
    public function whiteBalance(): ?int
    {
        return $this->int($this->exifIfd, ExifTag::WHITE_BALANCE);
    }

    /**
     * Returns the exposure bias value in EV if present.
     *
     * @return float|null
     */
    public function exposureBias(): ?float
    {
        return $this->rational($this->exifIfd, ExifTag::EXPOSURE_BIAS_VALUE);
    }

    /**
     * Returns the scene brightness value (APEX) if present.
     *
     * @return float|null
     */
    public function brightnessValue(): ?float
    {
        return $this->rational($this->exifIfd, ExifTag::BRIGHTNESS_VALUE);
    }

    /**
     * Returns the maximum aperture value (APEX) if present.
     *
     * @return float|null
     */
    public function maxApertureApex(): ?float
    {
        return $this->rational($this->exifIfd, ExifTag::MAX_APERTURE_VALUE);
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
     * Returns the raw DateTimeDigitized tag value.
     *
     * @return string|null
     */
    public function dateTimeDigitizedRaw(): ?string
    {
        return $this->str($this->exifIfd, ExifTag::DATETIME_DIGITIZED);
    }

    /**
     * Returns the raw DateTime tag value from IFD0.
     *
     * @return string|null
     */
    public function dateTimeRaw(): ?string
    {
        return $this->str($this->ifd0, ExifTag::DATETIME);
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
     * Returns the raw offset time for DateTimeDigitized.
     *
     * @return string|null
     */
    public function offsetTimeDigitizedRaw(): ?string
    {
        return $this->str($this->exifIfd, ExifTag::OFFSET_TIME_DIGITIZED);
    }

    /**
     * Returns the raw offset time for the DateTime tag.
     *
     * @return string|null
     */
    public function offsetTimeRaw(): ?string
    {
        return $this->str($this->exifIfd, ExifTag::OFFSET_TIME);
    }

    /**
     * Returns the parsed GPS metadata extracted from the GPS IFD.
     *
     * @return array{
     *     lat:?float,
     *     lon:?float,
     *     alt:?float,
     *     version:?string,
     *     satellites:?string,
     *     status:?string,
     *     measure_mode:?string,
     *     dop:?float,
     *     speed_ref:?string,
     *     speed_ms:?float,
     *     track_ref:?string,
     *     track:?float,
     *     img_direction_ref:?string,
     *     img_direction:?float,
     *     map_datum:?string,
     *     dest_lat_ref:?string,
     *     dest_lat:?float,
     *     dest_lon_ref:?string,
     *     dest_lon:?float,
     *     dest_bearing_ref:?string,
     *     dest_bearing:?float,
     *     dest_distance_ref:?string,
     *     dest_distance_m:?float,
     *     processing_method:?string,
     *     area_information:?string,
     *     date:?string,
     *     time:?string,
     *     timestamp:?DateTimeImmutable,
     *     differential:?int,
     *     h_positioning_error:?float
     * }
     */
    public function gps(): array
    {
        if (!$this->gpsIfd instanceof Ifd) {
            return ValueConverters::emptyGpsResult();
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
        return $this->parseExifDateTime($this->dateTimeOriginalRaw(), $this->offsetTimeOriginalRaw());
    }

    /**
     * Returns the digitised timestamp combining the raw value and offset tags.
     *
     * @return DateTimeImmutable|null
     */
    public function dateTimeDigitized(): ?DateTimeImmutable
    {
        return $this->parseExifDateTime($this->dateTimeDigitizedRaw(), $this->offsetTimeDigitizedRaw());
    }

    /**
     * Returns the DateTime tag combined with its optional offset.
     *
     * @return DateTimeImmutable|null
     */
    public function dateTime(): ?DateTimeImmutable
    {
        return $this->parseExifDateTime($this->dateTimeRaw(), $this->offsetTimeRaw());
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

    /**
     * Returns a rational or numeric value converted to float if present in the given IFD.
     *
     * @return float|null
     */
    private function rational(?Ifd $ifd, int $tag): ?float
    {
        if (!$ifd instanceof Ifd) {
            return null;
        }

        $entry = $ifd->get($tag);

        return $entry instanceof IfdEntry ? ValueConverters::rationalToFloat($entry->value) : null;
    }

    /**
     * Normalises EXIF timestamp strings and optional offsets into immutable datetime instances.
     *
     * @param string|null $rawDateTime Raw EXIF datetime formatted as "YYYY:MM:DD HH:MM:SS".
     * @param string|null $rawOffset   Optional timezone offset such as "+01:00".
     */
    private function parseExifDateTime(?string $rawDateTime, ?string $rawOffset): ?DateTimeImmutable
    {
        if ($rawDateTime === null || $rawDateTime === '' || strlen($rawDateTime) < 19) {
            return null;
        }

        try {
            $tz = ($rawOffset !== null && $rawOffset !== '')
                ? new DateTimeZone($rawOffset)
                : new DateTimeZone('UTC');
        } catch (Exception) {
            return null;
        }

        $normalized = str_replace(':', '-', substr($rawDateTime, 0, 10)) . substr($rawDateTime, 10);
        $dt         = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $normalized, $tz);

        return $dt !== false ? $dt : null;
    }
}
