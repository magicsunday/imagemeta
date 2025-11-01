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
 * Stores author and contact information associated with the asset.
 */
final readonly class Author
{
    /**
     * @param string|null $artist       Artist or photographer name.
     * @param string|null $ownerName    Camera owner name.
     * @param string|null $creator      Creator attribution as declared in XMP.
     * @param string|null $creatorEmail Contact email address for the creator.
     * @param string|null $photographer Photographer attribution from EXIF 3.0 tags.
     * @param string|null $imageEditor  Image editor attribution from EXIF 3.0 tags.
     */
    public function __construct(
        public readonly ?string $artist,
        public readonly ?string $ownerName,
        public readonly ?string $creator,
        public readonly ?string $creatorEmail,
        public readonly ?string $photographer,
        public readonly ?string $imageEditor,
    ) {
    }
}
