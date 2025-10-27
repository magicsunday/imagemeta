<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Curate\Structured;

use MagicSunday\ImageMeta\Value\FlashPix;
use MagicSunday\ImageMeta\Value\Interop;
use MagicSunday\ImageMeta\Value\Standards;
use MagicSunday\ImageMeta\Value\TiffData;
use MagicSunday\ImageMeta\Value\Xmp;

/**
 * Technical metadata such as TIFF layout and standards identifiers.
 */
final readonly class TechnicalMetadata
{
    public function __construct(
        public Interop $interop,
        public TiffData $tiff,
        public Standards $standards,
        public FlashPix $flashPix,
        public Xmp $xmp,
    ) {
    }
}
