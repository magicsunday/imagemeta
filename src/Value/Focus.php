<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value;

/**
 * Represents focus related capture metadata such as subject distance.
 */
final readonly class Focus
{
    /**
     * @param float|null  $subjectDistanceM Focus distance to the subject in metres.
     * @param int|null    $subjectAreaX     Normalised subject area rectangle origin (X).
     * @param int|null    $subjectAreaY     Normalised subject area rectangle origin (Y).
     * @param int|null    $subjectAreaW     Normalised subject area width.
     * @param int|null    $subjectAreaH     Normalised subject area height.
     * @param string|null $afMode           Active auto focus mode name.
     */
    public function __construct(
        public ?float $subjectDistanceM,
        public ?int $subjectAreaX,
        public ?int $subjectAreaY,
        public ?int $subjectAreaW,
        public ?int $subjectAreaH,
        public ?string $afMode,
    ) {
    }

    /**
     * Returns the focus distance to the subject in metres.
     */
    public function subjectDistanceM(): ?float
    {
        return $this->subjectDistanceM;
    }

    /**
     * Returns the normalised subject area X origin.
     */
    public function subjectAreaX(): ?int
    {
        return $this->subjectAreaX;
    }

    /**
     * Returns the normalised subject area Y origin.
     */
    public function subjectAreaY(): ?int
    {
        return $this->subjectAreaY;
    }

    /**
     * Returns the normalised subject area width.
     */
    public function subjectAreaW(): ?int
    {
        return $this->subjectAreaW;
    }

    /**
     * Returns the normalised subject area height.
     */
    public function subjectAreaH(): ?int
    {
        return $this->subjectAreaH;
    }

    /**
     * Returns the active auto focus mode name.
     */
    public function afMode(): ?string
    {
        return $this->afMode;
    }
}
