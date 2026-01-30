<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Detect;

use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\Stream;

/**
 * Contract for detecting the container type of a binary stream based on magic numbers.
 */
interface FormatDetectorInterface
{
    /**
     * Inspects the leading bytes of the stream and returns the detected container type.
     *
     * @param Stream $stream seekable stream positioned at an arbitrary offset
     *
     * @return ContainerType detected container format
     *
     * @throws ParseError when the signature cannot be read or does not match a known container
     */
    public function detect(Stream $stream): ContainerType;
}
