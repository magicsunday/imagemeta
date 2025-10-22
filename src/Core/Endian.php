<?php
declare(strict_types=1);

namespace MagicSunday\ImageMeta\Core;

enum Endian: string
{
    case Little = 'II';
    case Big = 'MM';
}
