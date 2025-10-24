<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\MakerNotes;

use InvalidArgumentException;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleMakerNotes;

use function preg_match;

/**
 * Immutable value object that describes normalised maker note metadata.
 */
final readonly class MakerNotesMetadata
{
    /**
     * @param string $vendor Vendor responsible for the maker note payload. Must not be empty.
     * @param int    $length Number of bytes contained in the payload. Must be zero or positive.
     * @param string $sha1   Lowercase hexadecimal SHA-1 digest of the payload. Must be 40 characters long.
     * @param AppleMakerNotes|null $apple Additional Apple specific maker note data.
     */
    public function __construct(
        private string $vendor,
        private int $length,
        private string $sha1,
        private ?AppleMakerNotes $apple = null,
    ) {
        if ($vendor === '') {
            throw new InvalidArgumentException('The vendor must not be empty.');
        }

        if ($length < 0) {
            throw new InvalidArgumentException('The payload length must be zero or positive.');
        }

        if (preg_match('/^[0-9a-f]{40}$/', $sha1) !== 1) {
            throw new InvalidArgumentException('The payload hash must be a 40 character lowercase hexadecimal string.');
        }
    }

    /**
     * Returns the vendor responsible for the maker note payload.
     */
    public function vendor(): string
    {
        return $this->vendor;
    }

    /**
     * Returns the number of bytes contained in the maker note payload.
     */
    public function length(): int
    {
        return $this->length;
    }

    /**
     * Returns the lowercase hexadecimal SHA-1 digest of the payload.
     */
    public function sha1(): string
    {
        return $this->sha1;
    }

    /**
     * Returns Apple specific maker note data when available.
     */
    public function apple(): ?AppleMakerNotes
    {
        return $this->apple;
    }
}
