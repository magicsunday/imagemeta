<?php

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Mpf;

use MagicSunday\ImageMeta\Contracts\ExtensionInterface;
use MagicSunday\ImageMeta\Registry;

final class MpfExtension implements ExtensionInterface
{
    public function register(Registry $registry): void
    {
        $registry->withEnricher(new MpfEnricher());
    }
}
