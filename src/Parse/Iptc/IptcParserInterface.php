<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Iptc;

use MagicSunday\ImageMeta\Model\Iptc\IptcDocument;

/**
 * Defines the contract for parsing IPTC payload blocks.
 */
interface IptcParserInterface
{
    /**
     * Parses one IPTC payload and returns the extracted datasets.
     */
    public function parse(string $payload): IptcDocument;
}
