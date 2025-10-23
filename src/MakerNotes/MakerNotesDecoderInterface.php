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
     *
     * @param string      $raw   Raw maker note byte sequence.
     * @param string      $make  Camera make identifier associated with the payload.
     * @param string|null $model Optional camera model identifier when available.
     *
     * @return MakerNotesMetadata Normalised metadata describing the payload.
     */
    public function decode(string $raw, string $make, ?string $model): MakerNotesMetadata;
}
