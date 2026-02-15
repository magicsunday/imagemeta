<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Exif\Adapter;

use DateTimeImmutable;
use MagicSunday\ImageMeta\Exif\Model\ParsedExif;

/**
 * Provides temporal-domain accessors on top of ParsedExif.
 */
final readonly class TemporalMetadataAdapter
{
    public function __construct(private ParsedExif $parsedExif)
    {
    }

    public function captureDateTime(): ?DateTimeImmutable
    {
        return $this->parsedExif->captureDateTime();
    }

    public function dateTimeOriginal(): ?DateTimeImmutable
    {
        return $this->parsedExif->dateTimeOriginal();
    }

    public function dateTimeDigitized(): ?DateTimeImmutable
    {
        return $this->parsedExif->dateTimeDigitized();
    }

    public function offsetTimeOriginal(): ?string
    {
        return $this->parsedExif->offsetTimeOriginal();
    }

    public function offsetTimeDigitized(): ?string
    {
        return $this->parsedExif->offsetTimeDigitized();
    }

    public function offsetTime(): ?string
    {
        return $this->parsedExif->offsetTime();
    }
}
