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
        public readonly ?float $subjectDistanceM,
        public readonly ?int $subjectAreaX,
        public readonly ?int $subjectAreaY,
        public readonly ?int $subjectAreaW,
        public readonly ?int $subjectAreaH,
        public readonly ?string $afMode,
    ) {
    }
}
