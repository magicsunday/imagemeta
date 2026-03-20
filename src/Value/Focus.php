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
     * Creates a focus metadata value object.
     *
     * @param float|null       $subjectDistanceMetres Focus distance to the subject in metres.
     * @param SubjectArea|null $subjectArea           Subject area location and dimensions (EXIF 3.0 §4.6.6).
     * @param string|null      $afMode                Active auto focus mode name.
     */
    public function __construct(
        public ?float $subjectDistanceMetres,
        public ?SubjectArea $subjectArea,
        public ?string $afMode,
    ) {
    }
}
