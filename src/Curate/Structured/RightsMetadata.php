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
    public ?string $copyright;

    public ?string $usageTerms;

    public ?string $licenseUrl;

    public ?string $creditLine;

    public ?string $securityClassification;

    public function __construct(
        RightsValue $rights,
        public Author $author,
        public RelatedAssets $related,
    ) {
        $this->copyright              = $rights->copyright;
        $this->usageTerms             = $rights->usageTerms;
        $this->licenseUrl             = $rights->licenseUrl;
        $this->creditLine             = $rights->creditLine;
        $this->securityClassification = $rights->securityClassification;
    }
}
