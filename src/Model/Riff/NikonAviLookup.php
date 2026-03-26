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
 * Typed accessor facade for Nikon AVI Camera Tags in StructuredMetadata factory fallback chains.
 *
 * Analogous to {@see RiffInfoLookup} for generic RIFF INFO/EXIF fields.
 */
final readonly class NikonAviLookup
{
    public function __construct(
        private ?NikonCameraTags $tags,
    ) {
    }

    public function make(): ?string
    {
        return $this->tags?->make;
    }

    public function model(): ?string
    {
        return $this->tags?->model;
    }

    public function software(): ?string
    {
        return $this->tags?->software;
    }

    public function equipment(): ?string
    {
        return $this->tags?->equipment;
    }

    public function dateTimeOriginal(): ?string
    {
        return $this->tags?->dateTimeOriginal;
    }

    public function createDate(): ?string
    {
        return $this->tags?->createDate;
    }

    public function orientation(): ?int
    {
        return $this->tags?->orientation;
    }

    public function exposureTime(): ?float
    {
        return $this->tags?->exposureTime;
    }

    public function fNumber(): ?float
    {
        return $this->tags?->fNumber;
    }

    public function exposureCompensation(): ?float
    {
        return $this->tags?->exposureCompensation;
    }

    public function maxApertureValue(): ?float
    {
        return $this->tags?->maxApertureValue;
    }

    public function meteringMode(): ?int
    {
        return $this->tags?->meteringMode;
    }

    public function focalLength(): ?float
    {
        return $this->tags?->focalLength;
    }

    public function duration(): ?float
    {
        return $this->tags?->duration;
    }

    public function focusMode(): ?string
    {
        return $this->tags?->focusMode;
    }

    public function digitalZoom(): ?float
    {
        return $this->tags?->digitalZoom;
    }

    public function whiteBalance(): ?string
    {
        return $this->tags?->whiteBalance;
    }
}
