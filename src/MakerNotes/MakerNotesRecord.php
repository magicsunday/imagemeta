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
 * Immutable value object that describes a normalised maker note payload.
 */
final readonly class MakerNotesRecord
{
    /**
     * @param string               $vendor Vendor responsible for the maker note payload. Must not be empty.
     * @param int                  $length Number of bytes contained in the payload. Must be zero or positive.
     * @param string               $sha1   Lowercase hexadecimal SHA-1 digest of the payload. Must be 40 characters long.
     * @param AppleMakerNotes|null $apple  Additional Apple specific maker note data.
     */
    public function __construct(
        public string $vendor,
        public int $length,
        public string $sha1,
        public ?AppleMakerNotes $apple = null,
    ) {
        if ($this->vendor === '') {
            throw new InvalidArgumentException('The vendor must not be empty.');
        }

        if ($this->length < 0) {
            throw new InvalidArgumentException('The payload length must be zero or positive.');
        }

        if (preg_match('/^[0-9a-f]{40}$/', $this->sha1) !== 1) {
            throw new InvalidArgumentException('The payload hash must be a 40 character lowercase hexadecimal string.');
        }
    }
}
