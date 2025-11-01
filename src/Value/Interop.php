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
 * Represents the EXIF interoperability metadata block.
 */
final readonly class Interop
{
    /**
     * @param string|null $index                  Interoperability index identifier such as "R98".
     * @param string|null $version                Interoperability version string such as "0100".
     * @param string|null $relatedImageFileFormat Declared file format for the related image asset.
     * @param int|null    $relatedImageWidth      Pixel width of the related image asset.
     * @param int|null    $relatedImageLength     Pixel length of the related image asset.
     */
    public function __construct(
        public readonly ?string $index,
        public readonly ?string $version,
        public readonly ?string $relatedImageFileFormat = null,
        public readonly ?int $relatedImageWidth = null,
        public readonly ?int $relatedImageLength = null,
    ) {
    }
}
