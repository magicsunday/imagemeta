<?php
declare(strict_types=1);

namespace MagicSunday\ImageMeta\Detect;

use MagicSunday\ImageMeta\Core\Stream;

final class FormatDetector
{
    public static function detect(Stream $s): ContainerType
    {
        $s->seek(0);
        $magic2 = $s->read(2);
        if ($magic2 === "\xFF\xD8") {
            return ContainerType::JPEG;
        }
        $s->seek(4);
        $brand = $s->read(4); // 'ftyp'
        if ($brand === 'ftyp') {
            return ContainerType::ISOBMFF;
        }
        // a few HEIC files may start with 0 size+ftyp; we already cover 'ftyp' at [4..8]
        throw new \RuntimeException('Unsupported or unknown container');
    }
}
