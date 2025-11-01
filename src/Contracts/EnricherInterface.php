<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Contracts;

use MagicSunday\ImageMeta\Curate\StructuredMetadata;
use MagicSunday\ImageMeta\Model\Metadata;

/**
 * Allows extensions to enrich structured metadata derived from EXIF sources.
 */
interface EnricherInterface
{
    public function enrich(Metadata $metadata, StructuredMetadata $structured): StructuredMetadata;
}
