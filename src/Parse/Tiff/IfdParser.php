<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Tiff;

use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;

/**
 * Encapsulates canonical IFD entry ordering and duplicate checks.
 */
final readonly class IfdParser
{
    /**
     * Adds an entry while enforcing the no-duplicates constraint.
     *
     * TIFF 6.0 §2 requires writers to sort IFD entries in ascending tag order,
     * but this is a writer-side constraint for binary-search efficiency.  Many
     * real-world cameras (Jolla, Xiaomi Mi 9T) produce unsorted IFDs.  Entries
     * are stored in an associative array keyed by tag ID, so lookup is O(1)
     * regardless of input order.
     *
     * @param array<int, IfdEntry> $entries
     *
     * @param-out int              $lastTagId
     */
    public function addEntry(array &$entries, ?int &$lastTagId, IfdEntry $entry): void
    {
        if (isset($entries[$entry->tag])) {
            throw new ParseError('Duplicate tag ID ' . $entry->tag . ' in IFD per TIFF 6.0 §2.', 1357);
        }

        $lastTagId            = $entry->tag;
        $entries[$entry->tag] = $entry;
    }
}
