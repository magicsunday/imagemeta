<?php

declare(strict_types=1);

namespace MagicSunday\ImageMeta\QuickTime;

use MagicSunday\ImageMeta\Contracts\EnricherInterface;
use MagicSunday\ImageMeta\Curate\StructuredMetadata;
use MagicSunday\ImageMeta\Model\Metadata;

final class QuickTimeEnricher implements EnricherInterface
{
    public function enrich(Metadata $metadata, StructuredMetadata $structured): StructuredMetadata
    {
        return $structured;
    }
}
