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
 * Decoder that extracts basic metadata about Canon maker note payloads.
 */
final class CanonDecoder extends AbstractSimpleDecoder
{
    /**
     * Returns the vendor label for Canon maker note records.
     */
    protected function getVendorName(): string
    {
        return 'Canon';
    }
}
