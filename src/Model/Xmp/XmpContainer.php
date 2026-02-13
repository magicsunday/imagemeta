<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model\Xmp;

/**
 * Enumerates RDF container kinds used by XMP list-valued properties.
 */
enum XmpContainer: string
{
    case Alt = 'Alt';

    case Bag = 'Bag';

    case Seq = 'Seq';

    /**
     * Maps RDF container element local names to enum values.
     */
    public static function fromRdfContainerName(string $name): ?self
    {
        return self::tryFrom($name);
    }
}
