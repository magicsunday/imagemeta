<?php

declare(strict_types=1);

use MagicSunday\ImageMeta\Extensions;
use MagicSunday\ImageMeta\Xmp\XmpExtension;

Extensions::register(new XmpExtension());
