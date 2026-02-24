<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\MakerNotes;

use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleMakerNotes;
use MagicSunday\ImageMeta\MakerNotes\Samsung\SamsungMakerNotes;

use function preg_match;

/**
 * Immutable value object that describes a normalized maker note payload.
 */
final readonly class MakerNotesRecord
{
    /**
     * @param string                 $vendor  Vendor responsible for the maker note payload. Must not be empty.
     * @param int                    $length  Number of bytes contained in the payload. Must be zero or positive.
     * @param string                 $sha1    Lowercase hexadecimal SHA-1 digest of the payload. Must be 40 characters long.
     * @param AppleMakerNotes|null   $apple   Additional Apple specific maker note data.
     * @param SamsungMakerNotes|null $samsung Additional Samsung specific maker note data.
     * @param bool|null              $safe    DNG MakerNoteSafety flag (true=safe, false=unsafe, null=absent).
     */
    public function __construct(
        public string $vendor,
        public int $length,
        public string $sha1,
        public ?AppleMakerNotes $apple = null,
        public ?SamsungMakerNotes $samsung = null,
        public ?bool $safe = null,
    ) {
        if ($this->vendor === '') {
            throw new ParseError('The vendor must not be empty.', 1858);
        }

        if ($this->length < 0) {
            throw new ParseError('The payload length must be zero or positive.', 1859);
        }

        if (preg_match('/^[0-9a-f]{40}$/', $this->sha1) !== 1) {
            throw new ParseError('The payload hash must be a 40 character lowercase hexadecimal string.', 1860);
        }
    }
}
