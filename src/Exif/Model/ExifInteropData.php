<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Exif\Model;

/**
 * Read-only access contract for interoperability metadata.
 *
 * EXIF 3.0 §4.6.8 defines Interoperability IFD semantics.
 */
interface ExifInteropData
{
    public function interoperabilityIfdPointer(): ?int;

    public function interopIndex(): ?string;
}
