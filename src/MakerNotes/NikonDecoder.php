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
 * Decoder that extracts basic metadata about Nikon maker note payloads.
 */
final class NikonDecoder extends AbstractSimpleDecoder
{
    /**
     * Returns the vendor label for Nikon maker note records.
     */
    protected function getVendorName(): string
    {
        return 'Nikon';
    }
}
