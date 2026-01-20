<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\MakerNotes\Samsung;

/**
 * Represents curated maker note data extracted from Samsung devices.
 *
 * Fields are based on documented Samsung maker note tags from ExifTool.
 */
final readonly class SamsungMakerNotes
{
    /**
     * @param string|null $makerNoteVersion Maker note version reported by Samsung devices.
     * @param string|null $deviceType       Device type string reported in the maker note.
     * @param int|null    $modelId          Numeric model identifier reported by Samsung devices.
     */
    public function __construct(
        public ?string $makerNoteVersion,
        public ?string $deviceType,
        public ?int $modelId,
    ) {
    }
}
