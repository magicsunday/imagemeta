<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Curate\Structured;

use MagicSunday\ImageMeta\Value\Author;
use MagicSunday\ImageMeta\Value\RelatedAssets;
use MagicSunday\ImageMeta\Value\Rights as RightsValue;

/**
 * Organises authorship and rights related metadata.
 */
final readonly class RightsMetadata
{
    public function __construct(
        public RightsValue $rights,
        public Author $author,
        public RelatedAssets $related,
    ) {
    }

    public function rights(): RightsValue
    {
        return $this->rights;
    }

    public function author(): Author
    {
        return $this->author;
    }

    public function related(): RelatedAssets
    {
        return $this->related;
    }
}
