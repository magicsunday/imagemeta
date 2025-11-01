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

use function preg_match;

/**
 * Immutable value object that describes a normalised maker note payload.
 */
final readonly class MakerNotesRecord
{
    public function __construct(
        public string $vendor,
        public int $length,
        public string $sha1,
        public ?bool $isSafe = null,
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
}
