<?php

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Xmp;

use MagicSunday\ImageMeta\Contracts\ExtensionInterface;
use MagicSunday\ImageMeta\Registry;

final class XmpExtension implements ExtensionInterface
{
    public function register(Registry $registry): void
    {
        $registry->withEnricher(new XmpEnricher());
    }
}
