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
 * Decoder that extracts basic metadata about Sony maker note payloads.
 */
final class SonyDecoder extends AbstractSimpleDecoder
{
    /**
     * Returns the vendor label for Sony maker note records.
     */
    protected function getVendorName(): string
    {
        return 'Sony';
    }
}
