<?php

declare(strict_types=1);

namespace MagicSunday\ImageMeta\QuickTime;

use MagicSunday\ImageMeta\Contracts\ExtensionInterface;
use MagicSunday\ImageMeta\Registry;

final class QuickTimeExtension implements ExtensionInterface
{
    public function register(Registry $registry): void
    {
        $registry->withEnricher(new QuickTimeEnricher());
    }
}
