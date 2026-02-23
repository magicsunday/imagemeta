<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value;

use MagicSunday\ImageMeta\Value\Enum\YCbCrPositioning;

/**
 * TIFF colour model and reference data: YCbCr, chromaticities, and transfer functions.
 */
final readonly class TiffColorRef
{
    /**
     * @param YCbCrPositioning|null                                       $ycbcrPos              Chroma positioning relative to luma samples.
     * @param array{0:int,1:int}|null                                     $ycbcrSubSampling      Horizontal/vertical chroma subsampling factors.
     * @param array{0:float,1:float,2:float}|null                         $ycbcrCoefficients     Luma coefficients for YCbCr conversions.
     * @param array{0:float,1:float}|null                                 $whitePoint            Normalised white point (x, y).
     * @param array{0:float,1:float,2:float,3:float,4:float,5:float}|null $primaryChromaticities Primary chromaticities ordered as R,G,B.
     * @param array{0:float,1:float,2:float,3:float,4:float,5:float}|null $referenceBlackWhite   Reference black and white point values.
     * @param list<int>|null                                              $transferFunction      Transfer function lookup table.
     */
    public function __construct(
        public ?YCbCrPositioning $ycbcrPos = null,
        public ?array $ycbcrSubSampling = null,
        public ?array $ycbcrCoefficients = null,
        public ?array $whitePoint = null,
        public ?array $primaryChromaticities = null,
        public ?array $referenceBlackWhite = null,
        public ?array $transferFunction = null,
    ) {
    }
}
