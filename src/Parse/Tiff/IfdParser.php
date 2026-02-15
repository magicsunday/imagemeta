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
     * Adds an entry while enforcing TIFF ordering and no-duplicates constraints.
     *
     * @param array<int, IfdEntry> $entries
     * @param int|null             $lastTagId
     *
     * @param-out int              $lastTagId
     *
     * @param IfdEntry $entry
     *
     * @return void
     */
    public function addEntry(array &$entries, ?int &$lastTagId, IfdEntry $entry): void
    {
        if (($lastTagId !== null) && ($entry->tag < $lastTagId)) {
            throw new ParseError('IFD entries must be sorted in ascending order by tag per TIFF 6.0 §2.', 1308);
        }

        if (isset($entries[$entry->tag])) {
            throw new ParseError('Duplicate tag ID ' . $entry->tag . ' in IFD per TIFF 6.0 §2.', 1357);
        }

        $lastTagId            = $entry->tag;
        $entries[$entry->tag] = $entry;
    }
}
