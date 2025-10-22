<?php
declare(strict_types=1);

namespace MagicSunday\ImageMeta\Detect;

enum ContainerType
{
    case JPEG;
    case ISOBMFF; // HEIC/AVIF/MP4/MOV
}
