<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value\Contracts;

interface LensInterface
{
    /**
     * @return array{0:float,1:float,2:float,3:float}|null
     */
    public function lensSpecification(): ?array;

    public function lensMake(): ?string;

    public function lensModel(): ?string;

    public function lensSerialNumber(): ?string;

    public function focalLengthMm(): ?float;

    public function focalLengthIn35mm(): ?int;

    public function maxApertureFNumber(): ?float;
}
