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
 * Typed accessor facade for Olympus AVI Camera Tags in StructuredMetadata factory fallback chains.
 *
 * Analogous to {@see NikonAviLookup} for Nikon Camera Tags and {@see RiffInfoLookup} for generic RIFF fields.
 */
final readonly class OlympusAviLookup
{
    public function __construct(
        private ?OlympusCameraTags $tags,
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

    public function fNumber(): ?float
    {
        return $this->tags?->fNumber;
    }

    public function dateTime1(): ?string
    {
        return $this->tags?->dateTime1;
    }

    public function dateTime2(): ?string
    {
        return $this->tags?->dateTime2;
    }
}
