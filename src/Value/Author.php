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
     * Creates an author/creator metadata value object.
     *
     * @param string|null $artist            Artist or photographer name.
     * @param string|null $ownerName         Camera owner name.
     * @param string|null $creator           Creator attribution as declared in XMP.
     * @param string|null $creatorEmail      Contact email address for the creator.
     * @param string|null $creatorPhone      Contact phone number for the creator.
     * @param string|null $creatorAddress    Contact street address for the creator.
     * @param string|null $creatorCity       Contact city for the creator.
     * @param string|null $creatorRegion     Contact region/state for the creator.
     * @param string|null $creatorPostalCode Contact postal code for the creator.
     * @param string|null $creatorCountry    Contact country for the creator.
     * @param string|null $creatorUrl        Contact URL for the creator.
     * @param string|null $photographer      Photographer attribution from EXIF 3.0 tags.
     * @param string|null $imageEditor       Image editor attribution from EXIF 3.0 tags.
     */
    public function __construct(
        public ?string $artist,
        public ?string $ownerName,
        public ?string $creator,
        public ?string $creatorEmail,
        public ?string $creatorPhone,
        public ?string $creatorAddress,
        public ?string $creatorCity,
        public ?string $creatorRegion,
        public ?string $creatorPostalCode,
        public ?string $creatorCountry,
        public ?string $creatorUrl,
        public ?string $photographer,
        public ?string $imageEditor,
    ) {
    }
}
