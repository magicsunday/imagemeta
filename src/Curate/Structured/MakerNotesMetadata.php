<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Curate\Structured;

use MagicSunday\ImageMeta\MakerNotes\Apple\AppleMakerNotes;

/**
 * Exposes maker note details grouped by vendor.
 */
final readonly class MakerNotesMetadata
{
    public function __construct(
        public ?AppleMakerNotes $apple,
    ) {
    }
}
