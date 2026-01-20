<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value;

use MagicSunday\ImageMeta\Model\Iptc\IptcDocument;

/**
 * Provides access to parsed IPTC IIM datasets.
 */
final readonly class Iptc
{
    /**
     * Creates an IPTC metadata value object.
     *
     * @param IptcDocument|null $document Parsed IPTC dataset collection.
     */
    public function __construct(public ?IptcDocument $document)
    {
    }
}
