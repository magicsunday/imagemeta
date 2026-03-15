<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Contract;

use MagicSunday\ImageMeta\Value\FlashPix;

/**
 * Defines the contract for parsing assembled FlashPix APP2 streams.
 */
interface FlashPixParserInterface
{
    /**
     * Creates a FlashPix value object from assembled streams, optionally extracting OLE property set metadata.
     *
     * @param array<int, string> $streams Assembled FlashPix extension streams keyed by FPXR contents-list index.
     */
    public function parse(array $streams): FlashPix;
}
