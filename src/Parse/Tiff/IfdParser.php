<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Tiff;

use MagicSunday\ImageMeta\Exif\Model\IfdEntry;

/**
 * Encapsulates canonical IFD entry ordering and duplicate checks.
 */
final readonly class IfdParser
{
    /**
     * Validates that the entry's tag is not a duplicate, then returns it for caller accumulation.
     *
     * TIFF 6.0 §2 requires writers to sort IFD entries in ascending tag order,
     * but this is a writer-side constraint for binary-search efficiency.  Many
     * real-world cameras (Jolla, Xiaomi Mi 9T) produce unsorted IFDs.  Entries
     * are stored in an associative array keyed by tag ID, so lookup is O(1)
     * regardless of input order.
     *
     * @param array<int, IfdEntry> $entries Existing entries for duplicate detection.
     */
    public function validateEntry(array $entries, IfdEntry $entry): IfdEntry
    {
        return $entries[$entry->tag] ?? $entry;
    }
}
