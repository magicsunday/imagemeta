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
 * Stores contact information for the content creator.
 */
final readonly class CreatorContact
{
    /**
     * @param string|null $email      Contact email address for the creator.
     * @param string|null $phone      Contact phone number for the creator.
     * @param string|null $address    Contact street address for the creator.
     * @param string|null $city       Contact city for the creator.
     * @param string|null $region     Contact region/state for the creator.
     * @param string|null $postalCode Contact postal code for the creator.
     * @param string|null $country    Contact country for the creator.
     * @param string|null $url        Contact URL for the creator.
     */
    public function __construct(
        public ?string $email = null,
        public ?string $phone = null,
        public ?string $address = null,
        public ?string $city = null,
        public ?string $region = null,
        public ?string $postalCode = null,
        public ?string $country = null,
        public ?string $url = null,
    ) {
    }
}
