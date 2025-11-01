<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value;

use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;

/**
 * Provides access to the parsed XMP document used during curation.
 */
final readonly class Xmp
{
    /**
     * @param XmpDocument|null $document Parsed XMP document.
     */
    public function __construct(public ?XmpDocument $document)
    {
    }
}
