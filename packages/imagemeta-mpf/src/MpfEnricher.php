<?php

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Mpf;

use MagicSunday\ImageMeta\Contracts\EnricherInterface;
use MagicSunday\ImageMeta\Curate\StructuredMetadata;
use MagicSunday\ImageMeta\Model\Metadata;

final class MpfEnricher implements EnricherInterface
{
    public function enrich(Metadata $metadata, StructuredMetadata $structured): StructuredMetadata
    {
        return $structured;
    }
}
