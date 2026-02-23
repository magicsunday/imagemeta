<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value;

use MagicSunday\ImageMeta\Value\Enum\ResolutionUnit;

/**
 * Captures low level TIFF image structure characteristics.
 */
final readonly class TiffData
{
    /**
     * Creates a TIFF image structure metadata value object composed of domain sub-objects.
     *
     * @param TiffStructure|null  $structure      Sample layout, compression, and photometric interpretation.
     * @param TiffColorRef|null   $color          Colour model and reference data.
     * @param TiffLayout|null     $layout         Strip, tile, and JPEG interchange data layout.
     * @param ResolutionUnit|null $resolutionUnit Resolution unit for X/Y values.
     * @param float|null          $xResolution    Horizontal resolution in the reported unit.
     * @param float|null          $yResolution    Vertical resolution in the reported unit.
     * @param string|null         $copyright      Copyright notice embedded in EXIF.
     */
    public function __construct(
        public ?TiffStructure $structure = null,
        public ?TiffColorRef $color = null,
        public ?TiffLayout $layout = null,
        public ?ResolutionUnit $resolutionUnit = null,
        public ?float $xResolution = null,
        public ?float $yResolution = null,
        public ?string $copyright = null,
    ) {
    }
}
