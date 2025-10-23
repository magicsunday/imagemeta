<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Xmp;

use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;

/**
 * @deprecated Use {@see XmpParser}. The class remains as a thin wrapper for backwards compatibility.
 */
final class XmpReader
{
    private readonly XmpParser $parser;

    /**
     * @param XmpParser|null $parser Optional parser instance used for delegation.
     */
    public function __construct(?XmpParser $parser = null)
    {
        $this->parser = $parser ?? new XmpParser();
    }

    /**
     * @param string $xml Raw XMP payload that should be parsed.
     *
     * @return XmpDocument Parsed XMP representation produced by the delegate parser.
     */
    public function parse(string $xml): XmpDocument
    {
        return $this->parser->parse($xml);
    }
}
