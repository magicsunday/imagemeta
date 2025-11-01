<?php

declare(strict_types=1);

namespace MagicSunday\ImageMeta\MakerNotes;

use MagicSunday\ImageMeta\Contracts\ExtensionInterface;
use MagicSunday\ImageMeta\Registry;

final class MakerNotesExtension implements ExtensionInterface
{
    public function register(Registry $registry): void
    {
        // Maker notes are handled via dedicated decoder packages.
    }
}
