<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Exif\Reader;

use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdValueReader;
use MagicSunday\ImageMeta\Model\Dng\DngTag;

use function count;
use function is_array;

/**
 * Reads camera and lens identification metadata from EXIF IFDs.
 *
 * Covers camera make/model, lens make/model/serial, body serial number,
 * owner name, lens specification, and DNG camera identification fields.
 */
final readonly class CameraLensExifReader
{
    /**
     * @param IfdValueReader $reader  Value reader for IFD tag extraction.
     * @param Ifd            $ifd0    Root IFD of the TIFF structure.
     * @param Ifd|null       $exifIfd Sub IFD containing EXIF-specific tags.
     */
    public function __construct(
        private IfdValueReader $reader,
        private Ifd $ifd0,
        private ?Ifd $exifIfd,
    ) {
    }

    /**
     * Returns the camera manufacturer string if present.
     *
     * EXIF 3.0 §4.6.5.4.2 (Make) stores the free-form manufacturer identifier
     * as ASCII or UTF-8 including the terminating NUL.
     */
    public function cameraMake(): ?string
    {
        return $this->reader->str($this->ifd0, ExifTag::MAKE);
    }

    /**
     * Returns the camera model string if present.
     *
     * EXIF 3.0 §4.6.5.4.3 (Model) defines the model name or number as an ASCII
     * or UTF-8 string with the NUL terminator counted in the tag length.
     */
    public function cameraModel(): ?string
    {
        return $this->reader->str($this->ifd0, ExifTag::MODEL);
    }

    /**
     * Returns the lens model string if present.
     *
     * EXIF 3.0 §4.6.6.9.6 stores the lens model as an ASCII or UTF-8 string.
     */
    public function lensModel(): ?string
    {
        return $this->reader->str($this->exifIfd, ExifTag::LENS_MODEL);
    }

    /**
     * Returns the lens manufacturer string if present.
     *
     * EXIF 3.0 §4.6.6.9.5 records LensMake as an ASCII or UTF-8 identifier and
     * expects it to remain stable once captured.
     */
    public function lensMake(): ?string
    {
        return $this->reader->str($this->exifIfd, ExifTag::LENS_MAKE);
    }

    /**
     * Returns the camera owner name if present.
     *
     * EXIF 3.0 §4.6.6.9.2 allows ASCII or UTF-8 text for CameraOwnerName and
     * expects Artist to be populated alongside it.
     */
    public function ownerName(): ?string
    {
        return $this->reader->str($this->exifIfd, ExifTag::CAMERA_OWNER_NAME);
    }

    /**
     * Returns the camera body serial number if present.
     *
     * EXIF 3.0 §4.6.6.9.3 stores the camera body serial as an ASCII string.
     */
    public function bodySerialNumber(): ?string
    {
        return $this->reader->str($this->exifIfd, ExifTag::BODY_SERIAL_NUMBER);
    }

    /**
     * Returns the lens serial number if present.
     *
     * EXIF 3.0 §4.6.6.9.7 defines LensSerialNumber as a free-form ASCII value
     * that should remain stable across edits.
     */
    public function lensSerialNumber(): ?string
    {
        return $this->reader->str($this->exifIfd, ExifTag::LENS_SERIAL_NUMBER);
    }

    /**
     * Returns the lens specification describing focal and aperture range.
     *
     * EXIF 3.0 §4.6.6.9.4 stores four RATIONALs: minimum
     * focal length, maximum focal length, minimum F-number at the minimum focal
     * length, and minimum F-number at the maximum focal length. Unknown
     * apertures are recorded as 0/0.
     *
     * @return array{0:float,1:float,2:float,3:float}|null
     */
    public function lensSpecification(): ?array
    {
        $values = $this->reader->rationalList($this->exifIfd, ExifTag::LENS_SPECIFICATION);

        if (!is_array($values) || (count($values) !== 4)) {
            return null;
        }

        return [
            $values[0],
            $values[1],
            $values[2],
            $values[3],
        ];
    }

    /**
     * Returns the DNG camera serial number from IFD0 when present.
     *
     * DNG 1.7.1.0 (DNG Tags, CameraSerialNumber): ASCII, NUL-terminated.
     */
    public function cameraSerialNumber(): ?string
    {
        return $this->reader->str($this->ifd0, DngTag::CAMERA_SERIAL_NUMBER);
    }

    /**
     * Returns the non-localized unique DNG camera model from IFD0 when present.
     *
     * DNG 1.7.1.0 (DNG Tags, UniqueCameraModel): ASCII, NUL-terminated.
     */
    public function uniqueCameraModel(): ?string
    {
        return $this->reader->str($this->ifd0, DngTag::UNIQUE_CAMERA_MODEL);
    }

    /**
     * Returns the localized DNG camera model from IFD0.
     *
     * DNG 1.7.1.0 (DNG Tags, LocalizedCameraModel): ASCII or BYTE, NUL-terminated UTF-8.
     * Default: same as UniqueCameraModel when absent.
     */
    public function localizedCameraModel(): ?string
    {
        return $this->reader->str($this->ifd0, DngTag::LOCALIZED_CAMERA_MODEL)
            ?? $this->reader->str($this->ifd0, DngTag::UNIQUE_CAMERA_MODEL);
    }
}
