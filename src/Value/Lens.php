<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value;

/**
 * Represents lens information used when capturing the image.
 */
final readonly class Lens
{
    /**
     * Creates a lens metadata value object.
     *
     * @param string|null                                 $lensMake           Lens manufacturer.
     * @param string|null                                 $lensModel          Lens model description.
     * @param string|null                                 $lensSerialNumber   Serial number reported by the lens.
     * @param float|null                                  $focalLengthMm      Focal length used in millimetres.
     * @param int|null                                    $focalLengthIn35mm  35mm equivalent focal length.
     * @param float|null                                  $maxApertureFNumber Maximum aperture value as f-number.
     * @param array{0:float,1:float,2:float,3:float}|null $lensSpecification  Lens specification describing zoom and aperture range.
     */
    public function __construct(public ?string $lensMake, public ?string $lensModel, public ?string $lensSerialNumber, public ?float $focalLengthMm, public ?int $focalLengthIn35mm, public ?float $maxApertureFNumber, public ?array $lensSpecification = null)
    {
    }
}
