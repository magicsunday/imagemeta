<?php

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Xmp;

use MagicSunday\ImageMeta\Contracts\EnricherInterface;
use MagicSunday\ImageMeta\Curate\StructuredMetadata;
use MagicSunday\ImageMeta\Model\Metadata;

final class XmpEnricher implements EnricherInterface
{
    public function enrich(Metadata $metadata, StructuredMetadata $structured): StructuredMetadata
    {
        return $structured;
    }
}
