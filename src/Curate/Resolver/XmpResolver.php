<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Curate\Resolver;

use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;
use MagicSunday\ImageMeta\Value\Xmp;

/**
 * Wraps the parsed XMP document inside a value object.
 */
final readonly class XmpResolver
{
    /**
     * Builds the XMP value object when a document is available.
     */
    public function resolve(?XmpDocument $xmpDocument): ?Xmp
    {
        if (!$xmpDocument instanceof XmpDocument) {
            return null;
        }

        return new Xmp($xmpDocument);
    }
}
