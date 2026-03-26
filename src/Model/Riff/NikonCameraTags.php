<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model\Riff;

/**
 * Immutable value object holding parsed Nikon Camera Tags (nctg chunk) from RIFF/AVI containers.
 *
 * The nctg chunk uses a flat TLV format (tag u16 LE + size u16 LE + value) with Nikon-proprietary
 * tag assignments. Types are implied by tag ID; see ExifTool Nikon.pm %AVITags.
 *
 * Typed properties provide structured access for StructuredMetadata factories.
 * The {@see $entries} array preserves all tags (including unknown) for format script rendering.
 */
final readonly class NikonCameraTags
{
    /**
     * @param array<int, string> $entries              All parsed tag entries (tag ID => display string), including unknown tags.
     * @param string|null        $make                 Camera make (tag 0x0003).
     * @param string|null        $model                Camera model (tag 0x0004).
     * @param string|null        $software             Firmware version (tag 0x0005).
     * @param int|null           $orientation          Image orientation (tag 0x0007).
     * @param float|null         $exposureTime         Exposure time in seconds (tag 0x0008).
     * @param float|null         $fNumber              F-number (tag 0x0009).
     * @param float|null         $exposureCompensation Exposure compensation EV (tag 0x000a).
     * @param float|null         $maxApertureValue     Max aperture in APEX (tag 0x000b).
     * @param int|null           $meteringMode         Metering mode (tag 0x000c).
     * @param float|null         $focalLength          Focal length in mm (tag 0x000f).
     * @param string|null        $dateTimeOriginal     Original capture date (tag 0x0013, EXIF format).
     * @param string|null        $createDate           Creation date (tag 0x0014, EXIF format).
     * @param float|null         $digitalZoom          Digital zoom ratio (tag 0x001b).
     * @param string|null        $whiteBalance         White balance mode (tag 0x001f).
     */
    public function __construct(
        public array $entries,
        public ?string $make = null,
        public ?string $model = null,
        public ?string $software = null,
        public ?int $orientation = null,
        public ?float $exposureTime = null,
        public ?float $fNumber = null,
        public ?float $exposureCompensation = null,
        public ?float $maxApertureValue = null,
        public ?int $meteringMode = null,
        public ?float $focalLength = null,
        public ?string $dateTimeOriginal = null,
        public ?string $createDate = null,
        public ?float $digitalZoom = null,
        public ?string $whiteBalance = null,
    ) {
    }
}
