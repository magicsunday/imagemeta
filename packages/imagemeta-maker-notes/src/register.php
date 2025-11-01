<?php

declare(strict_types=1);

use MagicSunday\ImageMeta\Extensions;
use MagicSunday\ImageMeta\MakerNotes\MakerNotesExtension;

Extensions::register(new MakerNotesExtension());
