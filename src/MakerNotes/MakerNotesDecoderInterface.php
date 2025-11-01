<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\MakerNotes;

/**
 * Describes a decoder that can interpret manufacturer-specific maker note metadata.
 */
interface MakerNotesDecoderInterface
{
    /**
     * Decodes a maker note payload for a specific camera make and model.
     */
    public function decode(string $raw, string $make, ?string $model): MakerNotesRecord;
}
