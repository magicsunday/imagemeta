<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Riff;

/**
 * Contract for streaming RIFF container parsers.
 */
interface RiffParserInterface
{
    /**
     * Extracts metadata payloads from the RIFF container.
     */
    public function extract(): RiffParseResult;
}
