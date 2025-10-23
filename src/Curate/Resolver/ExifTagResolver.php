<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Curate\Resolver;

use DateTimeImmutable;
use MagicSunday\ImageMeta\Model\Exif\ExifDocument;
use MagicSunday\ImageMeta\Model\Exif\ExifNumericList;
use MagicSunday\ImageMeta\Model\Exif\IfdEntry;
use MagicSunday\ImageMeta\Model\Exif\ValueConverters as ExifValueConverters;
use MagicSunday\ImageMeta\Model\Exif\ExifTag;
use MagicSunday\ImageMeta\Value\Enum\ColorSpace;
use MagicSunday\ImageMeta\Value\Enum\ExposureProgram;
use MagicSunday\ImageMeta\Value\Enum\MeteringMode;
use MagicSunday\ImageMeta\Value\Enum\Orientation;
use MagicSunday\ImageMeta\Value\Enum\WhiteBalance;
use MagicSunday\ImageMeta\Value\FlashInfo;

use function is_array;
use function is_string;
use function rtrim;

/**
 * Provides high level accessors for common EXIF tags without exposing raw identifiers to consumers.
 */
final readonly class ExifTagResolver
{
    public function __construct(private ?ExifDocument $document)
    {
    }

    /**
     * Returns the camera manufacturer when available.
     */
    public function cameraMake(): ?string
    {
        return $this->document?->cameraMake();
    }

    /**
     * Returns the camera model when available.
     */
    public function cameraModel(): ?string
    {
        return $this->document?->cameraModel();
    }

    /**
     * Returns the lens model description when available.
     */
    public function lensModel(): ?string
    {
        return $this->document?->lensModel();
    }

    /**
     * Returns the camera owner name when present.
     */
    public function ownerName(): ?string
    {
        return $this->document?->ownerName();
    }

    /**
     * Returns the body serial number when present.
     */
    public function bodySerialNumber(): ?string
    {
        return $this->document?->bodySerialNumber();
    }

    /**
     * Returns the lens serial number when present.
     */
    public function lensSerialNumber(): ?string
    {
        return $this->document?->lensSerialNumber();
    }

    /**
     * Returns the artist tag value when present.
     */
    public function artist(): ?string
    {
        $entry = $this->getEntry('Artist');
        $value = $entry?->value;

        return is_string($value) ? rtrim($value, "\0") : null;
    }

    /**
     * Returns the EXIF orientation as an enum.
     */
    public function orientation(): ?Orientation
    {
        return Orientation::fromExifValue($this->document?->orientation());
    }

    /**
     * Returns the EXIF color space enumeration when available.
     */
    public function colorSpace(): ?ColorSpace
    {
        return ColorSpace::fromExifValue($this->document?->colorSpace());
    }

    /**
     * Returns the ISO sensitivity.
     */
    public function iso(): ?int
    {
        return $this->document?->iso();
    }

    /**
     * Returns the exposure time in seconds.
     */
    public function exposureTime(): ?float
    {
        return $this->document?->exposureTime();
    }

    /**
     * Returns the aperture f-number.
     */
    public function fNumber(): ?float
    {
        return $this->document?->fNumber();
    }

    /**
     * Returns the focal length in millimetres.
     */
    public function focalLength(): ?float
    {
        return $this->document?->focalLengthMm();
    }

    /**
     * Returns the 35mm equivalent focal length when present.
     */
    public function focalLength35mm(): ?int
    {
        return $this->document?->focalLength35Mm();
    }

    /**
     * Returns the exposure program enumeration when available.
     */
    public function exposureProgram(): ?ExposureProgram
    {
        $value = $this->document?->exposureProgram();

        return $value !== null ? ExposureProgram::tryFrom($value) : null;
    }

    /**
     * Returns the metering mode enumeration when available.
     */
    public function meteringMode(): ?MeteringMode
    {
        $value = $this->document?->meteringMode();

        return $value !== null ? MeteringMode::tryFrom($value) : null;
    }

    /**
     * Returns the white balance enumeration when available.
     */
    public function whiteBalance(): ?WhiteBalance
    {
        return WhiteBalance::fromExifValue($this->document?->whiteBalance());
    }

    /**
     * Returns the flash metadata as a value object when available.
     */
    public function flash(): ?FlashInfo
    {
        $flashValue = $this->document?->flash();
        if ($flashValue === null) {
            return null;
        }

        return FlashInfo::fromExifValue($flashValue);
    }

    /**
     * Returns the capture datetime derived from DateTimeOriginal and offset tags.
     */
    public function captureDateTime(): ?DateTimeImmutable
    {
        return $this->document?->captureDateTime();
    }

    /**
     * Returns the digitised datetime when available.
     */
    public function digitizedDateTime(): ?DateTimeImmutable
    {
        return $this->document?->dateTimeDigitized();
    }

    /**
     * Returns the image datetime when available.
     */
    public function fileDateTime(): ?DateTimeImmutable
    {
        return $this->document?->dateTime();
    }

    /**
     * Returns the raw offset from DateTimeOriginal.
     */
    public function originalOffset(): ?string
    {
        return $this->document?->offsetTimeOriginalRaw();
    }

    /**
     * Returns the GPS coordinates as an array of floats.
     *
     * @return array{lat:?float,lon:?float,alt:?float}
     */
    public function gps(): array
    {
        return $this->document?->gps() ?? ['lat' => null, 'lon' => null, 'alt' => null];
    }

    /**
     * Returns the subject distance in metres when available.
     */
    public function subjectDistance(): ?float
    {
        return $this->getRational('SubjectDistance');
    }

    /**
     * Returns the EXIF subject area values.
     *
     * @return array<int, int>|null
     */
    public function subjectArea(): ?array
    {
        $entry = $this->getEntry('SubjectArea');
        $value = $entry?->value;

        if ($value instanceof ExifNumericList) {
            return $value->values;
        }

        if (is_array($value)) {
            return $value;
        }

        return null;
    }

    /**
     * Helper returning an IFD entry by friendly alias.
     */
    private function getEntry(string $alias): ?IfdEntry
    {
        if (!$this->document instanceof ExifDocument) {
            return null;
        }

        return match ($alias) {
            'SubjectArea'     => $this->document->exifIfd?->get(ExifTag::SUBJECT_AREA),
            'SubjectDistance' => $this->document->exifIfd?->get(ExifTag::SUBJECT_DISTANCE),
            'Artist'          => $this->document->ifd0->get(ExifTag::ARTIST),
            default           => null,
        };
    }

    /**
     * Helper returning rational values by alias.
     */
    private function getRational(string $alias): ?float
    {
        $entry = $this->getEntry($alias);

        return $entry instanceof IfdEntry ? ExifValueConverters::rationalToFloat($entry->value) : null;
    }
}
