<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Contract;

use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;

/**
 * Defines the contract for parsing XMP packets.
 */
interface XmpParserInterface
{
    /**
     * Parses one XMP RDF/XML packet.
     */
    public function parse(string $xml): XmpDocument;
}
